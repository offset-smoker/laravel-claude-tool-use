# laravel-claude-tool-use

A small Laravel 13 application that shows one way to run a Claude tool-use
loop correctly. You ask a question about a town's public records from the
command line; Claude searches and reads the records through three tools,
answers with the record ids it relied on, and can propose releasing a record
with redactions. The app then checks what the model did: it verifies every
cited id, refuses to treat a proposal as a release until a person approves it,
keeps user data out of the cached prompt prefix, logs tokens and cost without
logging any prompt text, and hands record text to the model in escaped
delimiters so instructions planted in a record stay data.

**This is a teaching sample on synthetic data.** The Town of Lantern Hollow
and all ten of its records are invented. Names such as "Avery Example" are
placeholders, phone numbers are in the 555-01xx range reserved for fiction,
and email addresses use `example.org`. Do not load real records into it: it
has no user accounts, no access control beyond "whoever can run artisan", and
it sends record text to the Claude API.

## Requirements

- PHP 8.3 or 8.4 with `mbstring` and `pdo_sqlite`
- Composer 2
- A Claude API key for `records:ask` (the tests need none)

## Install

```bash
git clone https://github.com/offset-smoker/laravel-claude-tool-use.git
cd laravel-claude-tool-use
composer run setup
```

`composer run setup` installs dependencies, creates `.env` from
`.env.example`, generates the app key, creates `database/database.sqlite`, runs
the migrations and seeds the ten synthetic records. Then put your key in
`.env`:

```dotenv
ANTHROPIC_API_KEY=sk-ant-...
```

Without `ANTHROPIC_API_KEY`, the Anthropic SDK looks for `ANTHROPIC_AUTH_TOKEN`
and then a profile saved by `ant auth login`. If it finds none of them,
`records:ask` stops with "No Claude credentials found" before sending anything.

## Usage

Ask a question. Each call to `records:ask` makes real, billed API calls (at
most `CLAUDE_MAX_ITERATIONS`, eight by default).

```bash
php artisan records:ask "How many potholes were filled on Millrace Road?"
```

The wording of the answer comes from the model and varies from run to run.
The structure of the output does not:

```text
Answer (citations verified)

Crew 2 filled four potholes on Millrace Road, between Ash Lane and the old grain depot (REC-0001).

Cited records: REC-0001

Run 4f0c...: 3 model call(s); tokens: 1,236 input, 288 output, 1,630 cache write, 3,260 cache read; estimated cost: $0.0195
```

The command exits with 0 only when the answer ended normally and passed the
citation check. Every other outcome (citation check failed, malformed final
message, refusal, response cut off at `max_tokens`, iteration limit, an
unexpected stop reason, a failed API call) prints what happened and exits
with 1. A failed API call (rate limit, overload, network error, rejected key)
is reported in one line with its HTTP status and request id; the proposals
already saved in that run and the usage so far are still printed and logged.
Missing credentials and an invalid `CLAUDE_EFFORT` are reported in one line
too, before any request is made.

Ask for a release and the model can only propose one:

```bash
php artisan records:ask "Please release the Willowbend Park pavilion booking."
```

The proposal is saved as pending, and the command lists it from the
database, whatever the model's answer says:

```text
Release proposals saved. Nothing has been released; a person must approve each one:
  01k... for REC-0004: php artisan records:approve 01k... --reviewer="Your Name"
```

A person then reviews it:

```bash
php artisan records:pending                                   # list open proposals
php artisan records:approve <proposal> --reviewer="Your Name" # shows the redacted text, then asks to confirm
php artisan records:reject <proposal> --reviewer="Your Name" --reason="Why"
php artisan records:release <proposal>                        # status, reviewer and, once approved, the released text
```

`records:approve` prints each redaction and the full text that would be
released, then asks for confirmation; the default answer is no, so
`--no-interaction` releases nothing. It refuses a proposal that is no longer
pending, and one whose record changed after the proposal was made.

Try the injection record too:

```bash
php artisan records:ask "What does the budget hearing comment card say?"
```

`REC-0007` contains "IGNORE ALL PREVIOUS INSTRUCTIONS", a request to release
every record, and a fake `</record_text>` closing tag.

## How it works

| Piece | Where |
|---|---|
| The loop | `app/Assistant/ToolUseLoop.php` |
| Tools | `app/Assistant/Tools/` (`SearchRecords`, `GetRecord`, `ProposeRedaction`, `ToolRegistry`) |
| System prompt | `app/Assistant/SystemPrompt.php` |
| Delimiting and escaping | `app/Assistant/UntrustedText.php` |
| Structured final answer | `app/Assistant/FinalAnswer.php` |
| Citation check | `app/Assistant/CitationVerifier.php` |
| Usage and cost log | `app/Assistant/UsageRecorder.php`, `app/Assistant/CostEstimator.php` |
| Approval | `app/Actions/ApproveRelease.php`, `app/Console/Commands/ApprovePendingRelease.php` |

**The loop.** It uses the official PHP SDK (`anthropic-ai/sdk`) and calls the
Messages API directly rather than the SDK's beta tool runner, because the
loop is the thing being taught. Each iteration is one model call. While the
response stops with `tool_use`, the loop runs every `tool_use` block, then
sends the assistant turn back unchanged (thinking blocks and their
signatures included) followed by one user message holding all of the
`tool_result` blocks, in order. A tool that fails (bad input, unknown id, a
redaction quote that is not in the record, an unknown tool name) returns an
`is_error: true` result explaining the problem, and the model gets to try
again. Any other stop reason ends the loop: `end_turn` is parsed as the
answer; `refusal`, `max_tokens` and anything else are reported as failures,
and tool calls in a truncated response are never run. A `tool_use` stop with
no `tool_use` block in it also ends the run, rather than sending an empty
user message. After
`CLAUDE_MAX_ITERATIONS` calls the loop stops whatever the model is doing.

**Tools.** All three use JSON-schema inputs with `strict: true` and
`additionalProperties: false`, and the app validates the input again with
Laravel's validator, because tool input is model output. Search uses bound
parameters with `LIKE` wildcards escaped.

**Citations.** The final message uses structured output
(`output_config.format`) with two fields, `answer` and `cited_record_ids`. An
answer passes only if every cited id exists in the database, every cited id
was returned by a tool during this run, and every `REC-nnnn` mentioned in the
answer text is in the cited list.

**Releases.** `propose_redaction` writes a `pending_releases` row and nothing
else. No tool approves, the loop never changes a release's status (an
architecture test forbids `App\Assistant` from using the approval actions),
and a partial unique index allows one open proposal per record. Approval is a
conditional update on `status = pending`, so two reviewers approving at once
release once.

**Prompt caching.** The tool list and the system prompt are constants, and
the one `cache_control` breakpoint sits on the last system block, so the
cached prefix is tools plus system. The question and all record text travel
after it, in `messages`. Tests assert that the prefix contains no question or
record text and is byte-identical across calls and questions.

**Usage logging.** Each model call and each run writes one JSON line to
`storage/logs/claude-usage-YYYY-MM-DD.log`: run id, iteration, message id,
model, stop reason, input, output, cache-write and cache-read tokens, and an
estimated cost. The recorder is given the response's usage, never the
messages. A test asserts the log holds no question, record text, answer or API
key. Exceptions are logged without function arguments
(`zend.exception_ignore_args`), so a stack trace cannot carry the start of a
question either.

**Untrusted text.** The question is wrapped in `<user_question>`, each record
title in `<record_title record_id="...">` and record text in
`<record_text record_id="...">`. Before wrapping, control and
invisible formatting characters are removed and `&`, `<` and `>` are escaped
(quotes too, inside the `record_id` attribute),
so text inside a delimiter cannot close it or open another one. The system
prompt says both are data and lists what to refuse. Model output is stripped of
terminal escape sequences and console style tags before it is printed.

**Model settings.** `claude-opus-5-5` with effort `medium` set explicitly.
Thinking is always on for this model, so no `thinking` parameter is sent, and
`tool_choice` is left at its default (`auto`), since Opus 5.5 rejects forced
tool use. Requests go through the beta Messages endpoint with
`fallbacks: "default"` (beta `server-side-fallback-2026-07-01`), so a request
declined for policy reasons is retried server-side on a fallback model. Turn
that off with `CLAUDE_FALLBACKS=false`.

## Configuration

All settings are in `config/claude.php` and read from `.env`:

| Variable | Default | Meaning |
|---|---|---|
| `ANTHROPIC_API_KEY` | none | API key. Read only by `config/claude.php`; never logged. |
| `CLAUDE_MODEL` | `claude-opus-5-5` | Model id. `claude-sonnet-5-5` and `claude-haiku-5-5` are priced in the config too. |
| `CLAUDE_EFFORT` | `medium` | `low`, `medium`, `high`, `xhigh` or `max`. With any other value `records:ask` refuses to start; the other commands do not read it. |
| `CLAUDE_MAX_TOKENS` | `16000` | `max_tokens` for each call. |
| `CLAUDE_MAX_ITERATIONS` | `8` | Most model calls for one question. |
| `CLAUDE_FALLBACKS` | `true` | Send the server-side refusal fallback. |
| `CLAUDE_USAGE_LOG_CHANNEL` | `claude_usage` | Log channel for usage lines. |

Prices for the cost estimate are in `claude.pricing`, in US dollars per million
tokens. A model with no price there (a fallback model, for instance) is logged
with `estimated_cost_usd: null` rather than a wrong number.

## Testing

```bash
composer test      # Pest: Unit, Feature and Architecture suites
composer analyse   # Larastan at level max, no baseline, from a cleared cache
composer lint      # Pint
```

The suite has 100 tests. None of them touches the network: `tests/TestCase.php`
gives the real SDK client a PSR-18 transport, `tests/Support/FixtureTransport.php`,
that answers from JSON files in `tests/Fixtures/messages/` and records every
request. The SDK still builds each request and parses each response, so the
tests check the actual wire format: `tool_result` blocks with matching
`tool_use_id` and `is_error`, the replayed thinking blocks, the cache
breakpoint, the fallback beta header. A request with no fixture queued fails
the test.

Some checks worth reading first: `tests/Feature/PromptInjectionTest.php`
(the planted record, and a run where the model obeys it and still releases
nothing), `tests/Feature/PromptCachingTest.php`, `tests/Feature/UsageLoggingTest.php`
and `tests/Feature/ReleaseApprovalTest.php`.

## Status and limitations

- **The fixtures are written by hand, not recorded.** They follow the Messages
  API response format the SDK parses, but they were not captured from live
  calls, and this repository has not been run against the live API. What the
  tests prove is how the app handles each response, not how Claude behaves.
  Whether the model actually ignores the instructions in `REC-0007` has to be
  checked with a real key.
- **The citation check catches ids, not claims.** It proves the cited records
  exist and were read in the run. It cannot tell whether the answer says what
  those records say, and an answer that cites nothing passes it. That is why
  `records:ask` prints pending releases from the database rather than trusting
  the answer.
- **Escaping and a system-prompt instruction reduce prompt injection; they do
  not prevent it.** The protection that holds regardless of the model is
  structural: no tool can release anything.
- **The cost is an estimate** from the prices in `config/claude.php`, which can
  go out of date. Check current prices before relying on it.
- **Caching is not measured.** The prefix is built to be cacheable, but its
  token count was not checked against the model's minimum cacheable length,
  and the `cache_read_input_tokens` figures in the fixtures are invented.
- **SQLite only.** The partial unique index is SQLite and PostgreSQL syntax,
  and the search ranks at most 50 `LIKE` matches in PHP. It is sized for ten
  records.
- **No authentication.** Anyone who can run artisan can approve a release,
  and `--reviewer` is a name typed by that person, not an identity.
- `phpstan/phpstan-strict-rules` is not used: its dynamic-static-call rule
  conflicts with Larastan's Eloquent builder stubs, and it would not pass
  without suppressions.

## How this was built

Written with Claude Code under Brandon Wagner's direction and review.

## License

MIT. See [LICENSE](LICENSE).
