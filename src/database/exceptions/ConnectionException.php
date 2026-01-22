<?php

namespace nsql\database\exceptions;

use Exception;
use nsql\database\exceptions\error_codes;

/**
 * Connection Exception
 * 
 * Veritabanı bağlantı hatalarını temsil eder
 */
class ConnectionException extends DatabaseException
{
    private ?string $dsn = null;
    private ?string $host = null;
    private ?string $database = null;

    public function __construct(
        string $message = '',
        ?string $dsn = null,
        ?string $host = null,
        ?string $database = null,
        int $code = error_codes::CONNECTION_FAILED,
        ?Exception $previous = null
    ) {
        // Eğer mesaj boşsa, hata kodundan mesaj al
        if (empty($message)) {
            $message = error_codes::get_message($code);
        }
        
        parent::__construct($message, $code, $previous);
        $this->dsn = $dsn;
        $this->host = $host;
        $this->database = $database;
    }

    /**
     * DSN'i döndürür
     */
    public function get_dsn(): ?string
    {
        return $this->dsn;
    }

    /**
     * Host'u döndürür
     */
    public function get_host(): ?string
    {
        return $this->host;
    }

    /**
     * Database adını döndürür
     */
    public function get_database(): ?string
    {
        return $this->database;
    }

    /**
     * Exception detaylarını döndürür
     */
    public function get_details(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'dsn' => $this->dsn,
            'host' => $this->host,
            'database' => $this->database,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
        ];
    }
}
