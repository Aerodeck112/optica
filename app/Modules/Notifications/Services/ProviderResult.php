<?php

namespace App\Modules\Notifications\Services;

final class ProviderResult
{
    public function __construct(
        public readonly bool $accepted,
        public readonly ?string $messageId = null,
        public readonly ?string $error = null,
        public readonly bool $retryable = false,
        public readonly ?int $costMicro = null,
        public readonly ?int $segments = null,
        public readonly bool $sandboxed = false,
    ) {}

    public static function accepted(?string $id, ?int $costMicro = null, ?int $segments = null): self
    {
        return new self(true, $id, null, false, $costMicro, $segments);
    }

    public static function failed(string $error, bool $retryable): self
    {
        return new self(false, null, $error, $retryable);
    }

    public static function sandboxed(): self
    {
        return new self(true, null, null, false, null, null, true);
    }
}
