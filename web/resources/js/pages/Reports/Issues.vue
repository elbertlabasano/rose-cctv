<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '../../layouts/AppLayout.vue';
import ClusterFilter from '../../components/ClusterFilter.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import type { Cluster } from '../../types';

interface IssueRow {
    branch_id: number;
    branch: string;
    cluster: string;
    device_id: number;
    asset_id: string;
    device: string;
    status: string;
    status_date: string | null;
    days_offline: number | null;
    remarks: string;
    reference: string | null;
    incident_id: number | null;
    issue_state: string;
    concern_on: string | null;
    resolved_on: string | null;
}
interface Report {
    date: string;
    today: string;
    generated_at: string;
    filters: { cluster_id: string; branch_id: string; include_resolved: boolean };
    branches: { id: number; name: string; cluster_id: number | null }[];
    clusters: Cluster[];
    rows: IssueRow[];
    summary: { branches: number; devices: number; issues: number };
}
const props = defineProps<{ report: Report; errors?: Record<string, string> }>();
const date = ref(props.report.date);
const cluster = ref(props.report.filters.cluster_id);
const branch = ref(props.report.filters.branch_id);
const includeResolved = ref(props.report.filters.include_resolved);
const loading = ref(false);
const branches = computed(() =>
    props.report.branches.filter((item) => {
        if (cluster.value === 'all') return true;
        if (cluster.value === 'clustered') return item.cluster_id !== null;
        if (cluster.value === 'unassigned') return item.cluster_id === null;
        return item.cluster_id === Number(cluster.value);
    }),
);
watch(cluster, () => {
    if (!branches.value.some((item) => item.id === Number(branch.value))) branch.value = 'all';
});
watch(
    () => props.report,
    (value) => {
        date.value = value.date;
        cluster.value = value.filters.cluster_id;
        branch.value = value.filters.branch_id;
        includeResolved.value = value.filters.include_resolved;
    },
);
const applied = computed(
    () =>
        new URLSearchParams({
            date: props.report.date,
            cluster_id: props.report.filters.cluster_id,
            ...(props.report.filters.branch_id !== 'all'
                ? { branch_id: props.report.filters.branch_id }
                : {}),
            include_resolved: props.report.filters.include_resolved ? '1' : '0',
        }),
);
const exportUrl = (format: string) =>
    `/reports/issues?${applied.value.toString()}&format=${format}`;
const scopeLabel = computed(() => {
    const branchName = props.report.branches.find(
        (item) => item.id === Number(props.report.filters.branch_id),
    )?.name;
    const value = props.report.filters.cluster_id;
    const clusterName = props.report.clusters.find((item) => item.id === Number(value))?.name;
    return (
        branchName ||
        clusterName ||
        ({
            all: 'All assigned branches',
            clustered: 'Assigned clusters',
            unassigned: 'Unassigned branches',
        }[value] ??
            'Selected scope')
    );
});
function generate() {
    loading.value = true;
    router.get(
        '/reports/issues',
        {
            date: date.value,
            cluster_id: cluster.value,
            ...(branch.value !== 'all' ? { branch_id: branch.value } : {}),
            include_resolved: includeResolved.value ? '1' : '0',
        },
        {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => {
                loading.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="Branch Issues Report" />
    <AppLayout
        section="reports"
        title="Branch Issues Report"
    >
        <header class="rw-page-heading">
            <div>
                <span class="form-eyebrow">CCTV OPERATION MONITORING</span>
                <h1>Branches that need attention</h1>
                <p>
                    Offline equipment and recorded concerns, including issues on cameras that are
                    online.
                </p>
            </div>
            <Link
                href="/reports"
                class="rw-btn"
                >All reports</Link
            >
        </header>
        <article class="rw-card issue-report">
            <form
                class="issue-filters"
                @submit.prevent="generate"
            >
                <label
                    >Report date<input
                        v-model="date"
                        type="date"
                        :max="report.today"
                        required
                /></label>
                <ClusterFilter
                    v-model="cluster"
                    :clusters="report.clusters"
                />
                <label
                    >Branch<select v-model="branch">
                        <option value="all">All assigned branches</option>
                        <option
                            v-for="item in branches"
                            :key="item.id"
                            :value="String(item.id)"
                        >
                            {{ item.name }}
                        </option>
                    </select></label
                >
                <label class="issue-checkbox"
                    ><input
                        v-model="includeResolved"
                        type="checkbox"
                    />Include concerns resolved on this date</label
                >
                <button
                    class="rw-btn primary"
                    :disabled="loading"
                    type="submit"
                >
                    {{ loading ? 'Generating…' : 'Generate report' }}
                </button>
                <div
                    v-if="errors && Object.keys(errors).length"
                    class="issue-errors"
                    role="alert"
                >
                    <p
                        v-for="message in errors"
                        :key="message"
                    >
                        {{ message }}
                    </p>
                </div>
            </form>
            <div class="issue-report-heading">
                <div>
                    <h2>{{ report.date }} · Branch Issues Report</h2>
                    <p>
                        {{ scopeLabel }} · {{ report.summary.branches }} branches ·
                        {{ report.summary.devices }} devices · {{ report.summary.issues }} concerns
                    </p>
                    <small>Generated {{ report.generated_at }}</small>
                </div>
                <div class="rw-inline-actions">
                    <a
                        class="rw-btn"
                        :href="exportUrl('csv')"
                        >Download CSV</a
                    ><a
                        class="rw-btn"
                        :href="exportUrl('html')"
                        target="_blank"
                        rel="noopener noreferrer"
                        >Print / Save PDF</a
                    >
                </div>
            </div>
            <p class="issue-table-hint">Scroll sideways to see status, remarks and dates.</p>
            <div
                class="rw-table-scroll"
                role="region"
                aria-label="Branch issues table"
                tabindex="0"
            >
                <table class="rw-table issue-table">
                    <caption class="rw-sr-only">
                        Branches with recorded issues as of
                        {{
                            report.date
                        }}
                    </caption>
                    <thead>
                        <tr>
                            <th>Branch / device</th>
                            <th>Cluster</th>
                            <th>Status</th>
                            <th>Days offline</th>
                            <th>Remarks</th>
                            <th>Date of concern</th>
                            <th>Date resolved</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, index) in report.rows"
                            :key="`${row.device_id}-${row.incident_id || index}`"
                        >
                            <td>
                                <strong>{{ row.branch }}</strong
                                ><Link :href="`/devices/${row.device_id}`"
                                    >{{ row.asset_id }} · {{ row.device }}</Link
                                >
                            </td>
                            <td>{{ row.cluster }}</td>
                            <td>
                                <StatusBadge :status="row.status" /><small
                                    v-if="row.status_date && row.status_date !== report.date"
                                    >Last recorded {{ row.status_date }}</small
                                ><small v-else-if="!row.status_date">No recorded status</small>
                            </td>
                            <td>
                                {{
                                    row.days_offline ?? (row.status === 'offline' ? 'Unknown' : '—')
                                }}
                            </td>
                            <td class="issue-remarks">
                                <p>{{ row.remarks }}</p>
                                <Link
                                    v-if="row.incident_id"
                                    :href="`/incidents?incident_id=${row.incident_id}`"
                                    >{{ row.reference }} ·
                                    {{ row.issue_state.replaceAll('_', ' ') }}</Link
                                >
                            </td>
                            <td>{{ row.concern_on || 'Unknown' }}</td>
                            <td>{{ row.resolved_on || '—' }}</td>
                        </tr>
                        <tr v-if="!report.rows.length">
                            <td
                                colspan="7"
                                class="issue-empty"
                            >
                                <strong>No recorded issues in this scope</strong>
                                <p>
                                    This is based on saved observations and incidents. It does not
                                    mean every camera has been checked.
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer class="issue-report-notes">
                <p>
                    Status is the latest recorded state on or before the report date. A
                    carried-forward status is labeled with its recorded date.
                </p>
                <p>
                    Days offline counts calendar days inclusively from a recorded outage start.
                    Missing starts stay Unknown. Date of concern is when the incident was recorded,
                    or the outage start for a status-only issue.
                </p>
                <p>
                    Incident history is read as of the selected day. Healthy equipment without a
                    recorded concern is excluded. One branch can have several device or incident
                    rows.
                </p>
            </footer>
        </article>
    </AppLayout>
</template>

<style scoped>
.issue-filters {
    display: flex;
    align-items: end;
    gap: 16px;
    flex-wrap: wrap;
    padding: 20px;
    border-bottom: 1px solid var(--line);
}
.issue-filters > label {
    display: flex;
    flex-direction: column;
    gap: 7px;
    font-size: 12px;
    color: #715d55;
}
.issue-filters input[type='date'],
.issue-filters select,
.issue-filters :deep(select) {
    min-height: 44px;
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 10px 12px;
    background: white;
    color: #542b25;
    font: inherit;
}
.issue-filters .issue-checkbox {
    flex-direction: row;
    align-items: center;
    gap: 9px;
    min-height: 44px;
    max-width: 235px;
}
.issue-checkbox input {
    width: 17px;
    height: 17px;
    flex: none;
    accent-color: #b93229;
}
.issue-report-heading {
    padding: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 20px;
    background: #fff8f1;
}
.issue-report-heading h2 {
    margin: 0 0 8px;
    font-size: 20px;
}
.issue-report-heading p {
    color: #715d55;
    margin: 0 0 8px;
}
.issue-report-heading small {
    color: #715d55;
}
.issue-table {
    min-width: 1040px;
}
.issue-table-hint {
    display: none;
    margin: 0;
    padding: 12px 20px;
    color: #715d55;
    border-top: 1px solid var(--line);
}
.issue-table td {
    vertical-align: top;
}
.issue-table td > a,
.issue-table td > small {
    display: block;
    margin-top: 6px;
}
.issue-table td > small {
    max-width: 150px;
    color: #715d55;
}
.issue-remarks {
    min-width: 280px;
    max-width: 460px;
    white-space: normal;
}
.issue-remarks p {
    margin: 0 0 8px;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    line-height: 1.6;
}
.issue-empty {
    padding: 32px !important;
    text-align: center;
}
.issue-report-notes {
    padding: 20px 24px;
    border-top: 1px solid var(--line);
    color: #715d55;
}
.issue-report-notes p {
    line-height: 1.7;
    margin: 0 0 8px;
}
.issue-report-notes p:last-child {
    margin-bottom: 0;
}
.issue-errors {
    flex-basis: 100%;
    color: #a42d24;
}
.issue-errors p {
    margin: 0;
}
@media (max-width: 600px) {
    .issue-filters > label:not(.issue-checkbox) {
        width: 100%;
    }
    .issue-filters .issue-checkbox {
        max-width: none;
    }
    .issue-report-heading {
        padding: 20px;
    }
}
@media (max-width: 1100px) {
    .issue-table-hint {
        display: block;
    }
}
</style>
