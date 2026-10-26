<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Models\Record;

/**
 * An answer passes only if every id it cites exists and was returned by a
 * tool during this run, and every record id it mentions is in its citations.
 */
final class CitationVerifier
{
    /** REC-0001 in any case, with a hyphen, any dash, a space or nothing between the parts. */
    private const string ID_IN_TEXT = '/\bREC[\s\p{Pd}]?(\d{4})\b/iu';

    /** @return list<string> one plain-English problem per failed check; empty when the answer passes */
    public function problems(FinalAnswer $answer, RunContext $context): array
    {
        $problems = [];

        $existing = Record::query()->whereIn('id', $answer->citedRecordIds)->pluck('id')->all();

        foreach ($answer->citedRecordIds as $id) {
            if (! in_array($id, $existing, true)) {
                $problems[] = "Cited {$id}, which does not exist.";

                continue;
            }

            if (! $context->wasReturned($id)) {
                $problems[] = "Cited {$id}, which no tool returned in this run.";
            }
        }

        preg_match_all(self::ID_IN_TEXT, $answer->answer, $matches);

        $mentionedIds = array_unique(array_map(static fn (string $digits): string => "REC-{$digits}", $matches[1]));

        foreach ($mentionedIds as $mentioned) {
            if (! in_array($mentioned, $answer->citedRecordIds, true)) {
                $problems[] = "Mentioned {$mentioned} in the answer without citing it.";
            }
        }

        return $problems;
    }
}
