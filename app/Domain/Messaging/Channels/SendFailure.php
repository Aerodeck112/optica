<?php

namespace App\Domain\Messaging\Channels;

use RuntimeException;

/**
 * transient: safe to retry later (rate limit, provider down before accepting).
 * permanent: retrying cannot help (invalid number, rejected template).
 * uncertain: the request may have reached the provider (timeout) – never retried automatically (COM-03).
 */
final class SendFailure extends RuntimeException
{
    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct(mb_substr($message, 0, 300));
    }

    public static function transient(string $message): self
    {
        return new self('transient', $message);
    }

    public static function permanent(string $message): self
    {
        return new self('permanent', $message);
    }

    public static function uncertain(string $message): self
    {
        return new self('uncertain', $message);
    }
}
