<?php

namespace Aldanux\StatamicPolls\Http\Controllers;

use Aldanux\StatamicPolls\Enums\Audience;
use Aldanux\StatamicPolls\Enums\PollStatus;
use Aldanux\StatamicPolls\Enums\VoteOutcome;
use Aldanux\StatamicPolls\Poll;
use Aldanux\StatamicPolls\PollManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The two endpoints the poll markup talks to. Pages are usually static-cached, so everything
 * that depends on the visitor (have I voted? may I see the counts?) comes from here, never
 * from the cached HTML.
 */
class PollController extends Controller
{
    public function __construct(protected PollManager $polls) {}

    public function state(Request $request, string $poll): JsonResponse
    {
        $poll = $this->findOrFail($poll, $request);
        $hasVoted = $this->polls->hasVoted($request, $poll);

        return $this->noStore(response()->json($this->statePayload($request, $poll, $hasVoted)));
    }

    public function vote(Request $request, string $poll): JsonResponse|RedirectResponse
    {
        $poll = $this->findOrFail($poll, $request);
        $option = $request->input('option');
        $outcome = $this->polls->vote($request, $poll, is_string($option) ? $option : '');
        $hasVoted = $outcome->counts() || $outcome === VoteOutcome::AlreadyVoted;

        if (! $request->expectsJson()) {
            return redirect()
                ->to(url()->previous().'#poll-'.$poll->id())
                ->with('statamic-polls.outcome', $outcome->value);
        }

        return $this->noStore(response()->json([
            'outcome' => $outcome->value,
            'message' => __('statamic-polls::messages.'.$outcome->value),
            ...$this->statePayload($request, $poll, $hasVoted),
        ], $outcome->httpStatus()));
    }

    /**
     * @return array{status: string, voted: bool, can_vote: bool, login_required: bool, results: array<string, mixed>|null}
     */
    protected function statePayload(Request $request, Poll $poll, bool $hasVoted): array
    {
        $status = $poll->status();
        $loginRequired = $poll->audience() === Audience::Authenticated
            && $this->polls->voterId($request, $poll) === null;

        return [
            'status' => $status->value,
            'voted' => $hasVoted,
            'can_vote' => $status === PollStatus::Open && ! $hasVoted && ! $loginRequired,
            'login_required' => $loginRequired,
            'results' => $poll->resultsVisibleTo($hasVoted) ? $this->polls->results($poll)->toArray() : null,
        ];
    }

    protected function findOrFail(string $id, Request $request): Poll
    {
        $site = $request->input('site');
        $poll = $this->polls->find($id, is_string($site) ? $site : null);

        abort_if(! $poll || ! $poll->isPublished(), 404);

        return $poll;
    }

    protected function noStore(JsonResponse $response): JsonResponse
    {
        return $response->header('Cache-Control', 'no-store, private');
    }
}
