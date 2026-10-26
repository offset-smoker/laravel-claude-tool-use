<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * The structured output the last message must match. The API constrains the
 * output to this schema; parse() still checks it, because a response that
 * stopped early or came from a fallback model is not guaranteed to.
 */
final readonly class FinalAnswer
{
    /** @param list<string> $citedRecordIds */
    public function __construct(
        public string $answer,
        public array $citedRecordIds,
    ) {}

    /** @return array<string, mixed> the JSON schema for output_config.format */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'answer' => ['type' => 'string'],
                'cited_record_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['answer', 'cited_record_ids'],
            'additionalProperties' => false,
        ];
    }

    public static function parse(string $json): ?self
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ! is_string($data['answer'] ?? null) || ! is_array($data['cited_record_ids'] ?? null)) {
            return null;
        }

        $ids = [];
        foreach ($data['cited_record_ids'] as $id) {
            if (! is_string($id)) {
                return null;
            }
            $ids[] = $id;
        }

        return new self($data['answer'], array_values(array_unique($ids)));
    }
}
