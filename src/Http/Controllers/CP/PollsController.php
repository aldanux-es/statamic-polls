<?php

namespace Aldanux\StatamicPolls\Http\Controllers\CP;

use Aldanux\StatamicPolls\Enums\PollStatus;
use Aldanux\StatamicPolls\Poll;
use Aldanux\StatamicPolls\PollManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Scope;
use Statamic\Facades\Site;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Http\Requests\FilteredRequest;
use Statamic\Query\Scopes\Filters\Concerns\QueriesFilters;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Results for editors, as Inertia pages like the rest of the control panel. Unlike the public
 * endpoints, editors see live counts of open polls too: only visitors must not see them
 * before the poll closes.
 */
class PollsController extends CpController
{
    use QueriesFilters;

    public function __construct(protected PollManager $polls) {}

    public function index(): Response
    {
        $this->authorize('view polls');

        $collection = $this->polls->collection();

        return Inertia::render('statamic-polls::Index', [
            'endpoint' => cp_route('statamic-polls.listing'),
            'columns' => $this->columns(),
            'filters' => Scope::filters('statamic-polls', ['collection' => $collection]),
            'createUrl' => Gate::allows('create', [EntryContract::class, Collection::find($collection)])
                ? cp_route('collections.entries.create', [$collection, Site::default()->handle()])
                : null,
            't' => $this->translations(),
        ]);
    }

    /**
     * The JSON the Listing component asks for. Filters (status, group, site) are Statamic
     * scope filters applied to an entry query, exactly like entry listings; search, the
     * votes sort and pagination are done here.
     */
    public function listing(FilteredRequest $request): JsonResponse
    {
        $this->authorize('view polls');

        $collection = $this->polls->collection();
        $query = Entry::query()->where('collection', $collection);
        $filters = collect($request->filters);

        $badges = $this->queryFilters($query, $filters->all(), ['collection' => $collection]);

        if (! $filters->has('poll_site')) {
            $query->where('site', Site::selected()->handle());
        }

        $search = mb_strtolower(trim((string) $request->string('search')));
        $sort = in_array($request->input('sort'), ['title', 'status', 'opens_at', 'closes_at', 'votes'], true) ? $request->input('sort') : 'opens_at';
        $descending = $request->input('order', 'desc') === 'desc';
        $perPage = max(1, min(100, $request->integer('perPage', 25)));
        $page = max(1, $request->integer('page', 1));

        $rows = $query->get()
            ->map(fn (StatamicEntry $entry): Poll => Poll::fromEntry($entry))
            ->filter(fn (Poll $poll): bool => $search === '' || str_contains(mb_strtolower($poll->title()), $search))
            ->map(fn (Poll $poll): array => $this->row($poll))
            ->sortBy(fn (array $row): string|int => match ($sort) {
                'votes' => $row['votes'],
                'opens_at', 'closes_at' => $row[$sort.'_sort'],
                default => mb_strtolower((string) $row[$sort]),
            }, SORT_NATURAL, $descending)
            ->values();

        $total = $rows->count();
        $offset = ($page - 1) * $perPage;

        return response()->json([
            'data' => $rows->slice($offset, $perPage)->values()->all(),
            'meta' => [
                'columns' => $this->columns(),
                'activeFilterBadges' => $badges,
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'from' => $total > 0 ? $offset + 1 : null,
                'to' => $total > 0 ? min($offset + $perPage, $total) : null,
            ],
        ]);
    }

    public function show(string $poll): Response
    {
        $this->authorize('view polls');

        $poll = $this->findOrFail($poll);
        $results = $this->polls->results($poll);
        $badge = self::badge($poll->status());

        return Inertia::render('statamic-polls::Show', [
            'poll' => [
                'id' => $poll->id(),
                'title' => $poll->title(),
                'status_label' => $badge['label'],
                'status_color' => $badge['color'],
                'closed' => $poll->status() === PollStatus::Closed,
                'opens_at' => $this->formatDate($poll->opensAt()),
                'closes_at' => $this->formatDate($poll->closesAt()),
                'total' => $results->total(),
                'index_url' => cp_route('statamic-polls.index'),
                'edit_url' => $poll->entry()->editUrl(),
                'export_url' => cp_route('statamic-polls.export', $poll->id()),
            ],
            'results' => collect($results->options())->sortByDesc('votes')->values()->all(),
            'frozen' => $poll->frozenResults() !== null,
            'votesPerIp' => collect($this->polls->votesPerIp($poll))
                ->map(fn (int $count, string $ip): array => [
                    'ip' => $ip,
                    'short' => substr($ip, 0, 12).'…',
                    'count' => $count,
                    'void_url' => cp_route('statamic-polls.void-ip', [$poll->id(), $ip]),
                ])
                ->values()
                ->all(),
            'canVoid' => Gate::allows('void poll votes'),
            't' => $this->translations(),
        ]);
    }

    public function export(string $poll): StreamedResponse
    {
        $this->authorize('view polls');

        $poll = $this->findOrFail($poll);
        $results = $this->polls->results($poll);
        $filename = str($poll->title())->slug()->append('-results.csv')->toString();

        return response()->streamDownload(function () use ($results): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [__('statamic-polls::cp.option'), __('statamic-polls::cp.votes'), '%'], escape: '');

            foreach ($results->options() as $option) {
                fputcsv($out, [$option['label'], $option['votes'], $option['percent']], escape: '');
            }

            fputcsv($out, [__('statamic-polls::cp.total'), $results->total(), 100], escape: '');
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function voidIp(string $poll, string $ip): RedirectResponse
    {
        $this->authorize('void poll votes');

        $poll = $this->findOrFail($poll);
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $ip) === 1, 404);

        $removed = $this->polls->voidIp($poll, $ip);

        return redirect()
            ->to(cp_route('statamic-polls.show', $poll->id()))
            ->with('success', trans_choice('statamic-polls::cp.voided', $removed, ['count' => $removed]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Poll $poll): array
    {
        $badge = self::badge($poll->status());

        return [
            'id' => $poll->id(),
            'title' => $poll->title(),
            'status' => $poll->status()->value,
            'status_label' => $badge['label'],
            'status_color' => $badge['color'],
            'opens_at' => $this->formatDate($poll->opensAt()),
            'opens_at_sort' => $poll->opensAt()?->getTimestamp() ?? 0,
            'closes_at' => $this->formatDate($poll->closesAt()),
            'closes_at_sort' => $poll->closesAt()?->getTimestamp() ?? 0,
            'votes' => $this->polls->results($poll)->total(),
            'show_url' => cp_route('statamic-polls.show', $poll->id()),
            'edit_url' => $poll->entry()->editUrl(),
        ];
    }

    /**
     * @return array<int, array{field: string, label: string, sortable: bool, visible: bool}>
     */
    protected function columns(): array
    {
        return collect(['title' => 'poll', 'status' => 'status', 'opens_at' => 'opens', 'closes_at' => 'closes', 'votes' => 'votes'])
            ->map(fn (string $label, string $field): array => [
                'field' => $field,
                'label' => __('statamic-polls::cp.'.$label),
                'sortable' => true,
                'visible' => true,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    protected function translations(): array
    {
        $lines = trans('statamic-polls::cp');

        return is_array($lines) ? array_filter($lines, 'is_string') : [];
    }

    protected function formatDate(?CarbonImmutable $date): ?string
    {
        return $date?->setTimezone(config('statamic.system.display_timezone') ?: config('app.timezone'))->format('d/m/Y H:i');
    }

    protected function findOrFail(string $id): Poll
    {
        $poll = $this->polls->find($id);
        abort_unless($poll !== null, 404);

        return Poll::fromEntry($poll->root());
    }

    /**
     * @return array{color: string, label: string}
     */
    public static function badge(PollStatus $status): array
    {
        return match ($status) {
            PollStatus::Scheduled => ['color' => 'amber', 'label' => __('statamic-polls::cp.status_scheduled')],
            PollStatus::Open => ['color' => 'green', 'label' => __('statamic-polls::cp.status_open')],
            PollStatus::Closed => ['color' => 'gray', 'label' => __('statamic-polls::cp.status_closed')],
        };
    }
}
