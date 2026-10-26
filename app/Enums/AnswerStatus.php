<?php

declare(strict_types=1);

namespace App\Enums;

enum AnswerStatus: string
{
    /** The answer ended normally and every cited id passed verification. */
    case Verified = 'verified';

    /** The answer cited an id that does not exist or that no tool returned in this run. */
    case CitationsFailed = 'citations_failed';

    /** The final message was not the JSON the output schema asks for. */
    case Malformed = 'malformed';

    case Refused = 'refused';

    /** The response hit max_tokens; any tool call in it was not run. */
    case Truncated = 'truncated';

    case IterationLimit = 'iteration_limit';

    /** A stop reason this loop does not handle, such as pause_turn, or tool_use with no tool call in it. */
    case UnexpectedStop = 'unexpected_stop';

    /** A Messages API call failed: a network error, rate limit, overload or rejected request. */
    case ApiError = 'api_error';

    public function label(): string
    {
        return match ($this) {
            self::Verified => 'Answer (citations verified)',
            self::CitationsFailed => 'Answer NOT verified: the citation check failed',
            self::Malformed => 'No answer: the final message was malformed',
            self::Refused => 'No answer: the model declined',
            self::Truncated => 'No answer: the response was cut off',
            self::IterationLimit => 'No answer: the iteration limit was reached',
            self::UnexpectedStop => 'No answer: unexpected stop reason',
            self::ApiError => 'No answer: the Claude API returned an error',
        };
    }
}
