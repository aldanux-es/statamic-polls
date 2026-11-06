<?php

namespace Aldanux\StatamicPolls\Options;

use Statamic\Assets\Asset as StatamicAsset;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Asset;
use Statamic\Facades\Entry;

/**
 * One answer of a poll. The key is stable ("entry:{id}" or "text:{row id}"), so votes keep
 * pointing at the right answer when editors reorder or reword the options.
 */
final readonly class PollOption
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $entryId = null,
        public ?string $assetId = null,
        public bool $customLabel = false,
    ) {}

    /**
     * @param  array{key: string, label: string, entry?: string|null, asset?: string|null, custom_label?: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['key'], $data['label'], $data['entry'] ?? null, $data['asset'] ?? null, (bool) ($data['custom_label'] ?? false));
    }

    /**
     * @return array{key: string, label: string, entry: string|null, asset: string|null, custom_label: bool}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'entry' => $this->entryId,
            'asset' => $this->assetId,
            'custom_label' => $this->customLabel,
        ];
    }

    /**
     * The linked entry in the given site, falling back to the original when it isn't
     * translated. Null when the entry was deleted after the poll opened.
     */
    public function entry(?string $site = null): ?StatamicEntry
    {
        if ($this->entryId === null) {
            return null;
        }

        $entry = Entry::find($this->entryId);

        if (! $entry instanceof StatamicEntry) {
            return null;
        }

        $localized = $site ? $entry->in($site) : null;

        return $localized instanceof StatamicEntry ? $localized : $entry;
    }

    public function image(): ?StatamicAsset
    {
        $asset = $this->assetId ? Asset::find($this->assetId) : null;

        return $asset instanceof StatamicAsset ? $asset : null;
    }

    /**
     * @return array{key: string, label: string, entry: StatamicEntry|null, image: StatamicAsset|null}
     */
    public function toTemplateData(?string $site = null): array
    {
        $entry = $this->entry($site);
        $label = $this->customLabel || $entry === null ? $this->label : (string) $entry->value('title');

        return [
            'key' => $this->key,
            'label' => $label,
            'entry' => $entry,
            'image' => $this->image(),
        ];
    }
}
