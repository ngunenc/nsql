<?php

namespace nsql\database\traits;

use nsql\database\drivers\InsertId;
use nsql\database\exceptions\QueryException;
use nsql\database\security\SensitiveDataFilter;

/**
 * Ham SQL yazma işlemleri: insert, batch_insert, batch_update, statement, update, delete.
 */
trait WriteOperationsTrait
{
    private int|string $last_insert_id = 0;

    /** INSERT ... RETURNING ile alınan id; insert_id() bunu lastval()'e tercih eder */
    private int|string|null $returned_insert_id = null;

    /**
     * INSERT çalıştırır ve eklenen kaydın id'sini döndürür (hata: false).
     *
     * @param string|null $sequence PostgreSQL'de id'nin okunacağı sequence (ör. users_id_seq);
     *                              verilmezse lastval() kullanılır
     */
    public function insert(string $sql, array $params = [], ?string $sequence = null): int|string|false
    {
        $this->set_last_called_method();
        $this->last_results = [];
        $this->last_insert_id = 0;
        $this->returned_insert_id = null;

        $stmt = $this->execute_query($sql, $params);
        if ($stmt !== false && $this->pdo !== null) {
            $this->last_insert_id = $this->driver
                ? $this->driver->get_last_insert_id($this->pdo, $sequence)
                : InsertId::normalize($this->pdo->lastInsertId());

            $this->invalidate_cache_for_write($sql);

            return $this->last_insert_id;
        }

        return false;
    }

    /**
     * `INSERT ... RETURNING` sorgusunu çalıştırır ve dönen satırdaki `$column` değerini id
     * olarak döndürür. Kolon dönmezse sürücünün son id yöntemine düşer. Hata → QueryException.
     *
     * @internal QueryBuilder ve ORM PostgreSQL'de kullanır
     */
    public function insert_returning(string $sql, array $params, string $column): int|string
    {
        $this->set_last_called_method();
        $this->last_results = [];
        $this->returned_insert_id = null;

        $stmt = $this->execute_query($sql, $params);
        if ($stmt === false) {
            throw $this->make_query_exception($sql, $params);
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $this->invalidate_cache_for_write($sql);

        if (is_array($row) && array_key_exists($column, $row)) {
            $this->last_insert_id = InsertId::normalize($row[$column]);
            $this->returned_insert_id = $this->last_insert_id;
        } else {
            $this->last_insert_id = $this->driver && $this->pdo
                ? $this->driver->get_last_insert_id($this->pdo)
                : 0;
        }

        return $this->last_insert_id;
    }

    /**
     * Batch insert işlemi yapar (toplu ekleme)
     *
     * @param string $table Tablo adı
     * @param array $data İnsert edilecek veriler (her eleman bir satır)
     * @param bool $use_transaction Transaction kullanılsın mı? (varsayılan: true)
     * @return int Eklenen satır sayısı
     * @throws QueryException
     */
    public function batch_insert(string $table, array $data, bool $use_transaction = true): int
    {
        if (empty($data)) {
            return 0;
        }

        // İlk satırdan sütun adlarını al
        $first_row = reset($data);
        if (! is_array($first_row)) {
            throw new QueryException('Batch insert için her satır bir array olmalıdır.');
        }

        $columns = array_keys($first_row);
        $columns_str = implode(', ', array_map(fn($col) => $this->quote_identifier($col), $columns));

        // Placeholder'ları oluştur
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        // Tüm satırlar için placeholder'ları birleştir
        $all_placeholders = implode(', ', array_fill(0, count($data), $placeholders));

        // Tüm değerleri düzleştir
        $values = [];
        foreach ($data as $row) {
            foreach ($columns as $col) {
                $values[] = $row[$col] ?? null;
            }
        }

        $sql = "INSERT INTO {$this->quote_identifier($table)} ({$columns_str}) VALUES {$all_placeholders}";

        try {
            if ($use_transaction) {
                $this->begin();
            }

            $stmt = $this->execute_query($sql, $values);

            if ($stmt === false) {
                throw new QueryException('Batch insert başarısız oldu.', $sql, SensitiveDataFilter::mask_params($sql, $values));
            }

            $affected_rows = $stmt->rowCount();
            $this->invalidate_cache_for_write($sql);

            if ($use_transaction) {
                $this->commit();
            }

            return $affected_rows;
        } catch (\Exception $e) {
            if ($use_transaction) {
                $this->rollback();
            }

            if ($e instanceof QueryException) {
                throw $e;
            }

            throw new QueryException('Batch insert hatası: ' . $e->getMessage(), $sql, SensitiveDataFilter::mask_params($sql, $values), 0, $e);
        }
    }

    /**
     * Batch update işlemi yapar (toplu güncelleme)
     *
     * @param string $table Tablo adı
     * @param array $data Güncellenecek veriler (her eleman bir satır, 'id' veya belirtilen key ile eşleşir)
     * @param string $key_column Eşleştirme için kullanılacak sütun (varsayılan: 'id')
     * @param bool $use_transaction Transaction kullanılsın mı? (varsayılan: true)
     * @return int Güncellenen satır sayısı
     * @throws QueryException
     */
    public function batch_update(string $table, array $data, string $key_column = 'id', bool $use_transaction = true): int
    {
        if (empty($data)) {
            return 0;
        }

        $total_affected = 0;

        try {
            if ($use_transaction) {
                $this->begin();
            }

            foreach ($data as $row) {
                if (! is_array($row) || ! isset($row[$key_column])) {
                    continue;
                }

                $key_value = $row[$key_column];
                unset($row[$key_column]);

                if (empty($row)) {
                    continue;
                }

                // SET clause oluştur
                $set_parts = [];
                $params = [];
                foreach ($row as $column => $value) {
                    $set_parts[] = $this->quote_identifier($column) . ' = ?';
                    $params[] = $value;
                }

                $set_clause = implode(', ', $set_parts);
                $params[] = $key_value;

                $sql = "UPDATE {$this->quote_identifier($table)} SET {$set_clause} WHERE {$this->quote_identifier($key_column)} = ?";

                $stmt = $this->execute_query($sql, $params);
                if ($stmt === false) {
                    throw $this->make_query_exception($sql, $params);
                }
                $total_affected += $stmt->rowCount();
            }

            $this->invalidate_cache_for_write("UPDATE {$this->quote_identifier($table)}");

            if ($use_transaction) {
                $this->commit();
            }

            return $total_affected;
        } catch (\Exception $e) {
            if ($use_transaction) {
                $this->rollback();
            }

            if ($e instanceof QueryException) {
                throw $e;
            }

            throw new QueryException('Batch update hatası: ' . $e->getMessage(), '', [], 0, $e);
        }
    }

    /**
     * Yazma sorgusu (INSERT/UPDATE/DELETE/DDL) çalıştırır ve etkilenen satır sayısını döndürür.
     * THROW_ON_ERROR ayarından bağımsız olarak hata durumunda QueryException fırlatır.
     */
    public function statement(string $sql, array $params = []): int
    {
        $this->set_last_called_method();
        $this->last_results = [];
        $this->returned_insert_id = null;

        $stmt = $this->execute_query($sql, $params);
        if ($stmt === false) {
            throw $this->make_query_exception($sql, $params);
        }

        $this->invalidate_cache_for_write($sql);

        return $stmt->rowCount();
    }

    /**
     * UPDATE çalıştırır.
     *
     * @return int|bool THROW_ON_ERROR=true: etkilenen satır sayısı (hata → QueryException).
     *                  THROW_ON_ERROR=false: başarı için true, hata için false.
     */
    public function update(string $sql, array $params = []): int|bool
    {
        $this->set_last_called_method();

        return $this->execute_write($sql, $params);
    }

    /**
     * DELETE çalıştırır. Dönüş değeri update() ile aynı sözleşmeye sahiptir.
     */
    public function delete(string $sql, array $params = []): int|bool
    {
        $this->set_last_called_method();

        return $this->execute_write($sql, $params);
    }

    private function execute_write(string $sql, array $params): int|bool
    {
        $this->last_results = [];

        $stmt = $this->execute_query($sql, $params);
        if ($stmt === false) {
            return false;
        }

        $this->invalidate_cache_for_write($sql);

        return $this->throw_on_error() ? $stmt->rowCount() : true;
    }

    /**
     * Son eklenen kaydın ID değerini döndürür.
     *
     * @param string|null $sequence PostgreSQL sequence adı (bkz. insert())
     * @return int|string Son eklenen kaydın ID değeri (int aralığı dışındaki / UUID id'ler string)
     */
    public function insert_id(?string $sequence = null): int|string
    {
        if ($this->returned_insert_id !== null && $sequence === null) {
            return $this->returned_insert_id;
        }
        if ($this->driver && $this->pdo) {
            return $this->driver->get_last_insert_id($this->pdo, $sequence);
        }

        return $this->last_insert_id;
    }
}
