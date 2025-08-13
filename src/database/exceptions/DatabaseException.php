<?php

namespace nsql\database\exceptions;

use RuntimeException;
use Throwable;

class DatabaseException extends RuntimeException {
    private array $context = [];
    private ?string $query = null;
    private array $params = [];
    private float $execution_time = 0.0;
    
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        array $context = [],
        ?string $query = null,
        array $params = [],
        float $execution_time = 0.0
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
        $this->query = $query;
        $this->params = $params;
        $this->execution_time = $execution_time;
    }
    
    public function getContext(): array { return $this->context; }
    public function getQuery(): ?string { return $this->query; }
    public function getParams(): array { return $this->params; }
    public function getExecutionTime(): float { return $this->execution_time; }
}
