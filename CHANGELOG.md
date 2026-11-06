# Changelog

All notable changes to this addon are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/).

## [0.1.1] - 2026-11-06

### Changed

- Documentation and examples no longer refer to a specific site.

## [0.1.0] - 2026-11-06

First public release.

### Added

- Polls as entries of a `polls` collection, with a blueprint created by `polls:install`.
- Answers from hand-picked entries, collection queries with conditions, or free text with an image.
- `file` (JSON lines in storage) and `database` vote drivers, plus `polls:migrate-votes` to move between them.
- Anonymous (cookie) and logged-in (any Laravel guard) voters.
- Honeypot, bot filter, atomic per-minute and per-IP limits, and optional Cloudflare Turnstile.
- `polls:sync` (scheduled every minute): freezes the options when a poll opens and the result when it closes. Changing the options of an open poll without votes refreshes the frozen list.
- `polls`, `polls:archive`, `polls:winner` and `polls:head` tags, a static-cache-safe default view and a front-end script.
- Control panel page (Inertia + Vue): searchable listing with status, group and site filters; results with live counts, CSV export and voiding the votes of an address. Permissions `view polls` and `void poll votes`.
- `polls:duplicate --next-month` for monthly polls, retitled per language.
- English and Spanish translations.
