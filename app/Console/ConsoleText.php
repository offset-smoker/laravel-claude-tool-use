<?php

declare(strict_types=1);

namespace App\Console;

use App\Assistant\UntrustedText;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Text from the model or from a record, made safe to print: no terminal
 * escape sequences, and no <tags> that the console would read as styles.
 */
final class ConsoleText
{
    public static function safe(string $text): string
    {
        return OutputFormatter::escape(UntrustedText::forTerminal($text));
    }
}
