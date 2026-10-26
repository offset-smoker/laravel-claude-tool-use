<?php

declare(strict_types=1);

namespace App\Assistant\Tools;

use App\Assistant\RunContext;
use App\Enums\RedactionReason;
use App\Enums\ReleaseStatus;
use App\Models\PendingRelease;
use App\Models\Record;
use Illuminate\Validation\Rule;

/**
 * Proposes a release. It only ever writes a pending row: there is no tool
 * that approves, and the loop never changes a release's status.
 */
final class ProposeRedaction implements Tool
{
    use ValidatesInput;

    public const int MAX_REDACTIONS = 20;

    public function name(): string
    {
        return 'propose_redaction';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Propose releasing a record with redactions. Call this only when the user asks for a record to be released, '
                .'after reading it with get_record. Quote each passage to redact exactly as it appears in the record text. '
                .'This saves a proposal for a human reviewer and releases nothing.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'record_id' => ['type' => 'string', 'description' => 'The record to release, for example REC-0001.'],
                    'redactions' => [
                        'type' => 'array',
                        'description' => 'Passages to redact. Use an empty list only if nothing in the record needs redacting.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'text' => ['type' => 'string', 'description' => 'The exact passage, copied from the record text.'],
                                'reason' => ['type' => 'string', 'enum' => array_column(RedactionReason::cases(), 'value')],
                            ],
                            'required' => ['text', 'reason'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'rationale' => ['type' => 'string', 'description' => 'One or two sentences for the reviewer on why these redactions.'],
                ],
                'required' => ['record_id', 'redactions', 'rationale'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ];
    }

    public function run(array $input, RunContext $context): ToolResult
    {
        $input = $this->validated($input, [
            'record_id' => ['required', 'string', 'regex:'.Record::ID_PATTERN],
            'redactions' => ['present', 'array', 'list', 'max:'.self::MAX_REDACTIONS],
            'redactions.*.text' => ['required', 'string', 'max:500'],
            'redactions.*.reason' => ['required', 'string', Rule::enum(RedactionReason::class)],
            'rationale' => ['required', 'string', 'max:1000'],
        ]);

        $record = Record::query()->find($input['record_id']);

        if (! $record instanceof Record) {
            throw new ToolInputInvalid("No record has the id {$input['record_id']}.");
        }

        // The model read the record through UntrustedText, so it may quote "&lt;" where the record has "<".
        /** @var list<array{text: string, reason: string}> $redactions */
        $redactions = array_map(
            static fn (array $redaction): array => [
                'text' => htmlspecialchars_decode($redaction['text'], ENT_NOQUOTES),
                'reason' => $redaction['reason'],
            ],
            $input['redactions'],
        );

        foreach ($redactions as $index => $redaction) {
            if (! str_contains($record->body, $redaction['text'])) {
                throw new ToolInputInvalid("Redaction {$index} does not quote the text of {$record->id} exactly. Copy the passage from get_record and try again.");
            }
        }

        $existing = $record->pendingReleases()->where('status', ReleaseStatus::Pending)->first();

        if ($existing instanceof PendingRelease) {
            throw new ToolInputInvalid("{$record->id} already has a proposal awaiting review ({$existing->id}). Do not propose it again.");
        }

        /** @var string $rationale */
        $rationale = $input['rationale'];

        $pending = PendingRelease::query()->create([
            'record_id' => $record->id,
            'redactions' => $redactions,
            'rationale' => $rationale,
            'record_body_sha256' => $record->bodyHash(),
            'requested_in_run' => $context->runId,
        ]);

        $context->addPendingRelease($pending->id);

        return new ToolResult(sprintf(
            'Proposal %s saved for %s with %d redaction(s). Nothing has been released: a human reviewer must approve it first. Tell the user it is awaiting review.',
            $pending->id,
            $record->id,
            count($redactions),
        ));
    }
}
