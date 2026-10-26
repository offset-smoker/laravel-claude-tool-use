<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * Everything that did not come from this codebase (the user's question,
 * record titles and bodies) passes through here before it reaches a prompt,
 * and model output passes through here before it reaches a terminal.
 */
final class UntrustedText
{
    public const int MAX_QUESTION_LENGTH = 2000;

    /**
     * Drops control characters (keeping newline and tab) and escapes &, < and >,
     * so text inside a delimiter can never close that delimiter or open another.
     */
    public static function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", mb_scrub($text, 'UTF-8'));
        $text = (string) preg_replace('/[^\P{C}\n\t]/u', '', $text);

        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function question(string $question): string
    {
        return "<user_question>\n".self::clean(trim($question))."\n</user_question>";
    }

    public static function record(string $recordId, string $text): string
    {
        return '<record_text record_id="'.self::attribute($recordId).'">'."\n"
            .self::clean($text)."\n</record_text>";
    }

    /** A record title, on one line, inside its own delimiter. */
    public static function title(string $recordId, string $title): string
    {
        return '<record_title record_id="'.self::attribute($recordId).'">'.self::line($title).'</record_title>';
    }

    /** A single line for short fields such as a search query. */
    public static function line(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', self::clean($text)));
    }

    /** clean(), with double and single quotes escaped too, for an attribute value. */
    private static function attribute(string $text): string
    {
        return htmlspecialchars(self::line($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', double_encode: false);
    }

    /**
     * Model output on its way to a terminal: no escape sequences or other
     * control characters, newlines and tabs kept.
     */
    public static function forTerminal(string $text): string
    {
        return (string) preg_replace('/[^\P{C}\n\t]/u', '', mb_scrub($text, 'UTF-8'));
    }
}
