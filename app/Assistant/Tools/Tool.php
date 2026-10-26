<?php

declare(strict_types=1);

namespace App\Assistant\Tools;

use App\Assistant\RunContext;

interface Tool
{
    public function name(): string;

    /**
     * The tool definition sent to the Messages API, in the SDK's camelCase keys.
     *
     * @return array{name: string, description: string, inputSchema: array<string, mixed>, strict: bool}
     */
    public function definition(): array;

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ToolInputInvalid
     */
    public function run(array $input, RunContext $context): ToolResult;
}
