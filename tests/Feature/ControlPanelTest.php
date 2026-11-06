<?php

use Aldanux\StatamicPolls\Poll;
use Aldanux\StatamicPolls\PollManager;
use Aldanux\StatamicPolls\Votes\Vote;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 15)->setTime(12, 0));
});

function cpPoll(array $data = []): StatamicEntry
{
    $entry = Entry::make()->id((string) Str::uuid())->collection('polls')->slug('poll-'.Str::random(6))
        ->data(array_merge([
            'title' => 'Best goal — November',
            'group' => 'goals',
            'opens_at' => '2026-11-01 00:00',
            'closes_at' => '2026-11-30 22:59',
            'options' => [
                ['id' => 'a', 'type' => 'text', 'label' => 'Ana'],
                ['id' => 'b', 'type' => 'text', 'label' => 'Bea'],
            ],
        ], $data))
        ->published(true);
    $entry->save();

    return $entry;
}

function cpVote(StatamicEntry $poll, string $option, string $voter, string $ip): void
{
    app(PollManager::class)->votes()->record(new Vote($poll->id(), $option, $voter, $ip, 'default'));
}

function editor(): void
{
    test()->actingAs(User::make()->id((string) Str::uuid())->email('editor@example.com')->makeSuper(), config('statamic.users.guards.cp', 'web'));
}

function listing(array $filters = [], array $query = []): TestResponse
{
    $params = $query + ($filters ? ['filters' => base64_encode(json_encode($filters))] : []);

    return test()->getJson(cp_route('statamic-polls.listing').'?'.http_build_query($params));
}

it('shows the polls page with native filters and live counts for editors', function () {
    $november = cpPoll();
    cpPoll(['title' => 'Best goal — October', 'opens_at' => '2026-10-01 00:00', 'closes_at' => '2026-10-31 22:59']);
    cpVote($november, 'text:a', 'v1', str_repeat('a', 64));
    editor();

    $this->get(cp_route('statamic-polls.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('statamic-polls::Index', false)
            ->where('filters.0.handle', 'poll_status')
            ->where('filters.1.handle', 'poll_group'));

    listing(query: ['search' => 'november'])
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.votes', 1)
        ->assertJsonPath('data.0.status_label', 'Open');

    listing(['poll_status' => ['status' => 'closed']])
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Best goal — October')
        ->assertJsonPath('meta.activeFilterBadges.poll_status', 'Status: Closed');

    $this->get(cp_route('statamic-polls.show', $november->id()))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('statamic-polls::Show', false)
            ->where('poll.total', 1)
            ->where('results.0.label', 'Ana')
            ->where('frozen', false));
});

it('exports the results as csv', function () {
    $poll = cpPoll();
    cpVote($poll, 'text:a', 'v1', str_repeat('a', 64));
    cpVote($poll, 'text:b', 'v2', str_repeat('b', 64));
    editor();

    $csv = $this->get(cp_route('statamic-polls.export', $poll->id()))
        ->assertOk()
        ->assertDownload('best-goal-november-results.csv')
        ->streamedContent();

    expect($csv)->toContain('Ana,1,50')->toContain('Bea,1,50');
});

it('voids the votes of one address and recounts a closed poll', function () {
    $poll = cpPoll();
    $burst = str_repeat('f', 64);
    cpVote($poll, 'text:a', 'v1', str_repeat('a', 64));
    cpVote($poll, 'text:b', 'bot1', $burst);
    cpVote($poll, 'text:b', 'bot2', $burst);
    $this->travelTo(now()->setDate(2026, 12, 1));
    $this->artisan('polls:sync');
    editor();

    $this->delete(cp_route('statamic-polls.void-ip', [$poll->id(), $burst]))
        ->assertRedirect(cp_route('statamic-polls.show', $poll->id()));

    $this->delete(cp_route('statamic-polls.void-ip', [$poll->id(), '127.0.0.1']))->assertNotFound();

    expect(app(PollManager::class)->results(Poll::fromEntry(Entry::find($poll->id())))->winners())->toBe(['text:a']);
});
