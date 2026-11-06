<?php

use Aldanux\StatamicPolls\Enums\RecordResult;
use Aldanux\StatamicPolls\ServiceProvider;
use Aldanux\StatamicPolls\Votes\Vote;
use Aldanux\StatamicPolls\Votes\VoteRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/**
 * Both storage drivers keep the same promises: one vote per voter and poll, an atomic per-IP
 * cap, counts per option, and votes can be voided and listed (for migrations).
 */
function repository(string $driver): VoteRepository
{
    return app()->getProvider(ServiceProvider::class)->repository($driver);
}

function ballot(string $voter, string $option = 'text:a', string $ip = 'ip-1', string $poll = 'poll-1'): Vote
{
    return new Vote($poll, $option, $voter, $ip, 'default');
}

it('counts a voter only once per poll', function (string $driver) {
    $votes = repository($driver);

    expect($votes->record(ballot('ana')))->toBe(RecordResult::Recorded)
        ->and($votes->record(ballot('ana', 'text:b')))->toBe(RecordResult::Duplicate)
        ->and($votes->record(ballot('ana', poll: 'poll-2')))->toBe(RecordResult::Recorded)
        ->and($votes->hasVoted('poll-1', 'ana'))->toBeTrue()
        ->and($votes->hasVoted('poll-1', 'luis'))->toBeFalse();
})->with(['file', 'database']);

it('tallies votes per option and counts them per ip', function (string $driver) {
    $votes = repository($driver);

    $votes->record(ballot('ana', 'text:a', 'ip-1'));
    $votes->record(ballot('luis', 'text:a', 'ip-1'));
    $votes->record(ballot('marta', 'text:b', 'ip-2'));

    expect($votes->tally('poll-1'))->toEqual(['text:a' => 2, 'text:b' => 1])
        ->and($votes->countForIp('poll-1', 'ip-1'))->toBe(2)
        ->and($votes->tally('unknown'))->toBe([]);
})->with(['file', 'database']);

it('checks the per-ip cap in the same step as the write', function (string $driver) {
    $votes = repository($driver);

    expect($votes->record(ballot('ana'), maxPerIp: 2))->toBe(RecordResult::Recorded)
        ->and($votes->record(ballot('luis'), maxPerIp: 2))->toBe(RecordResult::Recorded)
        ->and($votes->record(ballot('marta'), maxPerIp: 2))->toBe(RecordResult::IpLimitReached)
        ->and($votes->record(ballot('marta', ip: 'ip-2'), maxPerIp: 2))->toBe(RecordResult::Recorded);
})->with(['file', 'database']);

it('voids votes by voter or by address and lists the rest', function (string $driver) {
    $votes = repository($driver);

    $votes->record(ballot('ana', ip: 'ip-1'));
    $votes->record(ballot('luis', ip: 'ip-1'));
    $votes->record(ballot('marta', ip: 'ip-2'));
    $votes->record(ballot('pau', ip: 'ip-3'));

    expect($votes->forget('poll-1', 'pau'))->toBeTrue()
        ->and($votes->forgetIp('poll-1', 'ip-1'))->toBe(2)
        ->and(collect($votes->all('poll-1'))->map->voterId->all())->toBe(['marta'])
        ->and($votes->polls())->toBe(['poll-1']);
})->with(['file', 'database']);

it('keeps a vote after a line torn by a crashed write', function () {
    repository('file')->record(ballot('ana', 'text:a'));
    File::append($this->votesPath.'/poll-1.jsonl', '{"poll":"poll-1","option":"text:');

    repository('file')->record(ballot('luis', 'text:b'));

    expect(repository('file')->tally('poll-1'))->toEqual(['text:a' => 1, 'text:b' => 1]);
});

it('rejects poll ids that could escape the votes folder', function () {
    repository('file')->hasVoted('../../.env', 'ana');
})->throws(InvalidArgumentException::class);

it('migrates votes between drivers', function () {
    repository('file')->record(ballot('ana', 'text:a'));
    repository('file')->record(ballot('luis', 'text:b'));
    repository('database')->record(ballot('ana', 'text:a'));

    $this->artisan('polls:migrate-votes', ['from' => 'file', 'to' => 'database'])
        ->expectsOutputToContain('1 votes copied, 1 already present')
        ->assertSuccessful();

    expect(repository('database')->tally('poll-1'))->toEqual(['text:a' => 1, 'text:b' => 1]);
});
