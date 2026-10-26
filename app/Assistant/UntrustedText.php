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

    /** Control and invisible format characters, other than newline and tab. */
    private const string DROPPED = '/[^\P{C}\n\t]/u';

    /**
     * Drops control characters (keeping newline and tab) and escapes &, < and >,
     * so text inside a delimiter can never close that delimiter or open another.
     */
    public static function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", mb_scrub($text, 'UTF-8'));
        $text = (string) preg_replace(self::DROPPED, '', $text);

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

    /**
     * The passages of $original that clean() shows as $quoted (before
     * escaping), so a quote copied from what the model saw can be traced back
     * to the original characters, carriage returns and invisible characters
     * included. Distinct passages only, in order of first appearance.
     *
     * @return list<string>
     */
    public static function originalPassages(string $original, string $quoted): array
    {
        if ($quoted === '') {
            return [];
        }

        if (! mb_check_encoding($original, 'UTF-8')) {
            return str_contains($original, $quoted) ? [$quoted] : [];
        }

        preg_match_all('/\r\n|./su', $original, $characters, PREG_OFFSET_CAPTURE);

        // For each byte of the cleaned text, where its source character starts and ends in $original.
        $cleaned = '';
        $starts = [];
        $ends = [];

        foreach ($characters[0] as [$character, $offset]) {
            $shown = match (true) {
                $character === "\r\n", $character === "\r" => "\n",
                preg_match(self::DROPPED, $character) === 1 => '',
                default => $character,
            };

            for ($byte = 0; $byte < strlen($shown); $byte++) {
                $starts[] = $offset;
                $ends[] = $offset + strlen($character);
            }

            $cleaned .= $shown;
        }

        $passages = [];
        $from = 0;

        while (($position = strpos($cleaned, $quoted, $from)) !== false) {
            $start = $starts[$position];
            $passages[] = substr($original, $start, $ends[$position + strlen($quoted) - 1] - $start);
            $from = $position + 1;
        }

        return array_values(array_unique($passages));
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
        return (string) preg_replace(self::DROPPED, '', mb_scrub($text, 'UTF-8'));
    }
}
