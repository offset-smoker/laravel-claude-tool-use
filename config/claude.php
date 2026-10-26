<?php

declare(strict_types=1);

return [

    /*
    | The key is read here and nowhere else. It is never logged.
    */
    'api_key' => env('ANTHROPIC_API_KEY'),

    'model' => env('CLAUDE_MODEL', 'claude-opus-5-5'),

    /*
    | Claude Opus 5.5 always thinks; effort is the depth control. Its default
    | is "medium", set here explicitly so a reader can see it.
    */
    'effort' => env('CLAUDE_EFFORT', 'medium'),

    'max_tokens' => (int) env('CLAUDE_MAX_TOKENS', 16000),

    /*
    | Hard cap on model calls per question. One call per iteration, so this
    | also caps what one question can cost.
    */
    'max_iterations' => (int) env('CLAUDE_MAX_ITERATIONS', 8),

    /*
    | Server-side refusal fallback. "default" lets the API pick the fallback
    | model; set CLAUDE_FALLBACKS=off to send no fallback parameter at all.
    */
    'fallbacks' => env('CLAUDE_FALLBACKS', 'default'),

    'usage_log_channel' => env('CLAUDE_USAGE_LOG_CHANNEL', 'claude_usage'),

    /*
    | US dollars per million tokens, for the cost estimate in the usage log.
    | Cache writes are 1.25x input (5-minute TTL); cache reads are listed per
    | model. Check https://claude.com/pricing before relying on these.
    */
    'pricing' => [
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_write' => 5.00, 'cache_read' => 0.20],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_write' => 2.50, 'cache_read' => 0.20],
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50, 'cache_write' => 0.125, 'cache_read' => 0.01],
    ],

];
