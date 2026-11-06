<?php

namespace Aldanux\StatamicPolls\Filters;

use Carbon\CarbonImmutable;
use Statamic\Query\Scopes\Filter;

/**
 * Scheduled / open / closed, from the poll's dates (stored "Y-m-d H:i" in the app
 * timezone, so string comparison against "now" in the same format is exact).
 */
class PollStatus extends Filter
{
    protected $pinned = true;

    public static function title(): string
    {
        return __('statamic-polls::cp.status');
    }

    public function fieldItems(): array
    {
        return [
            'status' => [
                'type' => 'radio',
                'options' => [
                    'scheduled' => __('statamic-polls::cp.status_scheduled'),
                    'open' => __('statamic-polls::cp.status_open'),
                    'closed' => __('statamic-polls::cp.status_closed'),
                ],
            ],
        ];
    }

    public function apply($query, $values): void
    {
        $now = CarbonImmutable::now(config('app.timezone'))->format('Y-m-d H:i');

        match ($values['status'] ?? null) {
            'scheduled' => $query->where('opens_at', '>', $now),
            'open' => $query->where('opens_at', '<=', $now)->where('closes_at', '>', $now),
            'closed' => $query->where('closes_at', '<=', $now),
            default => null,
        };
    }

    public function badge($values): string
    {
        $options = $this->fieldItems()['status']['options'];

        return __('statamic-polls::cp.status').': '.($options[$values['status'] ?? ''] ?? '');
    }

    public function visibleTo($key): bool
    {
        return $key === 'statamic-polls';
    }
}
