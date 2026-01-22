<?php

namespace nsql\database\traits;

use Exception;
use PDOException;
use Throwable;
use nsql\database\exceptions\{
    QueryException,
    ConnectionException,
    DatabaseException
};

/**
 * Error Handling Trait
 * 
 * Özellikler:
 * - Try-catch wrapper
 * - Error recovery mekanizması
 * - Retry logic
 * - Error context tracking
 */
trait error_handling_trait
{
    /**
     * Güvenli şekilde işlem yapar (try-catch wrapper)
     * 
     * @param callable $operation Yapılacak işlem
     * @param mixed $default_value Hata durumunda döndürülecek varsayılan değer
     * @param bool $throw_exception Exception fırlatılsın mı?
     * @return mixed
     */
    protected function safe_execute_operation(
        callable $operation,
        mixed $default_value = null,
        bool $throw_exception = false
    ): mixed {
        try {
            return $operation();
        } catch (PDOException $e) {
            return $this->handle_pdo_exception($e, $default_value, $throw_exception);
        } catch (DatabaseException $e) {
            return $this->handle_database_exception($e, $default_value, $throw_exception);
        } catch (Exception $e) {
            return $this->handle_generic_exception($e, $default_value, $throw_exception);
        } catch (Throwable $e) {
            return $this->handle_throwable($e, $default_value, $throw_exception);
        }
    }

    /**
     * Retry logic ile işlem yapar
     * 
     * @param callable $operation Yapılacak işlem
     * @param int $max_retries Maksimum deneme sayısı
     * @param int $delay_ms Retry arası bekleme (milisaniye)
     * @return mixed
     * @throws Throwable
     */
    protected function execute_with_retry(
        callable $operation,
        int $max_retries = 3,
        int $delay_ms = 100
    ): mixed {
        $last_exception = null;
        
        for ($attempt = 0; $attempt <= $max_retries; $attempt++) {
            try {
                return $operation();
            } catch (PDOException $e) {
                $last_exception = $e;
                
                // Connection hatası ise retry yap
                if ($this->is_recoverable_error($e) && $attempt < $max_retries) {
                    usleep($delay_ms * 1000); // Mikrosaniye cinsinden bekleme
                    continue;
                }
                
                // Retry edilemez hata veya maksimum deneme aşıldı
                throw $this->convert_to_database_exception($e);
            } catch (Throwable $e) {
                // PDOException dışındaki hatalar için retry yapma
                throw $e;
            }
        }
        
        // Tüm denemeler başarısız
        throw $this->convert_to_database_exception($last_exception);
    }

    /**
     * Hatanın recoverable (düzeltilebilir) olup olmadığını kontrol eder
     */
    protected function is_recoverable_error(PDOException $e): bool
    {
        $error_code = $e->getCode();
        
        // MySQL error codes
        $recoverable_codes = [
            2002, // Connection timeout
            2003, // Can't connect to MySQL server
            2006, // MySQL server has gone away
            2013, // Lost connection to MySQL server
            1040, // Too many connections
            1205, // Lock wait timeout exceeded
        ];
        
        return in_array($error_code, $recoverable_codes, true);
    }

    /**
     * PDOException'ı handle eder
     */
    protected function handle_pdo_exception(
        PDOException $e,
        mixed $default_value,
        bool $throw_exception
    ): mixed {
        if (method_exists($this, 'log_error')) {
            $this->log_error(
                "PDO Exception: " . $e->getMessage(),
                [
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
                \nsql\database\logging\logger::ERROR
            );
        }
        
        if ($throw_exception) {
            throw $this->convert_to_database_exception($e);
        }
        
        return $default_value;
    }

    /**
     * DatabaseException'ı handle eder
     */
    protected function handle_database_exception(
        DatabaseException $e,
        mixed $default_value,
        bool $throw_exception
    ): mixed {
        if (method_exists($this, 'log_error')) {
            $this->log_error(
                "Database Exception: " . $e->getMessage(),
                $e->get_details(),
                \nsql\database\logging\logger::ERROR
            );
        }
        
        if ($throw_exception) {
            throw $e;
        }
        
        return $default_value;
    }

    /**
     * Generic Exception'ı handle eder
     */
    protected function handle_generic_exception(
        Exception $e,
        mixed $default_value,
        bool $throw_exception
    ): mixed {
        if (method_exists($this, 'log_error')) {
            $this->log_error(
                "Exception: " . $e->getMessage(),
                [
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
                \nsql\database\logging\logger::ERROR
            );
        }
        
        if ($throw_exception) {
            throw $e;
        }
        
        return $default_value;
    }

    /**
     * Throwable'ı handle eder
     */
    protected function handle_throwable(
        Throwable $e,
        mixed $default_value,
        bool $throw_exception
    ): mixed {
        if (method_exists($this, 'log_error')) {
            $this->log_error(
                "Throwable: " . $e->getMessage(),
                [
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
                \nsql\database\logging\logger::CRITICAL
            );
        }
        
        if ($throw_exception) {
            throw $e;
        }
        
        return $default_value;
    }

    /**
     * PDOException'ı DatabaseException'a dönüştürür
     */
    protected function convert_to_database_exception(PDOException $e): DatabaseException
    {
        $error_info = $e->errorInfo ?? [];
        $sql_state = $error_info[0] ?? '';
        $error_code = $error_info[1] ?? $e->getCode();
        $error_message = $error_info[2] ?? $e->getMessage();
        
        // Connection hatası mı?
        if (in_array($error_code, [2002, 2003, 2006, 2013, 1040], true)) {
            return new \nsql\database\exceptions\ConnectionException(
                $error_message,
                $error_code,
                $e
            );
        }
        
        // Query hatası
        return new \nsql\database\exceptions\QueryException(
            $error_message,
            '',
            [],
            $error_code,
            $e
        );
    }

    /**
     * Error context'i toplar
     */
    protected function collect_error_context(Throwable $e): array
    {
        return [
            'exception_type' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'previous' => $e->getPrevious() ? [
                'type' => get_class($e->getPrevious()),
                'message' => $e->getPrevious()->getMessage(),
            ] : null,
        ];
    }
}
