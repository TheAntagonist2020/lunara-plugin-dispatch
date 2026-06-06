# Lunara Dispatch Automation

Private WordPress plugin source for the Lunara Film Dispatch automation system.

## Role

Dispatch aggregates film-news sources, routes eligible items through the Lunara editorial prompt, creates draft Journal posts, manages source/image eligibility signals, and exposes admin controls for voice/prompt refinement and visual assignment.

## Source Locations

- Local source: `G:\lunara-backups\work\lunara-dispatch`
- Live plugin: `/home/151589083/htdocs/wp-content/plugins/lunara-dispatch`
- Continuity workspace: `C:\Users\silve_i21do49\OneDrive\Desktop\New folder`

## Version

Current baseline: `3.0.11`.

## Secrets

Do not commit provider API keys, WordPress application passwords, option exports, or environment files. Runtime credentials belong in server configuration or WordPress options, not this repository.

## Verification

- Run PHP lint on `lunara-dispatch.php` and `includes/*.php` after edits.
- Confirm the Dispatch admin settings screen loads.
- Confirm public routes do not leak Dispatch admin/prompt content.
- Run automation in draft/no-publish mode first after prompt, image, source, or provider changes.
