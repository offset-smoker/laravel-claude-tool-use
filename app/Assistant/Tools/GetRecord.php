<?php

declare(strict_types=1);

namespace App\Assistant\Tools;

use App\Assistant\RunContext;
use App\Assistant\UntrustedText;
use App\Models\Record;

final class GetRecord implements Tool
{
    use ValidatesInput;

    public function name(): string
    {
        return 'get_record';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Fetch one record\'s full text by id. Call this before answering from a record or proposing a redaction of it. '
                .'Ids look like REC-0001; get them from search_records.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'record_id' => ['type' => 'string', 'description' => 'The record id, for example REC-0001.'],
                ],
                'required' => ['record_id'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ];
    }

    public function run(array $input, RunContext $context): ToolResult
    {
        $input = $this->validated($input, [
            'record_id' => ['required', 'string', 'regex:'.Record::ID_PATTERN],
        ]);

        $recordId = $input->string('record_id')->toString();
        $record = Record::query()->find($recordId);

        if (! $record instanceof Record) {
            throw new ToolInputInvalid("No record has the id {$recordId}. Use search_records to find valid ids.");
        }

        $content = implode("\n", [
            "record_id: {$record->id}",
            UntrustedText::title($record->id, $record->title),
            "department: {$record->department->value}",
            "filed_on: {$record->filed_on->toDateString()}",
            UntrustedText::record($record->id, $record->body),
        ]);

        return new ToolResult($content, returnedRecordIds: [$record->id]);
    }
}
