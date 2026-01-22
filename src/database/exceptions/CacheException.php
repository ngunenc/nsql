<?php

namespace nsql\database\exceptions;

use Exception;
use nsql\database\exceptions\error_codes;

/**
 * Cache Exception
 * 
 * Cache işlem hatalarını temsil eder
 */
class CacheException extends DatabaseException
{
    private ?string $cache_key = null;
    private ?string $cache_adapter = null;
    private ?string $operation = null; // 'get', 'set', 'delete', 'clear'

    public function __construct(
        string $message = '',
        ?string $cache_key = null,
        ?string $cache_adapter = null,
        ?string $operation = null,
        int $code = error_codes::CACHE_FAILED,
        ?Exception $previous = null
    ) {
        // Eğer mesaj boşsa, hata kodundan mesaj al
        if (empty($message)) {
            $message = error_codes::get_message($code);
        }
        
        parent::__construct($message, $code, $previous);
        $this->cache_key = $cache_key;
        $this->cache_adapter = $cache_adapter;
        $this->operation = $operation;
    }

    /**
     * Cache key'i döndürür
     */
    public function get_cache_key(): ?string
    {
        return $this->cache_key;
    }

    /**
     * Cache adapter adını döndürür
     */
    public function get_cache_adapter(): ?string
    {
        return $this->cache_adapter;
    }

    /**
     * Operation'ı döndürür
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
            'cache_key' => $this->cache_key,
            'cache_adapter' => $this->cache_adapter,
            'operation' => $this->operation,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
        ];
    }
}
