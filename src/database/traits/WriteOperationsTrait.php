<?php

namespace nsql\database\traits;

use nsql\database\exceptions\QueryException;

/**
 * Ham SQL yazma işlemleri: insert, batch_insert, batch_update, statement, update, delete.
 */
trait WriteOperationsTrait
{
    private int $last_insert_id = 0;

    public function insert(string $sql, array $params = []): int|false
    {
        $this->set_last_called_method();
        $this->last_results = [];
        $this->last_insert_id = 0;

        $stmt = $this->execute_query($sql, $params);
        if ($stmt !== false && $this->pdo !== null) {
            // Driver'a göre last insert ID al
            if ($this->driver) {
                $this->last_insert_id = $this->driver->get_last_insert_id($this->pdo);
            } else {
                $this->last_insert_id = (int)$this->pdo->lastInsertId();
            }

            $this->invalidate_cache_for_write($sql);

            return $this->last_insert_id;
        }

        return false;
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
                if ($use_transaction) {
                    $this->rollback();
                }
                throw new QueryException('Batch insert başarısız oldu.', $sql, $values);
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

            throw new QueryException('Batch insert hatası: ' . $e->getMessage(), $sql, $values, 0, $e);
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
     * @return int Son eklenen kaydın ID değeri.
     */
    public function insert_id(): int|string
    {
        if ($this->driver && $this->pdo) {
            // Driver'a göre last insert ID al
            return $this->driver->get_last_insert_id($this->pdo);
        }
        return $this->last_insert_id;
    }
}
