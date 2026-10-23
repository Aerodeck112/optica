<?php

namespace App\Modules\Notifications\Services;

final class OutgoingMessage
{
    public function __construct(
        public readonly string $channel,
        public readonly string $to,
        public readonly ?string $subject,
        public readonly string $text,
        public readonly ?string $html,
        public readonly string $uuid,
        public readonly string $category,
        public readonly array $headers = [],
    ) {}
}
