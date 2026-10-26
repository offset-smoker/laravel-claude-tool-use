<?php

declare(strict_types=1);

namespace App\Assistant\Tools;

use App\Assistant\RunContext;

/**
 * The full set of tools offered to the model. The order is fixed, because
 * the tool list is part of the cached prompt prefix.
 */
final readonly class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools;

    public function __construct(SearchRecords $search, GetRecord $get, ProposeRedaction $propose)
    {
        $this->tools = [
            $search->name() => $search,
            $get->name() => $get,
            $propose->name() => $propose,
        ];
    }

    /** @return list<array{name: string, description: string, inputSchema: array<string, mixed>, strict: bool}> */
    public function definitions(): array
    {
        return array_values(array_map(static fn (Tool $tool): array => $tool->definition(), $this->tools));
    }

    /** @param array<array-key, mixed> $input */
    public function run(string $name, array $input, RunContext $context): ToolResult
    {
        $tool = $this->tools[$name] ?? null;

        if ($tool === null) {
            return ToolResult::error("Unknown tool {$name}. The available tools are: ".implode(', ', array_keys($this->tools)).'.');
        }

        try {
            $result = $tool->run($input, $context);
        } catch (ToolInputInvalid $invalid) {
            return ToolResult::error($invalid->getMessage());
        }

        $context->markReturned($result->returnedRecordIds);

        return $result;
    }
}
