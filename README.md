# Lunara Dispatch Automation

Private WordPress plugin source for the Lunara Film Dispatch automation system.

## Role

Dispatch aggregates film-news sources, routes eligible items through the Lunara editorial prompt, and hands verified draft payloads to Lunara Journal Foundation. Journal Foundation is a required dependency and is the only component allowed to create the canonical Journal drafts.

## Source Locations

- Local source: `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\01_ACTIVE_PROJECTS\lunara-dispatch-first-draft-voice-20260801`
- Live plugin: `/home/151589083/htdocs/wp-content/plugins/lunara-dispatch`
- Continuity workspace: `C:\Users\silve_i21do49\OneDrive\Desktop\New folder`

## Version

Current baseline: `3.2.8`.

### 3.2.8 Journal voice alignment

- The fallback system prompt and user directive now agree with the voice Journal Foundation 1.2.14 compiles: talk not essay, first person allowed, opinion in paragraph one, performed-expertise phrases banned, template headlines banned, and every entry closing on a landing sentence with an engagement question only when the entry has a real fork worth arguing. The old "do not force a question" rule is gone; it contradicted the Journal skill. The fallback only executes when Foundation is absent, but the two must not disagree.
- OpenAI Responses requests now send `text.verbosity: medium` instead of `low`. Low is an explicit instruction to be terse, which is the wire-service register the Journal exists to reject. Reasoning stays off because reasoning tokens count against the 2200 output cap and would truncate a two-entry run; output cost is bounded by the same cap either way. The cost-guard contract pins the new pair.
- The post builder folds typographic punctuation (curly quotes, em and en dashes, ellipses, no-break spaces, and their HTML entities) to ASCII before splitting sections, so Foundation's non-ASCII warning is reserved for accented names and titles that a human should look at. Contract: `tests/journal-voice-runtime.php`.

### 3.2.7 Journal Foundation ownership and Site Studio status

- Replaces duplicate Foundation-owned runtime, voice, prompt, model, token, and source controls with plain-language read-only summaries and an authoritative Journal Control Plane handoff whenever the compatible protocol is available.
- Preserves every legacy recovery value against forged Settings API and source-storage writes while keeping provider credentials, diagnostics, manual runs, Reset Seen, history, and the visual-assignment queue in Dispatch.
- Adds an inert Site Studio operations destination and a deliberately redacted status API that exposes only aggregate health, timestamp, enabled-source count, and the canonical Dispatch action.
- Keeps the complete legacy recovery form available when Journal Foundation is absent or protocol-incompatible.

### 3.2.6 RSS source provenance

- Preserves the publication timestamp already supplied by RSS or Atom feeds and passes it to Journal Foundation in explicit-offset ISO-8601 form.
- Preserves the article author from SimplePie, with Dublin Core creator as a bounded fallback.
- Adds no new page request, API dependency, or publication capability; Dispatch remains draft-only.

### 3.2.5 Cost-safe OpenAI and no-AI continuity

- Moves OpenAI generation to the Responses API with `store: false`, no reasoning overhead, low verbosity, an 18,000-character input ceiling, and a 2,200-token output ceiling.
- Enforces a cost-controlled model allowlist and defaults stale or frontier-priced configuration to `gpt-5.4-mini`.
- Records requested/effective model, token usage, and a per-run cost estimate without storing secret values.
- Classifies billing, authentication, and rate-limit failures distinctly.
- Creates clearly marked, four-paragraph source-packet drafts when AI is unavailable instead of allowing billing or provider failure to stop the Journal desk.
- Preserves canonical source URL, source date, idempotency, draft-only status, original image handling, and explicit needs-review state through the existing Journal Foundation contract.
- Keeps the normal editorial quality gate unchanged; only internally marked source packets use the narrow fallback contract.

### 3.2.4 Source Radar full-context bridge

- Consumes only new private Source Radar signals exposed by Journal Foundation 1.2.6 and keeps IFTTT in its intended transport role.
- Retrieves article text with WordPress safe HTTP validation, a 1 MB response ceiling, two-redirect cap, ten-second timeout, eight-read run budget, and positive/negative transient caching.
- Extracts the likely article body, caps ephemeral model context at 12,000 characters, and places it inside the existing untrusted-source envelope.
- Keeps copied article bodies out of the canonical Journal source ledger; only the bounded source excerpt, headline, publication, and URL persist.
- Reuses the exact source-story image path for captured Source Radar URLs when an Open Graph/Twitter lead is available.
- Triages Source Radar only after a draft or an allowlisted editorial terminal outcome. Network, AI, insertion, and lock failures remain eligible for retry.
- Does not select or change an AI provider. The active Journal Control Plane remains authoritative, and Dispatch remains draft-only with explicit approval required for publication.

### 3.2.3 Exact source-story images

- Restores automatic featured-image imports from the exact story that produced each Journal draft.
- Prefers RSS media, Open Graph, and Twitter lead-image signals and records the source story, publication, extraction signal, and any supplied credit or license metadata.
- Matches new drafts to images only by their canonical source URL; it never guesses from titles or keywords.
- Reuses an existing Media Library attachment when either the image URL or source-story URL has already been imported.
- Keeps HTTPS, response-byte, file-type, dimension, decoded-pixel, draft-only, and no-overwrite guards.
- Adds a dry-run-first `wp lunara-dispatch source-images` repair command; `--commit` is required to backfill existing Dispatch drafts.

### 3.2.2 Same-second heartbeat repair

- Keeps a valid worker lock when MySQL reports zero changed rows because two heartbeats wrote the same second-level payload.
- Re-reads the authoritative lock row and accepts the no-op only when the same owner still holds an unexpired lock.

## Editorial Quality Gate

Dispatch keeps generated Journal entries in draft-oriented review mode and now records why generated sections fail the runtime quality gate. The gate rejects thin entries, weak feed-parser headlines, banned generic phrases, sections without a distinct Lunara angle, sections without reader-pull or human-stake signals, source-risk items without enough original judgment, and prose that leans too heavily into dead analyst/register language.

Dispatch-imported images also receive practical attachment alt text from the source item title when no alt text is already present.

## Secrets

Do not commit provider API keys, WordPress application passwords, option exports, or environment files. Runtime credentials belong in server configuration or WordPress options, not this repository.

## Verification

- Run PHP lint on `lunara-dispatch.php` and `includes/*.php` after edits.
- Confirm the Dispatch admin settings screen loads.
- Confirm public routes do not leak Dispatch admin/prompt content.
- Run automation in draft/no-publish mode first after prompt, image, source, or provider changes.

## 3.1.1 Source Runtime Hotfix

- Restores the missing Control Plane source normalizer used by the Dispatch settings screen and feed runtime.
- Prevents the legacy fallback path from recursively calling the unavailable Control Plane client.
- Adds regression tests for both Control Plane and legacy source loading.

## 3.1.0 Control Plane Integration

Dispatch now reads runtime configuration from LUNARA Journal Foundation when the Journal Control Plane is active.

- Target post type is forced to `journal`.
- Creation status is forced to `draft`.
- Provider, model, max tokens, schedule, sources, and prompts are consumed from `Journal → Control Plane`.
- Existing API key options remain stored separately and are never exported by the Control Plane.
- New Journal drafts receive Control Plane provenance metadata.
- The legacy Dispatch settings screen remains useful for diagnostics, manual runs, and API-key visibility, but runtime governance lives in the Control Plane.


## 3.2.0 Fast Journal Desk

Adds an asynchronous manual-run queue used by the private LUNARA GPT. `queue_manual_run()` schedules `lunara_dispatch_manual_requested`, spawns WordPress cron, and returns immediately. The actual run still uses the authoritative Control Plane and always creates Journal drafts.

## 3.2.1 Stabilized Journal Integration

- Keeps the scheduled worker aligned with each activated Journal Control Plane configuration.
- Uses an atomic owner-token lock, heartbeat, conditional release, run IDs, and bounded outcome history.
- Queues Settings runs asynchronously and sends every generated entry through Journal Foundation's same-process, draft-only ingest contract.
- Uses a source-stable idempotency key so retries reuse the verified Journal draft instead of creating duplicates.
- Requires Journal Foundation and fails closed when it is absent, deactivated, protocol-incompatible, or missing its ingest handler. Dispatch has no standalone Journal insert fallback.
- Bounds prioritized source input, provider payloads, remote response sizes, and image downloads.
- Resolves provider secrets from server constants or environment variables before legacy WordPress options; admin screens show presence only.
- Downloads the lead image exposed by the exact source story only after a draft passes editorial gates. Attribution and source provenance are retained, explicit license metadata is preserved when supplied, and image bytes, dimensions, decoded pixels, and existing featured images remain bounded/protected.
- Restores the packaged dynamic Journal blocks as editable inserter-visible blocks with route-scoped public styling.
- Retires the legacy Dispatch roundup splitter. Existing-content conversion belongs to Journal Foundation's read-only preview and explicit confirmation flow.

Recommended server secret names: `LUNARA_DISPATCH_CLAUDE_API_KEY`, `LUNARA_DISPATCH_OPENAI_API_KEY`, `LUNARA_DISPATCH_GEMINI_API_KEY`, and `LUNARA_DISPATCH_GROK_API_KEY`.
