# Changelog

All notable changes to this addon are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/).

## [0.1.2] - 2026-11-06

### Fixed

- Cloudflare Turnstile now works as documented: `polls.js` loads Cloudflare's script (or waits for the site's own copy) and renders the widget in each open poll. Before, nothing loaded the script, so with both keys set every vote was turned away. After a failed vote the widget is reset, so the visitor can try again; a vote sent before the check finishes asks the visitor to wait, and if the script or the widget fails the visitor is told so and it is retried.
- A vote sent without JavaScript now shows its outcome ("Thanks for voting!") when the visitor comes back to the page, through the new `polls:outcome` tag inside `{{ nocache }}`. Left out with `full` static caching, where voting needs JavaScript.

### Changed

- The Turnstile placeholder in the default view is now `<div data-poll-turnstile data-sitekey="…">`, and the error element carries `data-poll-error-verifying` and `data-poll-error-unverified`. Published views with the old `.cf-turnstile` placeholder keep working; without the new attributes they show the generic error message.

### Added

- README: setting up Turnstile, what it sends to Cloudflare, a privacy section and control panel screenshots.

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
