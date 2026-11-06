<script setup>
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Badge,
    Button,
    Card,
    Description,
    Header,
    Heading,
    Panel,
    PanelHeader,
    Table,
    TableCell,
    TableColumn,
    TableColumns,
    TableRow,
    TableRows,
} from '@statamic/cms/ui';

const props = defineProps({
    poll: { type: Object, required: true },
    results: { type: Array, required: true },
    frozen: { type: Boolean, default: false },
    votesPerIp: { type: Array, default: () => [] },
    canVoid: { type: Boolean, default: false },
    t: { type: Object, required: true },
});

function voidIp(row) {
    if (! window.confirm(props.t.void_confirm)) {
        return;
    }

    router.delete(row.void_url, { preserveScroll: true });
}
</script>

<template>
    <Head :title="[poll.title, t.title]" />

    <div class="max-w-page mx-auto">
        <Header :title="poll.title" icon="charts-donut-graph">
            <Button :href="poll.index_url" :text="t.back" variant="ghost" />
            <Button :href="poll.edit_url" :text="t.edit" />
            <Button :href="poll.export_url" :text="t.export" variant="primary" />
        </Header>

        <div class="space-y-6">
            <Panel>
                <PanelHeader>
                    <Heading>{{ t.summary }}</Heading>
                </PanelHeader>
                <Card>
                    <div class="flex flex-wrap gap-2">
                        <Badge :color="poll.status_color" :text="poll.status_label" />
                        <Badge :prepend="t.opens" :text="poll.opens_at" />
                        <Badge :prepend="t.closes" :text="poll.closes_at" />
                        <Badge :prepend="t.votes" :text="String(poll.total)" />
                    </div>
                </Card>
            </Panel>

            <Panel>
                <PanelHeader>
                    <Heading>{{ t.results }}</Heading>
                </PanelHeader>
                <Card>
                    <Description class="mb-3">
                        {{ frozen ? t.results_frozen : t.results_live }}
                    </Description>
                    <Table>
                        <TableColumns>
                            <TableColumn>{{ t.option }}</TableColumn>
                            <TableColumn>{{ t.votes }}</TableColumn>
                            <TableColumn>%</TableColumn>
                        </TableColumns>
                        <TableRows>
                            <TableRow v-for="option in results" :key="option.key">
                                <TableCell>
                                    <div class="flex items-center gap-2">
                                        <span>{{ option.label }}</span>
                                        <Badge v-if="poll.closed && option.is_winner" color="green" size="sm" :text="t.winner" />
                                    </div>
                                </TableCell>
                                <TableCell class="tabular-nums">{{ option.votes }}</TableCell>
                                <TableCell class="tabular-nums">{{ option.percent }}%</TableCell>
                            </TableRow>
                        </TableRows>
                    </Table>
                </Card>
            </Panel>

            <Panel v-if="votesPerIp.length">
                <PanelHeader>
                    <Heading>{{ t.by_ip }}</Heading>
                </PanelHeader>
                <Card>
                    <Description class="mb-3">{{ t.by_ip_help }}</Description>
                    <Table>
                        <TableColumns>
                            <TableColumn>{{ t.ip }}</TableColumn>
                            <TableColumn>{{ t.votes }}</TableColumn>
                            <TableColumn />
                        </TableColumns>
                        <TableRows>
                            <TableRow v-for="row in votesPerIp" :key="row.ip">
                                <TableCell><code>{{ row.short }}</code></TableCell>
                                <TableCell class="tabular-nums">{{ row.count }}</TableCell>
                                <TableCell class="text-end">
                                    <Button
                                        v-if="canVoid"
                                        size="sm"
                                        variant="danger"
                                        :text="t.void"
                                        @click="voidIp(row)"
                                    />
                                </TableCell>
                            </TableRow>
                        </TableRows>
                    </Table>
                </Card>
            </Panel>
        </div>
    </div>
</template>
