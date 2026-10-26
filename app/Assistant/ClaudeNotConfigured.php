<?php

declare(strict_types=1);

namespace App\Assistant;

use RuntimeException;

final class ClaudeNotConfigured extends RuntimeException
{
    public static function noCredentials(): self
    {
        return new self('No Claude credentials found. Set ANTHROPIC_API_KEY in .env, or sign in with `ant auth login`.');
    }
}
