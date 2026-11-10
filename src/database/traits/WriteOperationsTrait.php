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
    /** batch_update(): tek CASE WHEN ifadesindeki en fazla satır (ifade boyutunu sınırlar) */
    private const BATCH_UPDATE_MAX_ROWS = 500;

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
        $this->last_rows_affected = 0;

        $stmt = $this->execute_query($sql, $params);
        if ($stmt !== false && $this->pdo !== null) {
            $this->last_rows_affected = $stmt->rowCount();
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

        $this->last_rows_affected = 0;
        $stmt = $this->execute_query($sql, $params);
        if ($stmt === false) {
            throw $this->make_query_exception($sql, $params);
        }
        $this->last_rows_affected = $stmt->rowCount();
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
        $this->last_rows_affected = 0;
        if (empty($data)) {
            return 0;
        }

        // İlk satırdan sütun adlarını al
        $first_row = reset($data);
        if (! is_array($first_row)) {
            throw new QueryException('Batch insert için her satır bir array olmalıdır.');
        }

        $columns = array_keys($first_row);
        if ($columns === []) {
            // Kolonsuz satır: parça boyutu hesabı sıfıra bölerdi
            throw new QueryException('Batch insert: satırlar en az bir kolon içermelidir.');
        }
        foreach ($data as $index => $row) {
            if (! is_array($row)) {
                throw new QueryException('Batch insert için her satır bir array olmalıdır.');
            }
            if (count($row) !== count($columns) || array_diff_key($first_row, $row) !== []) {
                throw new QueryException("Batch insert: {$index}. satırın kolonları ilk satırla aynı olmalıdır.");
            }
        }

        $columns_str = implode(', ', array_map(fn($col) => $this->quote_identifier($col), $columns));
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $insert_prefix = "INSERT INTO {$this->quote_identifier($table)} ({$columns_str}) VALUES ";
        $per_chunk = max(1, intdiv($this->max_bound_params(), count($columns)));

        $sql = $insert_prefix;
        $values = [];

        try {
            if ($use_transaction) {
                $this->begin();
            }

            $affected_rows = 0;
            foreach (array_chunk($data, $per_chunk) as $chunk) {
                $sql = $insert_prefix . implode(', ', array_fill(0, count($chunk), $placeholders));
                $values = [];
                foreach ($chunk as $row) {
                    foreach ($columns as $col) {
                        $values[] = $row[$col];
                    }
                }

                $stmt = $this->execute_query($sql, $values);

                if ($stmt === false) {
                    throw new QueryException('Batch insert başarısız oldu.', $sql, SensitiveDataFilter::mask_params($sql, $values));
                }

                $affected_rows += $stmt->rowCount();
            }
            $this->invalidate_cache_for_write($sql);

            if ($use_transaction) {
                $this->commit();
            }

            $this->last_rows_affected = $affected_rows;

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
     * @throws QueryException Satırlardan biri dizi değilse veya $key_column değeri yoksa (hiçbir satır yazılmadan)
     */
    public function batch_update(string $table, array $data, string $key_column = 'id', bool $use_transaction = true): int
    {
        if (empty($data)) {
            return 0;
        }
        // Anahtarsız satır önceden sessizce atlanıyordu; güncellenmeyen satır fark edilmiyordu
        foreach ($data as $index => $row) {
            if (! is_array($row) || ! isset($row[$key_column])) {
                throw new QueryException("Batch update: {$index}. satırda '{$key_column}' anahtarı yok.");
            }
        }

        $total_affected = 0;
        $this->last_rows_affected = 0;

        try {
            if ($use_transaction) {
                $this->begin();
            }

            // Aynı kolonları güncelleyen ardışık satırlar tek ifadede (CASE WHEN) yazılır. Kolon kümesi
            // değişince, anahtar yinelenince (son yazan kazanır) veya parametre sınırına gelince grup
            // kapatılır: sonuç, satırların sırayla tek tek güncellenmesiyle aynıdır.
            $columns = [];
            /** @var array<string, array{key: mixed, values: list<mixed>}> $group */
            $group = [];
            foreach ($data as $row) {
                $key_value = $row[$key_column];
                unset($row[$key_column]);

                if (empty($row)) {
                    continue;
                }

                $row_columns = array_map('strval', array_keys($row));
                $group_key = (string) $key_value;
                $limit = min(self::BATCH_UPDATE_MAX_ROWS, max(1, intdiv($this->max_bound_params(), 2 * count($row_columns) + 1)));
                if ($group !== [] && ($row_columns !== $columns || isset($group[$group_key]) || count($group) >= $limit)) {
                    $total_affected += $this->run_batch_update($table, $key_column, $columns, $group);
                    $group = [];
                }

                $columns = $row_columns;
                $group[$group_key] = ['key' => $key_value, 'values' => array_values($row)];
            }
            if ($group !== []) {
                $total_affected += $this->run_batch_update($table, $key_column, $columns, $group);
            }

            $this->invalidate_cache_for_write("UPDATE {$this->quote_identifier($table)}");

            if ($use_transaction) {
                $this->commit();
            }

            $this->last_rows_affected = $total_affected;

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
     * Aynı kolonları güncelleyen satırları tek ifadede yazar:
     * `UPDATE t SET c = CASE k WHEN ? THEN ? ... ELSE c END WHERE k IN (?, ...)`.
     * `ELSE c`: WHERE zaten yalnızca listelenen anahtarları seçer; ELSE dalı PostgreSQL'in CASE sonucunun
     * tipini kolondan çıkarması içindir (tüm dallar parametreyken tip `text` sayılırdı).
     *
     * @param list<string> $columns
     * @param array<string, array{key: mixed, values: list<mixed>}> $rows
     * @return int Etkilenen satır sayısı
     * @throws QueryException
     */
    private function run_batch_update(string $table, string $key_column, array $columns, array $rows): int
    {
        $key = $this->quote_identifier($key_column);
        $params = [];
        $set_parts = [];
        foreach ($columns as $index => $column) {
            $quoted = $this->quote_identifier($column);
            $set_parts[] = "{$quoted} = CASE {$key}" . str_repeat(' WHEN ? THEN ?', count($rows)) . " ELSE {$quoted} END";
            foreach ($rows as $row) {
                $params[] = $row['key'];
                $params[] = $row['values'][$index];
            }
        }
        foreach ($rows as $row) {
            $params[] = $row['key'];
        }

        $sql = "UPDATE {$this->quote_identifier($table)} SET " . implode(', ', $set_parts)
            . " WHERE {$key} IN (" . implode(', ', array_fill(0, count($rows), '?')) . ')';

        $stmt = $this->execute_query($sql, $params);
        if ($stmt === false) {
            throw $this->make_query_exception($sql, $params);
        }

        return $stmt->rowCount();
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
        $this->last_rows_affected = 0;

        $stmt = $this->execute_query($sql, $params);
        if ($stmt === false) {
            throw $this->make_query_exception($sql, $params);
        }

        $this->invalidate_cache_for_write($sql);

        $this->last_rows_affected = $stmt->rowCount();

        return $this->last_rows_affected;
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
        $this->last_rows_affected = 0;

        $stmt = $this->execute_query($sql, $params);
        if ($stmt === false) {
            return false;
        }

        $this->invalidate_cache_for_write($sql);

        $this->last_rows_affected = $stmt->rowCount();

        return $this->throw_on_error() ? $this->last_rows_affected : true;
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

    /**
     * Son yazma sorgusunun (INSERT/UPDATE/DELETE/statement) etkilenen satır sayısını döndürür.
     * Başarısız yazmada 0; SELECT sorguları bu değeri değiştirmez.
     */
    public function rows_affected(): int
    {
        return $this->last_rows_affected;
    }

    /**
     * Birden çok ifadeye bölünen yazmaların (QueryBuilder::insert_many / upsert) toplamını bildirir.
     *
     * @internal QueryBuilder kullanır
     */
    public function set_rows_affected(int $count): void
    {
        $this->last_rows_affected = max(0, $count);
    }
}
