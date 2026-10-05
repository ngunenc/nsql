<?php

namespace Tests\Support;

use Psr\Log\AbstractLogger;

final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<array{level: string, message: string, context: array<mixed>}>
     */
    public function matching(string $level, string $message): array
    {
        return array_values(array_filter(
            $this->records,
            fn (array $r) => $r['level'] === $level && str_contains($r['message'], $message)
        ));
    }
}
