# Lunara Dispatch Automation

Private WordPress plugin source for the Lunara Film Dispatch automation system.

## Role

Dispatch aggregates film-news sources, routes eligible items through the Lunara editorial prompt, and hands verified draft payloads to Lunara Journal Foundation. Journal Foundation is a required dependency and is the only component allowed to create the canonical Journal drafts.

## Source Locations

- Local source: `G:\lunara-backups\work\lunara-dispatch`
- Live plugin: `/home/151589083/htdocs/wp-content/plugins/lunara-dispatch`
- Continuity workspace: `C:\Users\silve_i21do49\OneDrive\Desktop\New folder`

## Version

Current baseline: `3.2.3`.

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
