<?php

namespace Aldanux\StatamicPolls\Filters;

use Aldanux\StatamicPolls\PollManager;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Query\Scopes\Filter;

/**
 * Which language to list the polls in, like the site filter of entry listings. Votes are
 * shared by every translation, so this only changes titles and which polls exist there.
 */
class PollSite extends Filter
{
    protected $pinned = true;

    public static function title(): string
    {
        return __('Site');
    }

    public function fieldItems(): array
    {
        return [
            'site' => [
                'display' => __('Site'),
                'type' => 'radio',
                'options' => $this->sites(),
            ],
        ];
    }

    public function autoApply(): array
    {
        return ['site' => Site::selected()->handle()];
    }

    public function apply($query, $values): void
    {
        $query->where('site', $values['site'] ?? Site::selected()->handle());
    }

    public function badge($values): string
    {
        return __('Site').': '.__(Site::get($values['site'] ?? '')?->name() ?? '');
    }

    public function visibleTo($key): bool
    {
        return $key === 'statamic-polls' && count($this->sites()) > 1;
    }

    /**
     * @return array<string, string>
     */
    protected function sites(): array
    {
        $configured = Collection::find(app(PollManager::class)->collection())?->sites() ?? collect();

        return Site::authorized()
            ->filter(fn ($site): bool => $configured->contains($site->handle()))
            ->mapWithKeys(fn ($site): array => [$site->handle() => __($site->name())])
            ->all();
    }
}
