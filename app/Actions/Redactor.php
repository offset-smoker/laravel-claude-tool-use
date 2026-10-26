<?php

declare(strict_types=1);

namespace App\Actions;

final class Redactor
{
    /**
     * Replaces every occurrence of each passage, longest passage first so a
     * short passage inside a longer one cannot leave part of the longer one.
     *
     * @param  list<array{text: string, reason: string}>  $redactions
     */
    public function apply(string $body, array $redactions): string
    {
        usort($redactions, static fn (array $a, array $b): int => mb_strlen($b['text']) <=> mb_strlen($a['text']));

        foreach ($redactions as $redaction) {
            $body = str_replace($redaction['text'], "[REDACTED: {$redaction['reason']}]", $body);
        }

        return $body;
    }
}
