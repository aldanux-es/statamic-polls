# Polls for Statamic

Simple, reusable polls and voting for Statamic 6. Answers come from any collection (hand-picked entries or a query, e.g. "every active player") or plain text. One vote per visitor, results hidden until the poll closes, and markup that is safe to cache with static caching.

Built for the monthly "Player of the Month" vote of [UD Ibiza](https://www.ibizaud.com/jugador-power).

## Features

- **Polls are entries** of a `polls` collection, so editors manage them in the control panel. They're translatable, and every language counts towards the same result.
- **Answers from anywhere**: hand-picked entries, every entry of a collection that matches some conditions, or free text with an image.
- **Options frozen when voting opens**, so a player leaving mid-month doesn't break the poll. While an open poll has no votes yet, editors can still change them.
- **Results revealed when voting closes** (or after voting, or always), then frozen.
- **Votes stored in files or in the database**: the `file` driver works on flat-file sites, and `database` is meant for busy ones.
- **Anonymous or logged-in voters**: a signed cookie, or any Laravel guard (Statamic users, your own customer accounts).
- **Abuse protection**: honeypot, bot filter, per-minute rate limit and a per-IP cap enforced atomically with the write, plus optional Cloudflare Turnstile.
- **Static-cache friendly**: nothing in the markup depends on the visitor. A small script asks for the visitor's state only when it has to, and nothing flashes for visitors who already voted.
- **Control panel page**: a searchable listing with status, group and site filters, live counts for your team, CSV export, and voiding the votes of a suspicious address.

## Installation

```bash
composer require aldanux/statamic-polls
php please polls:install --entries=players
```

`polls:install` creates the `polls` collection and its blueprint. `--entries` sets the collections editors can pick single entries from (any number), and `--container` sets the asset container for the images of text answers.

Publish the config if you want to change the defaults:

```bash
php artisan vendor:publish --tag=statamic-polls-config
```

### Storing votes in the database

```env
POLLS_DRIVER=database
```

```bash
php artisan migrate
```

The migration creates a `poll_votes` table. Votes can be moved between drivers at any time with `php please polls:migrate-votes file database`.

### Scheduler

Polls open and close on their own: `polls:sync` runs every minute through Laravel's scheduler. Make sure the scheduler is running (`* * * * * php artisan schedule:run`).

## Usage

### Templates

```antlers
{{# A poll picked with an entries field (or an id) #}}
{{ polls :poll="my_poll_field" }}

{{# Every opened poll of a group, newest first #}}
{{ polls:archive group="player-of-the-month" }}

{{# The winning answer of the latest closed poll, e.g. for a banner #}}
{{ polls:winner group="player-of-the-month" }}
    {{ winner:label }} — {{ winner:percent }}%
{{ /polls:winner }}
```

As tag pairs, `polls` and `polls:archive` expose their data (`question`, `options`, `results`, `status`, `is_open`, `closes_on_text`, and more) so you can write your own markup.

### Front-end script

Include the script on pages that show polls. It fetches the visitor's state and sends votes without reloading the page. It sets itself up on import:

```js
// resources/js/site.js
import '../../vendor/aldanux/statamic-polls/resources/js/polls.js'
```

Or publish it and load it directly:

```bash
php artisan vendor:publish --tag=statamic-polls
```

```html
<script type="module" src="/vendor/statamic-polls/js/polls.js"></script>
```

Add the pre-paint helper to your layout's `<head>`. It hides the voting form before the first paint for visitors who already voted:

```antlers
{{ polls:head }}
```

### Static caching

Polls have no URL of their own, so Statamic can't know which pages show them. If you use static caching, list those pages in the invalidation rules. `polls:sync` saves a poll when it opens and when it closes, and these rules turn that save into fresh pages:

```php
// config/statamic/static_caching.php
'invalidation' => [
    'rules' => [
        'collections' => [
            'polls' => ['urls' => ['/player-of-the-month']],
        ],
    ],
],
```

With the `full` strategy, voting needs JavaScript: Statamic swaps the cached CSRF token by script.

### Customising the markup

```bash
php artisan vendor:publish --tag=statamic-polls-views
```

The views end up in `resources/views/vendor/statamic-polls`. Keep the `data-poll*` attributes, because the script relies on them.

## Control panel

**Content → Polls** lists every poll with its status and vote count, and you can filter by status, group and site. A poll's page shows the live count (visitors never see it before closing), exports the results as CSV, and lists the addresses that voted most, so you can void a scripted burst.

There are two permissions: **View poll results** and **Void poll votes**.

## Monthly polls

```bash
php please polls:duplicate {poll-id} --next-month
```

Copies a poll, with its translations, to next month: the new poll opens on the 1st and closes at the end of the month, and the month in each title is updated in its own language. The copy is unpublished and has no votes.

## Configuration

| Key | Default | |
|---|---|---|
| `collection` | `polls` | Collection holding the polls |
| `driver` | `file` | `file` or `database` (`POLLS_DRIVER`) |
| `voters.public` / `voters.authenticated` | built-in | Implement `Aldanux\StatamicPolls\Voters\Voter` for your own |
| `guard` | default guard | Guard used by logged-in polls (`POLLS_GUARD`) |
| `protection.max_votes_per_ip` | `25` | Votes one address may cast per poll (0 = no cap) |
| `protection.rate_limit_per_minute` | `10` | Vote attempts per address per minute |
| `turnstile.site_key` / `turnstile.secret` | — | Enable Cloudflare Turnstile (`POLLS_TURNSTILE_*`) |

## Testing

```bash
composer install
composer test
```

## License

MIT. See [LICENSE](LICENSE).
