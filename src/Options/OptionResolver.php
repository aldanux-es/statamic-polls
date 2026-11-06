<?php

namespace Aldanux\StatamicPolls\Options;

use Illuminate\Support\Collection;
use Statamic\Assets\Asset as StatamicAsset;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use Statamic\Fieldtypes\Replicator;

/**
 * Turns the poll's `options` replicator into a flat list of answers. Three kinds of set:
 *
 * - entry: one hand-picked entry (e.g. a player), with an optional custom label.
 * - query: every published entry of a collection matching some conditions
 *   (e.g. players where active = true), resolved when the poll opens.
 * - text:  a plain label with an optional image.
 *
 * Duplicates (an entry picked by hand and also matched by a query) are kept once.
 */
class OptionResolver
{
    /**
     * @return Collection<int, PollOption>
     */
    public function resolve(StatamicEntry $poll): Collection
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $poll->get('options') ?? [];

        return collect($rows)
            ->filter(fn (array $row): bool => ($row['enabled'] ?? true) !== false)
            ->flatMap(fn (array $row): array => match ($row['type'] ?? null) {
                'entry' => $this->fromEntryRow($row),
                'query' => $this->fromQueryRow($poll, $row),
                'text' => $this->fromTextRow($poll, $row),
                default => [],
            })
            ->unique(fn (PollOption $option): string => $option->key)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, PollOption>
     */
    protected function fromEntryRow(array $row): array
    {
        $id = collect((array) ($row['entry'] ?? []))->first();
        $entry = is_string($id) ? Entry::find($id) : null;

        if (! $entry instanceof StatamicEntry || $entry->status() !== 'published') {
            return [];
        }

        $root = $entry->hasOrigin() ? $entry->root() : $entry;
        $entry = $root instanceof StatamicEntry ? $root : $entry;

        $label = trim((string) ($row['label'] ?? ''));

        return [new PollOption(
            key: 'entry:'.$entry->id(),
            label: $label !== '' ? $label : (string) $entry->value('title'),
            entryId: $entry->id(),
            customLabel: $label !== '',
        )];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, PollOption>
     */
    protected function fromQueryRow(StatamicEntry $poll, array $row): array
    {
        $collection = collect((array) ($row['collection'] ?? []))->first();

        if (! is_string($collection) || $collection === '') {
            return [];
        }

        // Only the entries of the poll's own site: translations are the same answer.
        $query = Entry::query()
            ->where('collection', $collection)
            ->where('site', $poll->locale())
            ->whereStatus('published');

        foreach ((array) ($row['conditions'] ?? []) as $condition) {
            $field = trim((string) ($condition['field'] ?? ''));

            if ($field === '') {
                continue;
            }

            $operator = in_array($condition['operator'] ?? '=', ['=', '!=', '>', '<', '>=', '<='], true) ? $condition['operator'] : '=';
            $query->where($field, $operator, $this->castConditionValue($condition['value'] ?? null));
        }

        [$orderField, $orderDirection] = array_pad(explode(':', (string) ($row['order_by'] ?? '')), 2, 'asc');

        if ($orderField !== '') {
            $query->orderBy($orderField, $orderDirection === 'desc' ? 'desc' : 'asc');
        }

        if ((int) ($row['limit'] ?? 0) > 0) {
            $query->limit((int) $row['limit']);
        }

        return $query->get()
            ->map(fn (StatamicEntry $entry): PollOption => new PollOption(
                key: 'entry:'.$entry->id(),
                label: (string) $entry->value('title'),
                entryId: $entry->id(),
            ))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, PollOption>
     */
    protected function fromTextRow(StatamicEntry $poll, array $row): array
    {
        $label = trim((string) ($row['label'] ?? ''));

        if ($label === '') {
            return [];
        }

        $rowId = $row['id'] ?? null;

        return [new PollOption(
            key: 'text:'.(is_string($rowId) && $rowId !== '' ? $rowId : substr(md5($label), 0, 12)),
            label: $label,
            assetId: $this->assetId($poll, $row['image'] ?? null),
            customLabel: true,
        )];
    }

    /**
     * Resolves the raw asset path through the set's own field config, so the container
     * configured in the blueprint is respected.
     */
    protected function assetId(StatamicEntry $poll, mixed $raw): ?string
    {
        if (empty($raw)) {
            return null;
        }

        $fieldtype = $poll->blueprint()?->field('options')?->fieldtype();

        if (! $fieldtype instanceof Replicator) {
            return null;
        }

        $field = $fieldtype->fields('text')->get('image');

        if (! $field) {
            return null;
        }

        $value = $field->setValue($raw)->augment()->value();
        $value = $value instanceof StatamicAsset ? $value : collect($value instanceof Collection ? $value : ($value?->get() ?? []))->first();

        return $value instanceof StatamicAsset ? $value->id() : null;
    }

    protected function castConditionValue(mixed $value): mixed
    {
        return match (is_string($value) ? strtolower(trim($value)) : $value) {
            'true' => true,
            'false' => false,
            'null', '' => null,
            default => $value,
        };
    }
}
