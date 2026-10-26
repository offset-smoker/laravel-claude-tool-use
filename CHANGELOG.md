# Changelog

## 0.1.0 - 2026-10-26

First release.

- `records:ask` runs a manual Claude tool-use loop over ten synthetic records
  with three tools: `search_records`, `get_record` and `propose_redaction`.
- The loop stops on any stop reason other than `tool_use` and after a
  configurable number of model calls. Tool failures go back to the model as
  `is_error` tool results.
- Final answers are structured output (`answer`, `cited_record_ids`). Every
  cited id must exist and must have been returned by a tool in the same run,
  and every record id mentioned in the answer must be cited.
- Proposed releases are saved as pending and released only by a person with
  `records:approve`, after a confirmation that shows the redacted text.
  `records:reject`, `records:pending` and `records:release` complete the review.
- Prompt caching on the static tools and system prefix; usage and estimated
  cost logged to `storage/logs/claude-usage-*.log` without prompt content.
- Question, record titles and record text are sanitized and wrapped in
  delimiters, with an instruction-hardening line in the system prompt.
- A failed API call ends the run as `api_error`, still logging usage and
  listing the proposals saved before it; missing credentials and an invalid
  `CLAUDE_EFFORT` are reported in one line.
- Overlapping redactions are merged before they are applied, and redaction
  quotes are matched against the cleaned text the model was shown.
