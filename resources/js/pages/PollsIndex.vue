<script setup>
import { Head, Link } from '@statamic/cms/inertia';
import { Badge, Button, Header, Listing } from '@statamic/cms/ui';

defineProps({
    endpoint: { type: String, required: true },
    columns: { type: Array, required: true },
    filters: { type: Array, default: () => [] },
    createUrl: { type: String, default: null },
    t: { type: Object, required: true },
});
</script>

<template>
    <Head :title="t.title" />

    <div class="max-w-page mx-auto">
        <Header :title="t.title" icon="charts-donut-graph">
            <Button v-if="createUrl" :href="createUrl" :text="t.create" variant="primary" />
        </Header>

        <Listing
            :url="endpoint"
            :columns="columns"
            :filters="filters"
            sort-column="opens_at"
            sort-direction="desc"
            :allow-bulk-actions="false"
            :allow-presets="false"
            :allow-customizing-columns="false"
            preferences-prefix="statamic-polls"
        >
            <template #cell-title="{ row, value }">
                <Link :href="row.show_url" class="font-medium">{{ value }}</Link>
            </template>

            <template #cell-status="{ row }">
                <Badge :color="row.status_color" :text="row.status_label" />
            </template>

            <template #cell-votes="{ value }">
                <span class="tabular-nums">{{ value }}</span>
            </template>
        </Listing>
    </div>
</template>
