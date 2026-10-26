<?php

declare(strict_types=1);

namespace App\Actions;

final class Redactor
{
    /**
     * Finds every occurrence of every passage in the original body, merges
     * spans that overlap or touch, and replaces each merged span once. All
     * matching happens against the original body, so a passage can never
     * match inside a placeholder and an overlap cannot leave a fragment.
     *
     * @param  list<array{text: string, reason: string}>  $redactions
     */
    public function apply(string $body, array $redactions): string
    {
        $spans = [];

        foreach ($redactions as $redaction) {
            $length = strlen($redaction['text']);

            if ($length === 0) {
                continue;
            }

            $offset = 0;
            while (($start = strpos($body, $redaction['text'], $offset)) !== false) {
                $spans[] = ['start' => $start, 'end' => $start + $length, 'reasons' => [$redaction['reason']]];
                $offset = $start + 1;
            }
        }

        usort($spans, static fn (array $a, array $b): int => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);

        /** @var list<array{start: int, end: int, reasons: list<string>}> $merged */
        $merged = [];

        foreach ($spans as $span) {
            $last = array_key_last($merged);

            if ($last === null || $span['start'] > $merged[$last]['end']) {
                $merged[] = $span;

                continue;
            }

            $merged[$last]['end'] = max($merged[$last]['end'], $span['end']);
            $merged[$last]['reasons'] = array_values(array_unique([...$merged[$last]['reasons'], ...$span['reasons']]));
        }

        foreach (array_reverse($merged) as $span) {
            $body = substr_replace($body, '[REDACTED: '.implode(', ', $span['reasons']).']', $span['start'], $span['end'] - $span['start']);
        }

        return $body;
    }
}
