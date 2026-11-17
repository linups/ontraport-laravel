<?php

namespace Linups\OntraportLaravel\Tests\Fakes;

use Psr\Log\AbstractLogger;

class RecordingLogger extends AbstractLogger
{
    /** @var array<int, array{level: mixed, message: string, context: array}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
