<?php

namespace App\Domain\Import;

/** State shared by the rows of one run: source label, options, references seen in the file. */
class ImportContext
{
    /** @var array<string, true> */
    public array $seen = [];

    public function __construct(
        public readonly string $source,
        public readonly array $options = [],
        public readonly ?int $userId = null,
    ) {}

    /** External identifiers are stored with their source, so two systems never collide. */
    public function ref(string $value): string
    {
        return mb_substr($this->source.':'.trim($value), 0, 120);
    }
}
