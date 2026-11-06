<?php

use Aldanux\StatamicPolls\Enums\PollStatus;
use Aldanux\StatamicPolls\Poll;
use Aldanux\StatamicPolls\PollManager;
use Aldanux\StatamicPolls\Votes\Vote;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Antlers;
use Statamic\Facades\CP\Toast;
use Statamic\Facades\Entry;

const BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Safari/605.1.15';

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 15)->setTime(12, 0));
});

function person(string $title, bool $active = true, int $number = 1): StatamicEntry
{
    $entry = Entry::make()->id((string) Str::uuid())->collection('people')->slug(Str::slug($title))
        ->data(['title' => $title, 'active' => $active, 'number' => $number])->published(true);
    $entry->save();

    return $entry;
}

/**
 * A monthly poll: every active person plus a free text answer, open during November 2026.
 */
function poll(array $data = []): StatamicEntry
{
    $entry = Entry::make()->id((string) Str::uuid())->collection('polls')->slug('poll-'.Str::random(6))
        ->data(array_merge([
            'title' => 'Player of the month — November',
            'question' => 'Who was the best player of the month?',
            'group' => 'player-of-the-month',
            'opens_at' => '2026-11-01 00:00',
            'closes_at' => '2026-11-30 22:59',
            'options' => [
                ['id' => 'q1', 'type' => 'query', 'enabled' => true, 'collection' => ['people'],
                    'conditions' => [['field' => 'active', 'operator' => '=', 'value' => 'true']], 'order_by' => 'number:asc'],
                ['id' => 'other', 'type' => 'text', 'enabled' => true, 'label' => 'Someone else'],
            ],
        ], $data))
        ->published(true);
    $entry->save();

    return $entry;
}

function vote(string $pollId, mixed $option, array $extra = [], string $agent = BROWSER): TestResponse
{
    return test()->withCredentials()->withHeader('User-Agent', $agent)
        ->postJson("/!/statamic-polls/polls/{$pollId}/vote", ['option' => $option, ...$extra]);
}

function render(string $template, array $data = []): string
{
    return (string) Antlers::parse($template, $data, true);
}

it('builds options from collection queries, picked entries and text', function () {
    $nine = person('Nine', number: 9);
    $four = person('Four', number: 4);
    person('Retired', active: false);

    expect(Poll::fromEntry(poll())->options()->map->key->all())
        ->toBe(['entry:'.$four->id(), 'entry:'.$nine->id(), 'text:other']);

    $picked = Poll::fromEntry(poll(['options' => [
        ['id' => 'e1', 'type' => 'entry', 'entry' => [$four->id()], 'label' => 'The captain'],
        ['id' => 'q1', 'type' => 'query', 'collection' => ['people'], 'conditions' => [['field' => 'number', 'operator' => '>=', 'value' => '4']]],
    ]]));

    expect($picked->options()->map->key->all())->toBe(['entry:'.$four->id(), 'entry:'.$nine->id()])
        ->and($picked->options()->first()->toTemplateData()['label'])->toBe('The captain');
});

it('follows the opening and closing dates', function (string $opens, string $closes, PollStatus $status) {
    expect(Poll::fromEntry(poll(['opens_at' => $opens, 'closes_at' => $closes]))->status())->toBe($status);
})->with([
    'scheduled' => ['2026-12-01 00:00', '2026-12-31 22:59', PollStatus::Scheduled],
    'open' => ['2026-11-01 00:00', '2026-11-30 22:59', PollStatus::Open],
    'closed' => ['2026-10-01 00:00', '2026-10-31 22:59', PollStatus::Closed],
]);

it('lets a visitor vote once, remembered by a cookie set only when voting', function () {
    $poll = poll();

    $this->getJson("/!/statamic-polls/polls/{$poll->id()}/state")
        ->assertOk()
        ->assertJson(['status' => 'open', 'voted' => false, 'can_vote' => true, 'results' => null])
        ->assertCookieMissing('poll_voter');

    $first = vote($poll->id(), 'text:other')->assertOk()->assertJson(['outcome' => 'recorded', 'voted' => true]);
    $this->withCookie('poll_voter', $first->getCookie('poll_voter')->getValue());

    vote($poll->id(), 'text:other')->assertStatus(409)->assertJson(['outcome' => 'already_voted']);
    $this->getJson("/!/statamic-polls/polls/{$poll->id()}/state")
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson(['voted' => true, 'can_vote' => false, 'results' => null]);
});

it('keeps counts secret until the poll closes', function () {
    $poll = poll();
    vote($poll->id(), 'text:other')->assertOk()->assertJson(['results' => null]);

    $this->travelTo(now()->setDate(2026, 12, 1));

    $this->getJson("/!/statamic-polls/polls/{$poll->id()}/state")
        ->assertJsonPath('status', 'closed')
        ->assertJsonPath('results.total', 1)
        ->assertJsonPath('results.winners', ['text:other']);
});

it('ignores bots and honeypot fillers without telling them', function (array $extra, string $agent) {
    $poll = poll();

    vote($poll->id(), 'text:other', $extra, $agent)->assertOk()->assertJson(['outcome' => 'ignored']);

    expect(app(PollManager::class)->votes()->tally($poll->id()))->toBe([]);
})->with([
    'crawler' => [[], 'Googlebot/2.1 (+http://www.google.com/bot.html)'],
    'honeypot' => [['poll_website' => 'http://spam.example'], BROWSER],
]);

it('refuses votes outside the window, for unknown answers or sent as arrays', function () {
    $closed = poll(['opens_at' => '2026-10-01 00:00', 'closes_at' => '2026-10-31 22:59']);
    $open = poll();

    vote($closed->id(), 'text:other')->assertForbidden()->assertJson(['outcome' => 'not_open']);
    vote($open->id(), 'text:nope')->assertUnprocessable()->assertJson(['outcome' => 'invalid_option']);
    vote($open->id(), ['text:other'])->assertUnprocessable()->assertJson(['outcome' => 'invalid_option']);
});

it('checks every vote with cloudflare turnstile once both keys are set', function () {
    config(['statamic-polls.turnstile.site_key' => 'site-key', 'statamic-polls.turnstile.secret' => 'secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::sequence()
        ->push(['success' => false, 'error-codes' => ['timeout-or-duplicate']])
        ->push(['success' => true])]);
    $poll = poll();

    expect(render('{{ polls :poll="poll" }}', ['poll' => $poll->id()]))
        ->toContain('data-poll-turnstile data-sitekey="site-key"');

    vote($poll->id(), 'text:other')->assertUnprocessable()->assertJson(['outcome' => 'verification_failed']);
    Http::assertNothingSent();

    vote($poll->id(), 'text:other', ['cf-turnstile-response' => 'spent'])->assertUnprocessable();
    vote($poll->id(), 'text:other', ['cf-turnstile-response' => 'fresh'])->assertOk()->assertJson(['outcome' => 'recorded']);

    Http::assertSent(fn (HttpRequest $request): bool => $request['secret'] === 'secret'
        && $request['response'] === 'fresh'
        && $request['remoteip'] === '127.0.0.1');
});

it('turns the vote away when cloudflare does not answer', function () {
    config(['statamic-polls.turnstile.site_key' => 'site-key', 'statamic-polls.turnstile.secret' => 'secret']);
    Http::fake(fn () => throw new ConnectionException('Timed out'));
    $poll = poll();

    vote($poll->id(), 'text:other', ['cf-turnstile-response' => 'token'])
        ->assertUnprocessable()
        ->assertJson(['outcome' => 'verification_failed']);

    expect(app(PollManager::class)->votes()->tally($poll->id()))->toBe([]);
});

it('leaves turnstile out until both keys are set', function () {
    config(['statamic-polls.turnstile.site_key' => 'site-key', 'statamic-polls.turnstile.secret' => null]);
    Http::fake();
    $poll = poll();

    expect(render('{{ polls :poll="poll" }}', ['poll' => $poll->id()]))->not->toContain('data-poll-turnstile');
    vote($poll->id(), 'text:other')->assertOk();
    Http::assertNothingSent();
});

it('confirms a vote sent without javascript when the visitor comes back', function () {
    $poll = poll();
    $other = poll();

    $this->withHeader('User-Agent', BROWSER)->from('/vote-here')
        ->post("/!/statamic-polls/polls/{$poll->id()}/vote", ['option' => 'text:other'])
        ->assertRedirect('/vote-here#poll-'.$poll->id())
        ->assertSessionHas('statamic-polls.outcome', 'recorded')
        ->assertSessionHas('statamic-polls.poll', $poll->id());

    expect(render('{{ polls :poll="poll" }}', ['poll' => $poll->id()]))
        ->toContain('poll__outcome--recorded')
        ->toContain('Thanks for voting!')
        ->and(render('{{ polls :poll="poll" }}', ['poll' => $other->id()]))
        ->not->toContain('poll__outcome');
});

it('shows the outcome for any poll, or for the one given as an id or an entry', function () {
    $poll = poll();
    $other = poll();
    session()->flash('statamic-polls.outcome', 'already_voted');
    session()->flash('statamic-polls.poll', $poll->id());

    expect(render('{{ polls:outcome }}{{ outcome }}|{{ is_success ? "ok" : "ko" }}{{ /polls:outcome }}'))->toBe('already_voted|ok')
        ->and(render('{{ polls:outcome :poll="poll" }}{{ message }}{{ /polls:outcome }}', ['poll' => $poll]))->toBe('You have already voted in this poll.')
        ->and(render('{{ polls:outcome :poll="poll" }}{{ message }}{{ /polls:outcome }}', ['poll' => $other]))->toBe('');
});

it('leaves the no-javascript outcome out with full static caching', function () {
    $poll = poll();
    session()->flash('statamic-polls.outcome', 'recorded');
    session()->flash('statamic-polls.poll', $poll->id());
    config(['statamic.static_caching.strategy' => 'full']);

    expect(render('{{ polls :poll="poll" }}', ['poll' => $poll->id()]))->not->toContain('poll__outcome');
});

it('caps the votes of a shared address', function () {
    config(['statamic-polls.protection.max_votes_per_ip' => 1]);
    $poll = poll();

    vote($poll->id(), 'text:other')->assertOk();
    $this->withCookie('poll_voter', (string) Str::uuid());

    vote($poll->id(), 'text:other')->assertTooManyRequests()->assertJson(['outcome' => 'too_many_attempts']);
});

it('asks anonymous visitors to log in for polls of logged-in users', function () {
    $poll = poll(['audience' => 'authenticated']);

    vote($poll->id(), 'text:other')->assertUnauthorized()->assertJson(['outcome' => 'login_required']);

    $this->actingAs(new GenericUser(['id' => 42]));

    vote($poll->id(), 'text:other')->assertOk()->assertJson(['outcome' => 'recorded']);
    vote($poll->id(), 'text:other')->assertStatus(409);
});

it('freezes the options on opening and the result on closing', function () {
    $nine = person('Nine');
    $poll = poll();
    $this->artisan('polls:sync')->assertSuccessful();

    $nine->set('active', false)->save();
    vote($poll->id(), 'entry:'.$nine->id())->assertOk();

    $this->travelTo(now()->setDate(2026, 12, 1));
    $this->artisan('polls:sync')->assertSuccessful();

    $fresh = Poll::fromEntry(Entry::find($poll->id()));
    app(PollManager::class)->votes()->record(new Vote($poll->id(), 'text:other', 'late', 'ip', 'default'));

    expect($fresh->options()->map->key->all())->toContain('entry:'.$nine->id())
        ->and($fresh->frozenResults())->toMatchArray(['tally' => ['entry:'.$nine->id() => 1], 'total' => 1])
        ->and(app(PollManager::class)->results($fresh)->winners())->toBe(['entry:'.$nine->id()]);
});

it('refreshes the frozen options while an open poll has no votes, and locks them after', function () {
    $nine = person('Nine');
    $poll = poll();
    $this->artisan('polls:sync');

    Entry::find($poll->id())->set('options', [['id' => 'n1', 'type' => 'entry', 'entry' => [$nine->id()]]])->save();

    expect(Poll::fromEntry(Entry::find($poll->id()))->options()->map->key->all())->toBe(['entry:'.$nine->id()]);

    vote($poll->id(), 'entry:'.$nine->id())->assertOk();
    Entry::find($poll->id())->set('options', [['id' => 't1', 'type' => 'text', 'label' => 'Nobody']])->save();

    expect(Poll::fromEntry(Entry::find($poll->id()))->options()->map->key->all())->toBe(['entry:'.$nine->id()])
        ->and(collect(Toast::all())->map(fn ($toast) => $toast->toArray()['message'])->all())
        ->toBe([__('statamic-polls::cp.options_locked')]);
});

it('renders cache-safe markup: a form while open, the final ranking once closed', function () {
    person('Nine');
    $poll = poll();

    expect(render('{{ polls :poll="poll" }}', ['poll' => $poll->id()]))
        ->toContain('data-poll-status="open"')
        ->toContain('/!/statamic-polls/polls/'.$poll->id().'/vote')
        ->toContain('Nine')
        ->toContain('name="poll_website"')
        ->not->toContain('<progress');

    vote($poll->id(), 'text:other')->assertOk();
    $this->travelTo(now()->setDate(2026, 12, 1));
    $this->artisan('polls:sync');

    expect(render('{{ polls :poll="poll" }}', ['poll' => $poll->id()]))
        ->toContain('data-poll-status="closed"')
        ->toContain('poll__result--winner')
        ->not->toContain('<form');
});

it('lists the opened polls of a group, newest first, and the latest winner', function () {
    poll(['title' => 'October', 'opens_at' => '2026-10-01 00:00', 'closes_at' => '2026-10-31 22:59']);
    poll(['title' => 'November']);
    poll(['title' => 'December', 'opens_at' => '2026-12-01 00:00', 'closes_at' => '2026-12-31 22:59']);
    poll(['title' => 'Other', 'group' => 'other']);
    $october = Entry::query()->where('collection', 'polls')->where('title', 'October')->first();
    app(PollManager::class)->votes()->record(new Vote($october->id(), 'text:other', 'ana', 'ip', 'default'));
    $this->artisan('polls:sync');

    expect(render('{{ polls:archive group="player-of-the-month" }}{{ items }}{{ title }}|{{ /items }}{{ /polls:archive }}'))
        ->toBe('November|October|')
        ->and(render('{{ polls:winner group="player-of-the-month" }}{{ winner:label }}{{ /polls:winner }}'))
        ->toBe('Someone else');
});

it('hides the form before paint in browsers that already voted', function () {
    expect(render('{{ polls:head }}'))
        ->toStartWith('<script>')
        ->toContain('statamic-polls:voted:')
        ->toContain('[data-poll-form]{display:none!important}');
});

it('copies a poll for next month without its votes or snapshot', function () {
    $poll = poll(['title' => 'Player of the month — November']);
    $this->artisan('polls:sync');

    $this->artisan('polls:duplicate', ['poll' => $poll->id(), '--next-month' => true])->assertSuccessful();

    $copy = Entry::query()->where('collection', 'polls')->where('title', 'Player of the month — December')->first();

    expect($copy)->not->toBeNull()
        ->and($copy->published())->toBeFalse()
        ->and(Poll::fromEntry($copy)->hasSnapshot())->toBeFalse()
        ->and($copy->get('opens_at'))->toBe('2026-12-01 00:00')
        ->and($copy->get('closes_at'))->toBe('2026-12-31 23:59');
});
