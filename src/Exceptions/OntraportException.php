<?php

namespace Linups\OntraportLaravel\Exceptions;

/**
 * Thrown when an Ontraport API call fails.
 *
 * Extends \Exception, so existing `catch (\Exception $e)` blocks keep working. The message
 * keeps the pre-v1.1 `<pre>print_r(...)</pre>` format; card data in it is redacted.
 */
class OntraportException extends \Exception
{
    /**
     * @param array<string, mixed> $context Redacted details of the failed call.
     */
    public function __construct(
        string $message,
        private readonly array $context = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Name of the package method that failed, e.g. "addUser".
     */
    public function getMethodName(): ?string
    {
        return $this->context['name'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
