<?php

declare(strict_types=1);

namespace App\Assistant\Tools;

use App\Assistant\RunContext;
use App\Assistant\UntrustedText;
use App\Enums\RedactionReason;
use App\Enums\ReleaseStatus;
use App\Models\PendingRelease;
use App\Models\Record;
use Illuminate\Database\UniqueConstraintViolationException;
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

        $recordId = $input->string('record_id')->toString();
        $record = Record::query()->find($recordId);

        if (! $record instanceof Record) {
            throw new ToolInputInvalid("No record has the id {$recordId}.");
        }

        /** @var list<array{text: string, reason: string}> $validatedRedactions  shape checked by the rules above */
        $validatedRedactions = $input->array('redactions');

        // The model read the record through UntrustedText: it may quote "&lt;"
        // where the record has "<", and it never saw carriage returns or
        // invisible characters. Each quote is traced back to the passages of
        // the original body it stands for, and those are what get redacted.
        $redactions = [];

        foreach ($validatedRedactions as $index => $redaction) {
            $passages = UntrustedText::originalPassages($record->body, htmlspecialchars_decode($redaction['text'], ENT_NOQUOTES));

            if ($passages === []) {
                throw new ToolInputInvalid("Redaction {$index} does not quote the text of {$record->id} exactly. Copy the passage from get_record and try again.");
            }

            foreach ($passages as $passage) {
                $redactions[] = ['text' => $passage, 'reason' => $redaction['reason']];
            }
        }

        $existing = $record->pendingReleases()->where('status', ReleaseStatus::Pending)->first();

        if ($existing instanceof PendingRelease) {
            throw new ToolInputInvalid("{$record->id} already has a proposal awaiting review ({$existing->id}). Do not propose it again.");
        }

        $rationale = $input->string('rationale')->toString();

        try {
            $pending = PendingRelease::query()->create([
                'record_id' => $record->id,
                'redactions' => $redactions,
                'rationale' => $rationale,
                'record_body_sha256' => $record->bodyHash(),
                'requested_in_run' => $context->runId,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another process saved a proposal for this record after the check above.
            throw new ToolInputInvalid("{$record->id} already has a proposal awaiting review. Do not propose it again.");
        }

        $context->addPendingRelease($pending->id);

        return new ToolResult(sprintf(
            'Proposal %s saved for %s with %d redaction(s). Nothing has been released: a human reviewer must approve it first. Tell the user it is awaiting review.',
            $pending->id,
            $record->id,
            count($redactions),
        ));
    }
}
