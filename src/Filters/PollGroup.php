<?php

namespace Aldanux\StatamicPolls\Filters;

use Aldanux\StatamicPolls\PollManager;
use Statamic\Facades\Entry;
use Statamic\Query\Scopes\Filter;

/**
 * The poll groups in use (e.g. "jugador-power"), to list one series at a time.
 */
class PollGroup extends Filter
{
    public static function title(): string
    {
        return __('statamic-polls::cp.group');
    }

    public function fieldItems(): array
    {
        return [
            'group' => [
                'type' => 'select',
                'options' => $this->groups(),
            ],
        ];
    }

    public function apply($query, $values): void
    {
        if (filled($values['group'] ?? null)) {
            $query->where('group', $values['group']);
        }
    }

    public function badge($values): string
    {
        return __('statamic-polls::cp.group').': '.($values['group'] ?? '');
    }

    public function visibleTo($key): bool
    {
        return $key === 'statamic-polls' && $this->groups() !== [];
    }

    /**
     * @return array<string, string>
     */
    protected function groups(): array
    {
        return Entry::query()
            ->where('collection', app(PollManager::class)->collection())
            ->get()
            ->map(fn ($entry): string => trim((string) $entry->get('group')))
            ->filter()
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $group): array => [$group => $group])
            ->all();
    }
}
