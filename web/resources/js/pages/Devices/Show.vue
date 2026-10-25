<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type { DeviceDashboardData } from '../../device-dashboard';
import type { Evidence, Observation } from '../../types';
import AppLayout from '../../layouts/AppLayout.vue';
import AppModal from '../../components/AppModal.vue';
import DeviceTrends from '../../components/DeviceTrends.vue';
import HistoryPagination from '../../components/HistoryPagination.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import FormErrors from '../../components/FormErrors.vue';

const props = defineProps<{ dashboard: DeviceDashboardData }>();

const period = useForm({ from: props.dashboard.period.from, to: props.dashboard.period.to });
const record = ref<Observation | null>(null);
const preview = ref<Evidence | null>(null);

watch(
    () => props.dashboard.period,
    (value) => {
        period.from = value.from;
        period.to = value.to;
    },
);

const tabs = computed(() => [
    { id: 'overview', label: 'Overview', count: null },
    {
        id: 'status',
        label: 'Status history',
        count: props.dashboard.counts.status + props.dashboard.counts.status_changes,
    },
    { id: 'tickets', label: 'Tickets', count: props.dashboard.counts.tickets },
    { id: 'incidents', label: 'Incidents', count: props.dashboard.counts.incidents },
    { id: 'evidence', label: 'Evidence', count: props.dashboard.counts.evidence },
    { id: 'outages', label: 'Outages', count: props.dashboard.counts.outages },
    ...(props.dashboard.can_audit
        ? [{ id: 'activity', label: 'Device changes', count: props.dashboard.counts.activity || 0 }]
        : []),
]);

const currentPage = computed(() => Object.values(props.dashboard.history)[0] || null);
const currentTitle = computed(
    () => tabs.value.find((tab) => tab.id === props.dashboard.tab)?.label || 'History',
);

function tabUrl(tab: string) {
    return `/devices/${props.dashboard.device.id}?${new URLSearchParams({ ...props.dashboard.period, tab })}`;
}

function applyPeriod() {
    period.get(`/devices/${props.dashboard.device.id}`, {
        preserveScroll: true,
        preserveState: true,
    });
}

function quickPeriod(days: number) {
    const start = new Date(`${props.dashboard.today}T12:00:00Z`);
    start.setUTCDate(start.getUTCDate() - days + 1);
    period.from = start.toISOString().slice(0, 10);
    period.to = props.dashboard.today;
    applyPeriod();
}

function dateLabel(value: string) {
    return new Date(`${value}T12:00:00Z`).toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    });
}

function timeLabel(value: string | null) {
    return value
        ? `${new Date(value).toLocaleString('en-GB', { timeZone: 'Asia/Manila', day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })} PHT`
        : 'Not recorded';
}

function duration(minutes: number) {
    const rounded = Math.round(minutes);
    return rounded >= 60 ? `${Math.floor(rounded / 60)}h ${rounded % 60}m` : `${rounded}m`;
}
</script>

<template>
    <Head :title="`${dashboard.device.asset_id} · Device dashboard`" />
    <AppLayout
        section="devices"
        :title="dashboard.device.name"
    >
        <div class="rw-page-heading">
            <div>
                <Link
                    href="/devices"
                    class="dd-back"
                    >&larr; Device inventory</Link
                >
                <h1>{{ dashboard.device.name }}</h1>
                <p>
                    {{ dashboard.device.asset_id }} &middot;
                    {{ dashboard.device.branch.name }} &middot; {{ dashboard.device.type }}
                </p>
                <div class="dd-current">
                    <StatusBadge :status="dashboard.device.status" /><span
                        >Last status recorded:
                        {{ timeLabel(dashboard.device.status_observed_at) }}</span
                    >
                </div>
            </div>
            <Link
                v-if="dashboard.can_check"
                :href="`/checklist?branch_id=${dashboard.device.branch_id}&device_id=${dashboard.device.id}`"
                class="rw-btn primary"
                >Open today's checklist</Link
            >
        </div>

        <div class="rw-metrics dd-metrics">
            <article class="rw-card">
                <span>Daily records</span><strong>{{ dashboard.counts.status }}</strong
                ><small>All saved dates</small>
            </article>
            <article class="rw-card">
                <span>Ticket requests</span><strong>{{ dashboard.counts.tickets }}</strong
                ><small>{{ dashboard.counts.confirmed_tickets }} confirmed by provider</small>
            </article>
            <article class="rw-card">
                <span>Active incidents</span><strong>{{ dashboard.counts.active_incidents }}</strong
                ><small>{{ dashboard.counts.incidents }} total incidents</small>
            </article>
            <article class="rw-card">
                <span>Evidence files</span><strong>{{ dashboard.counts.evidence }}</strong
                ><small>Daily records and incidents</small>
            </article>
        </div>

        <article class="rw-card dd-workspace">
            <nav
                class="dd-tabs"
                aria-label="Device dashboard sections"
            >
                <Link
                    v-for="tab in tabs"
                    :key="tab.id"
                    :href="tabUrl(tab.id)"
                    :class="{ active: dashboard.tab === tab.id }"
                    :aria-current="dashboard.tab === tab.id ? 'page' : undefined"
                    preserve-scroll
                    >{{ tab.label }}<span v-if="tab.count !== null">{{ tab.count }}</span></Link
                >
            </nav>

            <template v-if="dashboard.tab === 'overview'">
                <form
                    class="dd-period"
                    @submit.prevent="applyPeriod"
                    aria-label="Chart period"
                >
                    <strong>Chart period</strong>
                    <div class="rw-inline-actions">
                        <button
                            v-for="days in [7, 30, 90]"
                            :key="days"
                            class="rw-btn"
                            type="button"
                            :disabled="period.processing"
                            @click="quickPeriod(days)"
                        >
                            {{ days }} days
                        </button>
                    </div>
                    <label
                        >From<input
                            v-model="period.from"
                            type="date"
                            :max="dashboard.today"
                            required
                    /></label>
                    <label
                        >To<input
                            v-model="period.to"
                            type="date"
                            :min="period.from"
                            :max="dashboard.today"
                            required
                    /></label>
                    <button
                        type="submit"
                        class="rw-btn"
                        :disabled="period.processing"
                    >
                        {{ period.processing ? 'Updating...' : 'Apply' }}
                    </button>
                    <FormErrors :errors="period.errors" />
                </form>
                <div class="dd-overview">
                    <DeviceTrends
                        :days="dashboard.days"
                        @inspect="record = $event"
                    />
                    <aside
                        class="dd-facts"
                        aria-label="Device information"
                    >
                        <h2>Device information</h2>
                        <dl class="dd-specs">
                            <div
                                v-for="[label, value] in [
                                    ['Location', dashboard.device.location],
                                    ['Responsible team', dashboard.device.owner],
                                    [
                                        'Brand / model',
                                        [dashboard.device.brand, dashboard.device.model]
                                            .filter(Boolean)
                                            .join(' / '),
                                    ],
                                    ['Serial number', dashboard.device.serial],
                                    ['Network address', dashboard.device.ip],
                                    ['Recorder channel', dashboard.device.channel],
                                    ['Channel capacity', dashboard.device.channels],
                                    ['Storage (TB)', dashboard.device.storage_tb],
                                    ['Disk health', dashboard.device.disk_health],
                                    ['Installed', dashboard.device.installed_at],
                                    ['Warranty until', dashboard.device.warranty_at],
                                ]"
                                :key="String(label)"
                            >
                                <dt>{{ label }}</dt>
                                <dd>{{ value ?? 'Not specified' }}</dd>
                            </div>
                        </dl>
                        <p
                            v-if="dashboard.device.notes"
                            class="dd-notes"
                        >
                            {{ dashboard.device.notes }}
                        </p>
                        <h3>Connected equipment</h3>
                        <p
                            v-if="!dashboard.parent && !dashboard.children.length"
                            class="rw-note"
                        >
                            No connected equipment recorded.
                        </p>
                        <Link
                            v-if="dashboard.parent"
                            :href="`/devices/${dashboard.parent.id}`"
                            class="dd-related"
                            ><small>Connected to</small
                            ><strong>{{ dashboard.parent.asset_id }}</strong
                            ><span>{{ dashboard.parent.name }}</span></Link
                        >
                        <Link
                            v-for="child in dashboard.children"
                            :key="child.id"
                            :href="`/devices/${child.id}`"
                            class="dd-related"
                            ><strong>{{ child.asset_id }}</strong
                            ><span>{{ child.name }}</span
                            ><StatusBadge :status="child.status"
                        /></Link>
                        <p class="rw-note">
                            {{ dashboard.counts.open_outages }} recorded outage(s) awaiting
                            restoration.
                        </p>
                    </aside>
                </div>
            </template>

            <template v-else>
                <div class="dd-history-heading">
                    <h2>{{ currentTitle }}</h2>
                    <p>All saved dates for this device. Newest first.</p>
                </div>

                <section
                    v-if="dashboard.history.status_changes"
                    aria-label="Inventory status changes"
                >
                    <div class="dd-history-heading">
                        <h3>Device status changes</h3>
                        <p>
                            Changes saved through Device management, including recorders and other
                            equipment.
                        </p>
                    </div>
                    <div
                        v-for="item in dashboard.history.status_changes.data"
                        :key="item.id"
                        class="rw-list-row"
                    >
                        <div class="rw-grow">
                            <strong>{{ item.actor }}</strong
                            ><small>{{ timeLabel(item.created_at) }}</small>
                        </div>
                        <StatusBadge
                            v-if="item.from"
                            :status="item.from"
                        /><span
                            v-if="item.from"
                            aria-label="changed to"
                            >&rarr;</span
                        ><StatusBadge :status="item.to" />
                    </div>
                    <p
                        v-if="!dashboard.history.status_changes.total"
                        class="dd-history-note"
                    >
                        No inventory status changes recorded yet.
                    </p>
                    <HistoryPagination :page="dashboard.history.status_changes" />
                    <div class="dd-history-heading"><h3>Daily records</h3></div>
                </section>
                <div
                    v-if="dashboard.history.status"
                    class="rw-table-scroll"
                >
                    <table class="rw-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Saved status</th>
                                <th>Checked by</th>
                                <th>Evidence / ticket</th>
                                <th>Record</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="item in dashboard.history.status.data"
                                :key="item.id"
                            >
                                <td>{{ dateLabel(item.date) }}</td>
                                <td>
                                    <StatusBadge :status="item.checked_at ? item.status : null" />
                                </td>
                                <td>
                                    {{ item.checker || 'Not checked'
                                    }}<small>{{ timeLabel(item.checked_at) }}</small>
                                </td>
                                <td>
                                    {{ item.attachments.length }} image(s)<small v-if="item.ticket"
                                        >Ticket request: {{ item.ticket.status }}</small
                                    >
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        class="rw-btn"
                                        :aria-label="`View record for ${item.date}`"
                                        @click="record = item"
                                    >
                                        View record
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="dashboard.history.tickets">
                    <p class="dd-history-note">
                        Requests are separate from confirmed external tickets. Provider confirmation
                        is required before a ticket is marked created.
                    </p>
                    <div
                        v-for="item in dashboard.history.tickets.data"
                        :key="item.id"
                        class="rw-list-row"
                    >
                        <div class="rw-grow">
                            <strong>{{ item.title }}</strong
                            ><small
                                >Requested {{ timeLabel(item.created_at) }} &middot; Record
                                {{ dateLabel(item.observation.date) }}</small
                            >
                            <p
                                v-if="item.error"
                                class="rw-note"
                            >
                                {{ item.error }}
                            </p>
                        </div>
                        <StatusBadge :status="item.status" /><a
                            v-if="item.status === 'created' && item.external_url"
                            :href="item.external_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="rw-btn"
                            >Open {{ item.external_id }}</a
                        ><button
                            class="rw-btn"
                            type="button"
                            @click="record = item.observation"
                        >
                            Daily record
                        </button>
                    </div>
                </div>

                <div v-if="dashboard.history.incidents">
                    <section
                        v-for="item in dashboard.history.incidents.data"
                        :key="item.id"
                        class="dd-incident"
                    >
                        <div class="dd-incident-head">
                            <div>
                                <h3>{{ item.title }}</h3>
                                <p>
                                    {{ item.reference }} &middot; {{ item.category }} &middot;
                                    {{ item.priority }} priority
                                </p>
                            </div>
                            <StatusBadge :status="item.status" />
                        </div>
                        <p class="dd-notes">{{ item.description }}</p>
                        <p class="rw-note">
                            Assigned to {{ item.assignee || 'Unassigned' }} &middot; Opened
                            {{ timeLabel(item.created_at) }}
                        </p>
                        <div class="rw-inline-actions">
                            <Link
                                :href="item.url"
                                class="rw-btn"
                                >Open incident &amp; action history</Link
                            ><a
                                v-if="item.signed_document_url"
                                :href="item.signed_document_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                >Signed incident report</a
                            >
                        </div>
                    </section>
                </div>

                <div
                    v-if="dashboard.history.evidence?.data.length"
                    class="dd-evidence-grid"
                >
                    <div
                        v-for="item in dashboard.history.evidence.data"
                        :key="item.id"
                        class="dd-evidence"
                    >
                        <button
                            type="button"
                            class="rw-image-button"
                            :aria-label="`Preview ${item.name}`"
                            @click="preview = item"
                        >
                            <img
                                v-if="item.mime.startsWith('image/')"
                                :src="item.url"
                                :alt="item.name"
                                loading="lazy"
                            /><span v-else>PDF &middot; {{ item.name }}</span>
                        </button>
                        <strong>{{ item.name }}</strong>
                        <p>
                            {{ item.source }} &middot;
                            {{ item.record_date || item.incident_reference }}
                        </p>
                        <small>{{ timeLabel(item.created_at) }}</small>
                    </div>
                </div>

                <div
                    v-if="dashboard.history.outages"
                    class="rw-table-scroll"
                >
                    <table class="rw-table">
                        <thead>
                            <tr>
                                <th>Offline recorded</th>
                                <th>Restoration</th>
                                <th>Recorded duration</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="item in dashboard.history.outages.data"
                                :key="item.id"
                            >
                                <td>{{ timeLabel(item.started_at) }}</td>
                                <td>
                                    {{
                                        item.restored_at
                                            ? timeLabel(item.restored_at)
                                            : 'Restoration not confirmed'
                                    }}
                                </td>
                                <td>
                                    {{ duration(item.minutes)
                                    }}<small v-if="!item.restored_at">As of this page load</small>
                                </td>
                                <td class="dd-notes">{{ item.notes || 'No notes' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <ol
                    v-if="dashboard.history.activity"
                    class="dd-activity"
                >
                    <li
                        v-for="item in dashboard.history.activity.data"
                        :key="item.id"
                    >
                        <strong>{{ item.action }} &middot; {{ item.actor }}</strong>
                        <p>{{ item.reason }}</p>
                        <time :datetime="item.created_at">{{ timeLabel(item.created_at) }}</time>
                    </li>
                </ol>

                <div
                    v-if="!currentPage?.total"
                    class="rw-empty"
                >
                    <h3>
                        No
                        {{
                            dashboard.tab === 'status'
                                ? 'daily records'
                                : currentTitle.toLowerCase()
                        }}
                        yet
                    </h3>
                    <p>Records linked to this device will appear here when they are saved.</p>
                </div>
                <HistoryPagination
                    v-if="currentPage"
                    :page="currentPage"
                />
            </template>
        </article>

        <AppModal
            :open="!!record"
            :title="
                record ? `${dashboard.device.asset_id} · ${dateLabel(record.date)}` : 'Daily record'
            "
            wide
            @close="record = null"
        >
            <template v-if="record"
                ><StatusBadge :status="record.checked_at ? record.status : null" />
                <p class="rw-note">
                    Checked by {{ record.checker || 'Not checked' }} &middot;
                    {{ timeLabel(record.checked_at) }}
                </p>
                <p class="dd-notes">{{ record.notes || 'No notes saved for this record.' }}</p>
                <div
                    v-if="record.ticket"
                    class="rw-callout"
                >
                    <div>
                        <h3>{{ record.ticket.title }}</h3>
                        <p>Ticket request: {{ record.ticket.status }}</p>
                    </div>
                </div>
                <div class="dd-evidence-grid">
                    <button
                        v-for="item in record.attachments"
                        :key="item.id"
                        type="button"
                        class="rw-image-button"
                        :aria-label="`Preview ${item.name}`"
                        @click="preview = item"
                    >
                        <img
                            :src="item.url"
                            :alt="item.name"
                        />
                    </button>
                </div>
                <p
                    v-if="!record.attachments.length"
                    class="rw-note"
                >
                    No images attached.
                </p>
                <p class="rw-note">
                    This is a read-only history view. Use today's checklist to record new
                    observations.
                </p></template
            >
        </AppModal>
        <AppModal
            :open="!!preview"
            :title="preview?.name || 'Evidence preview'"
            wide
            @close="preview = null"
            ><template v-if="preview"
                ><img
                    v-if="preview.mime.startsWith('image/')"
                    :src="preview.url"
                    :alt="preview.name"
                    class="rw-preview-image"
                /><iframe
                    v-else
                    :src="preview.url"
                    :title="preview.name"
                    class="rw-pdf-preview"
                ></iframe>
                <div class="rw-form-actions">
                    <a
                        :href="preview.download_url"
                        class="rw-btn"
                        >Download evidence</a
                    >
                </div></template
            ></AppModal
        >
    </AppLayout>
</template>

<style scoped>
.dd-back {
    display: inline-block;
    color: #806b65;
    margin-bottom: 12px;
    text-underline-offset: 4px;
}
.dd-current {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 14px;
    color: #806b65;
    font-size: 11px;
}
.dd-metrics small {
    display: block;
    margin-top: 8px;
}
.dd-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    padding: 12px 16px 0;
    border-bottom: 1px solid #ecdcd0;
}
.dd-tabs a {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 13px 12px;
    color: #806b65;
    text-decoration: none;
    border-bottom: 3px solid transparent;
}
.dd-tabs a:hover {
    color: #5d1616;
    background: #fff8f1;
}
.dd-tabs a.active {
    color: #5d1616;
    font-weight: 550;
    border-bottom-color: #e31b17;
}
.dd-tabs span {
    font-size: 10px;
    border-radius: 5px;
    padding: 2px 5px;
    background: #f6ede4;
}
.dd-period {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    padding: 18px 22px;
    border-bottom: 1px solid #ecdcd0;
}
.dd-period > strong {
    color: #806b65;
    font-size: 12px;
}
.dd-period label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #806b65;
    min-width: 0;
}
.dd-period input {
    min-height: 38px;
    min-width: 0;
    padding: 8px 10px;
    border: 1px solid #e3d3c5;
    border-radius: 8px;
    color: #452626;
    background: #fff;
}
.dd-overview {
    display: grid;
    grid-template-columns: minmax(0, 1.7fr) minmax(240px, 1fr);
}
.dd-facts {
    padding: 22px;
    border-left: 1px solid #ecdcd0;
    min-width: 0;
}
.dd-specs {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 17px;
    margin: 22px 0;
}
.dd-specs dt {
    color: #806b65;
    font-size: 10px;
    margin-bottom: 5px;
}
.dd-specs dd {
    margin: 0;
    font-size: 12px;
    overflow-wrap: anywhere;
}
.dd-notes {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    color: #74594e;
}
.dd-related {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 5px;
    margin: 10px 0;
    padding: 10px 0;
    border-bottom: 1px solid #eaded3;
    text-decoration: none;
    overflow-wrap: anywhere;
}
.dd-related:hover strong {
    text-decoration: underline;
}
.dd-related span {
    font-size: 12px;
    color: #806b65;
}
.dd-history-heading {
    padding: 20px;
    border-bottom: 1px solid #ecdcd0;
}
.dd-history-heading p {
    color: #806b65;
    margin: 6px 0 0;
}
.dd-history-note {
    color: #806b65;
    margin: 0;
    padding: 16px 20px;
    border-bottom: 1px solid #ecdcd0;
}
.dd-incident {
    padding: 20px;
    border-bottom: 1px solid #ecdcd0;
}
.dd-incident-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}
.dd-incident-head h3 {
    margin: 0;
}
.dd-incident-head p {
    margin: 7px 0 0;
    color: #806b65;
}
.dd-evidence-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 18px;
    padding: 20px;
}
.dd-evidence {
    min-width: 0;
}
.dd-evidence img,
.dd-evidence-grid > button img {
    width: 100%;
    height: 150px;
    object-fit: contain;
    display: block;
}
.dd-evidence > strong {
    display: block;
    margin-top: 10px;
    overflow-wrap: anywhere;
    font-size: 12px;
}
.dd-evidence p {
    color: #806b65;
    margin: 6px 0;
}
.dd-activity {
    margin: 0;
    padding: 22px 22px 22px 42px;
}
.dd-activity li {
    padding: 0 0 20px 5px;
    color: #74594e;
    font-size: 13px;
}
.dd-activity p {
    white-space: pre-wrap;
}
.dd-activity time {
    font-size: 10px;
    color: #806b65;
}
@media (max-width: 1100px) {
    .dd-overview {
        grid-template-columns: minmax(0, 1fr);
    }
    .dd-facts {
        border-left: 0;
        border-top: 1px solid #ecdcd0;
    }
}
@media (max-width: 600px) {
    .dd-period {
        padding: 16px;
    }
    .dd-period label {
        flex: 1 1 100%;
    }
    .dd-period input {
        width: 100%;
        font-size: 16px;
    }
    .dd-tabs {
        padding: 8px 8px 0;
        gap: 0;
    }
    .dd-tabs a {
        padding: 12px 8px;
    }
    .dd-evidence-grid {
        grid-template-columns: minmax(0, 1fr);
        padding: 16px;
    }
    .dd-facts {
        padding: 16px;
    }
    .dd-incident-head {
        flex-wrap: wrap;
    }
}
</style>
