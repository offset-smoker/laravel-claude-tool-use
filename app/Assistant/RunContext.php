<?php

declare(strict_types=1);

namespace App\Assistant;

use Illuminate\Support\Str;

/**
 * State for one question: which record ids the tools have returned (the only
 * ids the answer may cite) and which releases were proposed.
 */
final class RunContext
{
    /** @var array<string, true> */
    private array $returnedRecordIds = [];

    /** @var list<string> */
    private array $pendingReleaseIds = [];

    public function __construct(
        public readonly string $runId,
    ) {}

    public static function start(): self
    {
        return new self((string) Str::uuid());
    }

    /** @param list<string> $recordIds */
    public function markReturned(array $recordIds): void
    {
        foreach ($recordIds as $recordId) {
            $this->returnedRecordIds[$recordId] = true;
        }
    }

    public function wasReturned(string $recordId): bool
    {
        return isset($this->returnedRecordIds[$recordId]);
    }

    public function addPendingRelease(string $pendingReleaseId): void
    {
        $this->pendingReleaseIds[] = $pendingReleaseId;
    }

    /** @return list<string> */
    public function pendingReleaseIds(): array
    {
        return $this->pendingReleaseIds;
    }
}
