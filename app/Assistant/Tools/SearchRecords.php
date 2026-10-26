<?php

declare(strict_types=1);

namespace App\Assistant\Tools;

use App\Assistant\RunContext;
use App\Assistant\UntrustedText;
use App\Enums\Department;
use App\Models\Record;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class SearchRecords implements Tool
{
    use ValidatesInput;

    public const int MAX_RESULTS = 5;

    private const int MAX_TERMS = 8;

    private const int SNIPPET_LENGTH = 200;

    public function name(): string
    {
        return 'search_records';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Search the town records by keyword. Call this first to find which records relate to the question; '
                .'it returns up to '.self::MAX_RESULTS.' matches with their id, title, department, filing date and the start of their text. '
                .'Use get_record for a record\'s full text before relying on details from it.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Keywords to look for in record titles and text, for example "library hours". 2 to 200 characters.',
                    ],
                    'department' => [
                        'type' => 'string',
                        'enum' => ['any', ...array_column(Department::cases(), 'value')],
                        'description' => 'Limit the search to one department, or "any".',
                    ],
                ],
                'required' => ['query', 'department'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ];
    }

    public function run(array $input, RunContext $context): ToolResult
    {
        $input = $this->validated($input, [
            'query' => ['required', 'string', 'min:2', 'max:200'],
            'department' => ['required', 'string', Rule::in(['any', ...array_column(Department::cases(), 'value')])],
        ]);

        $query = $input->string('query')->toString();
        $department = $input->string('department')->toString();
        $terms = array_slice(array_values(array_unique(array_filter(
            mb_split('\s+', mb_strtolower($query)) ?: [],
            static fn (string $term): bool => mb_strlen($term) >= 2,
        ))), 0, self::MAX_TERMS);

        if ($terms === []) {
            throw new ToolInputInvalid('Invalid input: the query needs at least one word of two or more characters.');
        }

        $candidates = Record::query()
            ->when($department !== 'any', fn (Builder $builder) => $builder->where('department', $department))
            ->where(function (Builder $builder) use ($terms): void {
                foreach ($terms as $term) {
                    $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
                    $builder->orWhereRaw("lower(title) like ? escape '!'", [$like])
                        ->orWhereRaw("lower(body) like ? escape '!'", [$like]);
                }
            })
            ->limit(50)
            ->get();

        $matches = $candidates
            ->sortByDesc(fn (Record $record): int => $this->score($record, $terms))
            ->take(self::MAX_RESULTS)
            ->values();

        $echo = UntrustedText::line($query);

        if ($matches->isEmpty()) {
            return new ToolResult("No records matched \"{$echo}\".");
        }

        $lines = ["{$matches->count()} record(s) matched \"{$echo}\", best match first:"];
        foreach ($matches as $record) {
            $lines[] = sprintf(
                '- %s | %s | %s | filed %s',
                $record->id,
                UntrustedText::line($record->title),
                $record->department->value,
                $record->filed_on->toDateString(),
            );
            $lines[] = UntrustedText::record($record->id, Str::limit($record->body, self::SNIPPET_LENGTH));
        }

        /** @var list<string> $ids */
        $ids = $matches->pluck('id')->all();

        return new ToolResult(implode("\n", $lines), returnedRecordIds: $ids);
    }

    /** @param list<string> $terms */
    private function score(Record $record, array $terms): int
    {
        $haystack = mb_strtolower($record->title.' '.$record->body);

        return count(array_filter($terms, static fn (string $term): bool => str_contains($haystack, $term)));
    }
}
