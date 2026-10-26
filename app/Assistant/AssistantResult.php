<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Enums\AnswerStatus;

final readonly class AssistantResult
{
    /**
     * @param  list<string>  $citedRecordIds
     * @param  list<string>  $citationProblems
     * @param  list<string>  $pendingReleaseIds
     */
    public function __construct(
        public string $runId,
        public AnswerStatus $status,
        public ?string $answer,
        public array $citedRecordIds,
        public array $citationProblems,
        public array $pendingReleaseIds,
        public int $iterations,
        public TokenUsage $usage,
        public ?string $detail = null,
    ) {}
}
