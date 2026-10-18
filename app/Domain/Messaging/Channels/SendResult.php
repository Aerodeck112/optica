<?php

namespace App\Domain\Messaging\Channels;

/** What the provider answered: "accepted" means taken over, not delivered (COM-02). */
final class SendResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $providerId = null,
        public readonly ?int $segments = null,
        public readonly ?int $reportedCostCents = null,
    ) {}
}
