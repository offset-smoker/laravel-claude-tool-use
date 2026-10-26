<?php

declare(strict_types=1);

namespace App\Assistant\Tools;

final readonly class ToolResult
{
    /** @param list<string> $returnedRecordIds */
    public function __construct(
        public string $content,
        public bool $isError = false,
        public array $returnedRecordIds = [],
    ) {}

    public static function error(string $message): self
    {
        return new self($message, isError: true);
    }
}
