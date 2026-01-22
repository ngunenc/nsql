<?php

namespace nsql\database\exceptions;

use Exception;
use nsql\database\exceptions\error_codes;

/**
 * Migration Exception
 * 
 * Migration işlem hatalarını temsil eder
 */
class MigrationException extends DatabaseException
{
    private ?string $migration_name = null;
    private ?string $migration_batch = null;
    private ?string $operation = null; // 'up' veya 'down'

    public function __construct(
        string $message = '',
        ?string $migration_name = null,
        ?string $migration_batch = null,
        ?string $operation = null,
        int $code = error_codes::MIGRATION_FAILED,
        ?Exception $previous = null
    ) {
        // Eğer mesaj boşsa, hata kodundan mesaj al
        if (empty($message)) {
            $message = error_codes::get_message($code);
        }
        
        parent::__construct($message, $code, $previous);
        $this->migration_name = $migration_name;
        $this->migration_batch = $migration_batch;
        $this->operation = $operation;
    }

    /**
     * Migration adını döndürür
     */
    public function get_migration_name(): ?string
    {
        return $this->migration_name;
    }

    /**
     * Migration batch'ini döndürür
     */
    public function get_migration_batch(): ?string
    {
        return $this->migration_batch;
    }

    /**
     * Operation'ı döndürür ('up' veya 'down')
     */
    public function get_operation(): ?string
    {
        return $this->operation;
    }

    /**
     * Exception detaylarını döndürür
     */
    public function get_details(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'migration_name' => $this->migration_name,
            'migration_batch' => $this->migration_batch,
            'operation' => $this->operation,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
        ];
    }
}
