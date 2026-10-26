<?php

declare(strict_types=1);

namespace App\Assistant;

use Anthropic\Beta\Messages\BetaOutputConfig\Effort;
use RuntimeException;

final class ClaudeNotConfigured extends RuntimeException
{
    public static function noCredentials(): self
    {
        return new self('No Claude credentials found. Set ANTHROPIC_API_KEY in .env, or sign in with `ant auth login`.');
    }

    public static function invalidEffort(): self
    {
        return new self('CLAUDE_EFFORT must be one of: '.implode(', ', array_column(Effort::cases(), 'value')).'.');
    }
}
