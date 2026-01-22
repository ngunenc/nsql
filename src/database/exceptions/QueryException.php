<?php

namespace nsql\database\exceptions;

use Exception;
use nsql\database\exceptions\error_codes;

/**
 * Query Exception
 * 
 * SQL sorgu hatalarını temsil eder
 */
class QueryException extends DatabaseException
{
    private ?string $sql = null;
    private array $params = [];

    public function __construct(
        string $message = '',
        ?string $sql = null,
        array $params = [],
        int $code = error_codes::QUERY_FAILED,
        ?Exception $previous = null
    ) {
        // Eğer mesaj boşsa, hata kodundan mesaj al
        if (empty($message)) {
            $message = error_codes::get_message($code);
        }
        
        parent::__construct($message, $code, $previous);
        $this->sql = $sql;
        $this->params = $params;
    }

    /**
     * SQL sorgusunu döndürür
     */
    public function get_sql(): ?string
    {
        return $this->sql;
    }

    /**
     * SQL parametrelerini döndürür
     */
    public function get_params(): array
    {
        return $this->params;
    }

    /**
     * Exception detaylarını döndürür
     */
    public function get_details(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'sql' => $this->sql,
            'params' => $this->params,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
        ];
    }
}
