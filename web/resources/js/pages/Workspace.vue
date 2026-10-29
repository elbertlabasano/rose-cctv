<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch, onUnmounted } from 'vue';
import type { Workspace, Device, Observation, Incident, Account, Evidence, Branch } from '../types';
import AppLayout from '../layouts/AppLayout.vue';
import AppModal from '../components/AppModal.vue';
import FormErrors from '../components/FormErrors.vue';
import StatusBadge from '../components/StatusBadge.vue';
import DeviceEditor from '../components/DeviceEditor.vue';
import ObservationEditor from '../components/ObservationEditor.vue';
import IncidentEditor from '../components/IncidentEditor.vue';
import IncidentAction from '../components/IncidentAction.vue';
import IncidentHistory from '../components/IncidentHistory.vue';
import AccountEditor from '../components/AccountEditor.vue';
import WorkspaceFilters from '../components/WorkspaceFilters.vue';
import RegisterSummaryRows from '../components/RegisterSummaryRows.vue';
import ClusterFilter from '../components/ClusterFilter.vue';
import LiveTicketList from '../components/LiveTicketList.vue';
import QuickCameraStatus from '../components/QuickCameraStatus.vue';
import type { QuickStatusResult } from '../types';
import { branchMatchesCluster } from '../workspace-scope';
import { hasStatusCheck, observationStatus, observationLabel } from '../observation-status';

const props = defineProps<{ section: string; workspace: Workspace }>();
const registerScroll = ref<HTMLElement | null>(null);

function jumpToToday() {
    const scroll = registerScroll.value;
    const day = scroll?.querySelector<HTMLElement>('th.rw-register-day.today');
    const camera = scroll?.querySelector<HTMLElement>('th.sticky-camera');
    if (!scroll || !day || !camera) return;
    const cameraWidth = camera.getBoundingClientRect().width;
    const offset = day.getBoundingClientRect().left - scroll.getBoundingClientRect().left;
    scroll.scrollTo({
        left:
            scroll.scrollLeft +
            offset -
            cameraWidth -
            (scroll.clientWidth - cameraWidth - day.clientWidth) / 2,
        behavior: 'smooth',
    });
}

function quickStatusSaved(result: QuickStatusResult) {
    router.replaceProp('workspace', (value: unknown) => {
        const current = value as Workspace;
        const observations = current.observations.filter(
            (record) =>
                record.device_id !== result.observation.device_id ||
                record.date !== result.observation.date,
        );
        return {
            ...current,
            devices: current.devices.map((device) =>
                device.id === result.device.id ? { ...device, ...result.device } : device,
            ),
            observations: [...observations, result.observation],
            outages: [
                ...current.outages.filter((outage) => outage.device_id !== result.device.id),
                ...result.outages,
            ],
        };
    });
}

const titles: Record<string, string> = {
    overview: 'Operations overview',
    cameras: 'Camera status by day',
    checklist: 'Daily checklist',
    evidence: 'Screenshot history',
    outages: 'Outage tracking',
    branches: 'Branch dashboard',
    process: 'How operations work',
    incidents: 'Incident management',
    tickets: 'External tickets',
    reminders: 'Follow-up reminders',
    users: 'User management',
    devices: 'Device inventory',
    reports: 'Daily handover report',
};

const descriptions: Record<string, string> = {
    overview: 'A shared view of branch health and the work that needs attention.',
    cameras: 'Select a device and day to record status, attach screenshots, or request a ticket.',
    checklist: 'Review each camera, record your observation, and follow up issues.',
    evidence: 'Review private evidence by camera and date.',
    outages: 'Outages begin at a recorded offline observation and end at confirmed online status.',
    branches: 'Latest known camera states, checking progress, and active incidents.',
    process: 'Follow the same path from a camera check through verified resolution.',
    incidents: 'Record investigations, work performed, signed documents, and verification.',
    tickets: 'Requests go to your external ticketing provider. Incidents stay separate.',
    reminders:
        'Your in-app follow-up queue. Snooze an item without changing its operational state.',
    users: 'Manage roles, branch assignments, account status, and passwords.',
    devices: 'Register equipment, recorder channels, connections, and maintenance details.',
    reports:
        'Preview observations and export a daily handover with evidence and incident summaries.',
};

const requestedDeviceId = Number(new URLSearchParams(window.location.search).get('device_id'));
const requestedIncidentId =
    Number(new URLSearchParams(window.location.search).get('incident_id')) || null;

const branch = ref(new URLSearchParams(window.location.search).get('branch_id') || 'all'),
    search = ref(
        props.workspace.devices.find((device) => device.id === requestedDeviceId)?.asset_id || '',
    ),
    statusFilter = ref('all'),
    selectedIds = ref<number[]>([]),
    incidentState = ref(requestedIncidentId ? 'all' : 'active'),
    selectedIncidentId = ref<number | null>(requestedIncidentId),
    showSnoozed = ref(false);

const detailDevice = ref<Device | null>(null);

const deviceOpen = ref(false),
    editingDevice = ref<Device | null>(null),
    observationOpen = ref(false),
    observationDevices = ref<Device[]>([]),
    observationDate = ref(''),
    newIncidentOpen = ref(false),
    actionOpen = ref(false),
    incidentAction = ref('log'),
    accountOpen = ref(false),
    editingAccount = ref<Account | null>(null),
    preview = ref<Evidence | null>(null),
    branchOpen = ref(false),
    editingBranch = ref<Branch | null>(null);

const branchForm = useForm({
    name: '',
    code: '',
    address: '',
    active: true,
    cluster_id: '' as number | '',
});

const requestedCluster = new URLSearchParams(window.location.search).get('cluster_id');
const clusterFilter = ref(
    requestedCluster ||
        (props.section === 'cameras' && branch.value === 'all' && props.workspace.clusters?.length
            ? 'clustered'
            : 'all'),
);
const clusterBranches = computed(() =>
    props.workspace.branches.filter((item) => branchMatchesCluster(item, clusterFilter.value)),
);
const scopeBranchIds = computed(
    () =>
        new Set(
            clusterBranches.value
                .filter((item) => branch.value === 'all' || item.id === Number(branch.value))
                .map((item) => item.id),
        ),
);
watch(clusterFilter, () => {
    if (
        branch.value !== 'all' &&
        !clusterBranches.value.some((item) => item.id === Number(branch.value))
    )
        branch.value = 'all';
    selectedIds.value = [];
    selectedIncidentId.value = null;
});

function matchesCluster(device: Device) {
    return branchMatchesCluster(device.branch, clusterFilter.value);
}

const canActOnSelectedDate = computed(
    () => props.workspace.permissions.write && props.workspace.date === props.workspace.today,
);

const cameras = computed(() =>
    props.workspace.devices.filter((d) => ['IP Camera', 'Analog Camera'].includes(d.type)),
);

const matching = (d: Device) =>
    matchesCluster(d) &&
    (branch.value === 'all' || d.branch_id === Number(branch.value)) &&
    [d.asset_id, d.name, d.branch.name, d.location]
        .join(' ')
        .toLowerCase()
        .includes(search.value.toLowerCase());

const visibleCameras = computed(() =>
    cameras.value
        .filter(matching)
        .filter(
            (d) =>
                statusFilter.value === 'all' ||
                (statusFilter.value === 'unchecked'
                    ? !hasStatusCheck(observation(d.id, props.workspace.date))
                    : statusFilter.value === 'checked'
                      ? hasStatusCheck(observation(d.id, props.workspace.date))
                      : d.status === statusFilter.value),
        ),
);

const visibleBranches = computed(() =>
    props.workspace.branches.filter(
        (item) =>
            branchMatchesCluster(item, clusterFilter.value) &&
            (branch.value === 'all' || item.id === Number(branch.value)) &&
            [item.name, item.code, item.address, item.cluster?.name]
                .join(' ')
                .toLowerCase()
                .includes(search.value.toLowerCase()),
    ),
);

const visibleDevices = computed(() =>
    props.workspace.devices
        .filter(matching)
        .filter((d) => statusFilter.value === 'all' || d.status === statusFilter.value),
);

const observationMap = computed(
    () => new Map(props.workspace.observations.map((o) => [`${o.device_id}:${o.date}`, o])),
);

function observation(id: number, date: string) {
    return observationMap.value.get(`${id}:${date}`) || null;
}

const checklistTotal = computed(() => cameras.value.filter(matching).length);

const checkedCount = computed(
    () =>
        cameras.value
            .filter(matching)
            .filter((d) => hasStatusCheck(observation(d.id, props.workspace.date))).length,
);

const dates = computed(() => {
    const [year, month] = props.workspace.date.split('-').map(Number);
    const count = new Date(year, month, 0).getDate();
    return Array.from(
        { length: count },
        (_, i) => `${props.workspace.date.slice(0, 8)}${String(i + 1).padStart(2, '0')}`,
    );
});

const registerMonthLabel = computed(() =>
    new Date(`${props.workspace.date.slice(0, 7)}-01T12:00:00Z`).toLocaleDateString('en-PH', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }),
);

const registerMonths = computed(() => {
    const [year, month] = props.workspace.today.split('-').map(Number);
    const values = Array.from({ length: 24 }, (_, index) =>
        new Date(Date.UTC(year, month - 1 - index, 1)).toISOString().slice(0, 7),
    );
    const selected = props.workspace.date.slice(0, 7);
    if (!values.includes(selected)) values.push(selected);
    return values
        .sort()
        .reverse()
        .map((value) => ({
            value,
            label: new Date(`${value}-01T12:00:00Z`).toLocaleDateString('en-PH', {
                month: 'long',
                year: 'numeric',
                timeZone: 'UTC',
            }),
        }));
});

function changeRegisterMonth(event: Event) {
    const month = (event.target as HTMLSelectElement).value;
    const date =
        month === props.workspace.today.slice(0, 7) ? props.workspace.today : `${month}-01`;
    router.get('/cameras', { date, ...scopeParameters() }, { preserveScroll: true });
}

const activeIncidents = computed(() =>
    props.workspace.incidents.filter((i) => !['resolved', 'closed'].includes(i.status)),
);

const filteredIncidents = computed(() =>
    props.workspace.incidents.filter(
        (i) =>
            scopeBranchIds.value.has(i.branch_id) &&
            [i.reference, i.title, i.device.asset_id, i.branch.name]
                .join(' ')
                .toLowerCase()
                .includes(search.value.toLowerCase()) &&
            (incidentState.value === 'all' ||
                (incidentState.value === 'active'
                    ? !['resolved', 'closed'].includes(i.status)
                    : i.status === incidentState.value)),
    ),
);

const selectedIncident = computed(
    () =>
        filteredIncidents.value.find((i) => i.id === selectedIncidentId.value) ||
        filteredIncidents.value[0] ||
        null,
);

const evidence = computed(() =>
    props.workspace.observations
        .flatMap((o) =>
            o.attachments.map((a) => ({
                a,
                o,
                d: props.workspace.devices.find((d) => d.id === o.device_id)!,
            })),
        )
        .filter((x) => x.d && matching(x.d)),
);

const tickets = computed(() =>
    props.workspace.observations
        .filter((o) => o.ticket)
        .map((o) => ({
            o,
            t: o.ticket!,
            d: props.workspace.devices.find((d) => d.id === o.device_id)!,
        }))
        .filter((x) => x.d && matching(x.d)),
);

const outages = computed(() =>
    props.workspace.outages
        .map((o) => ({ ...o, device: props.workspace.devices.find((d) => d.id === o.device_id)! }))
        .filter((o) => o.device && matching(o.device)),
);

const reminderItems = computed(() =>
    [
        ...cameras.value
            .filter((d) => d.status === 'offline')
            .map((d) => ({
                type: 'device',
                id: d.id,
                branch: d.branch_id,
                title: `${d.asset_id} · ${d.name}`,
                description: `${d.branch.name} · camera offline`,
                device: d,
                incident: null as Incident | null,
            })),
        ...props.workspace.incidents
            .filter((i) => i.status !== 'closed')
            .map((i) => ({
                type: 'incident',
                id: i.id,
                branch: i.branch_id,
                title: `${i.reference} · ${i.title}`,
                description:
                    i.status === 'resolved'
                        ? 'Resolution awaiting verification'
                        : `Assigned to ${i.assignee?.name || 'Unassigned'} · target ${format(i.due_at)}`,
                device: null as Device | null,
                incident: i,
            })),
    ]
        .map((item) => ({
            ...item,
            snoozed:
                props.workspace.followups.find(
                    (f) => f.subject_type === item.type && f.subject_id === item.id,
                )?.snoozed_until || null,
        }))
        .filter(
            (i) =>
                scopeBranchIds.value.has(i.branch) &&
                i.title.toLowerCase().includes(search.value.toLowerCase()) &&
                (showSnoozed.value || !i.snoozed || i.snoozed <= props.workspace.today),
        ),
);

const accounts = computed(() =>
    props.workspace.accounts.filter(
        (u) =>
            accountMatchesScope(u) &&
            [u.name, u.email, u.idcode, u.team, u.role]
                .join(' ')
                .toLowerCase()
                .includes(search.value.toLowerCase()),
    ),
);

function accountMatchesScope(account: Account) {
    if (clusterFilter.value === 'all' && branch.value === 'all') return true;
    return (
        scopeBranchIds.value.size > 0 &&
        (account.role === 'admin' ||
            account.all_branches ||
            account.branches.some((item) => scopeBranchIds.value.has(item.id)))
    );
}

const visibleAudits = computed(() =>
    props.workspace.audits.filter((audit) => {
        if (clusterFilter.value === 'all' && branch.value === 'all') return true;
        if (audit.branch_id != null) return scopeBranchIds.value.has(audit.branch_id);
        const account =
            audit.subject_type === 'User'
                ? props.workspace.accounts.find((item) => item.id === audit.subject_id)
                : null;
        return account ? accountMatchesScope(account) : false;
    }),
);

function scopeParameters() {
    return {
        ...(clusterFilter.value === 'all' ? {} : { cluster_id: clusterFilter.value }),
        ...(branch.value === 'all' ? {} : { branch_id: branch.value }),
    };
}

const selectedClusterLabel = computed(() => {
    if (clusterFilter.value === 'clustered') return 'Assigned clusters';
    if (clusterFilter.value === 'unassigned') return 'Unassigned branches';
    return (
        props.workspace.clusters?.find((item) => item.id === Number(clusterFilter.value))?.name ||
        'All clusters'
    );
});

const reportUrl = (format: string) =>
    `/reports/export?${new URLSearchParams({ date: props.workspace.date, format, ...scopeParameters() })}`;
const issueReportUrl = computed(
    () =>
        `/reports/issues?${new URLSearchParams({ date: props.workspace.date, ...scopeParameters() })}`,
);

const metricItems = computed(() =>
    props.section === 'checklist'
        ? [
              ['Checked', `${checkedCount.value} / ${cameras.value.filter(matching).length}`],
              ['Still to check', cameras.value.filter(matching).length - checkedCount.value],
              [
                  'Offline',
                  cameras.value.filter(matching).filter((d) => d.status === 'offline').length,
              ],
              [
                  'Evidence images',
                  props.workspace.observations
                      .filter(
                          (o) =>
                              o.date === props.workspace.date &&
                              props.workspace.devices.some(
                                  (d) => d.id === o.device_id && matching(d),
                              ),
                      )
                      .reduce((n, o) => n + o.attachments.length, 0),
              ],
          ]
        : [
              ['Cameras', cameras.value.filter(matching).length],
              [
                  'Online',
                  cameras.value.filter(matching).filter((d) => d.status === 'online').length,
              ],
              [
                  'Offline',
                  cameras.value.filter(matching).filter((d) => d.status === 'offline').length,
              ],
              [
                  'Active incidents',
                  activeIncidents.value.filter((i) => scopeBranchIds.value.has(i.branch_id)).length,
              ],
          ],
);

watch(
    () => props.section,
    () => {
        search.value = '';
        statusFilter.value = 'all';
        selectedIds.value = [];
    },
);

watch(branch, () => {
    selectedIds.value = [];
});

let timer: ReturnType<typeof setInterval> | undefined;

watch(
    () =>
        props.workspace.observations.some(
            (o) => o.ticket && ['queued', 'processing'].includes(o.ticket.status),
        ),
    (pending) => {
        if (timer) clearInterval(timer);
        if (pending)
            timer = setInterval(() => {
                if (!observationOpen.value && !actionOpen.value)
                    router.reload({ only: ['workspace'], preserveUrl: true });
            }, 10000);
    },
    { immediate: true },
);

onUnmounted(() => {
    if (timer) clearInterval(timer);
});

function dateChange(e: Event) {
    router.get(
        location.pathname,
        { date: (e.target as HTMLInputElement).value, ...scopeParameters() },
        { preserveScroll: true },
    );
}

function review(d: Device, date = props.workspace.date) {
    observationDevices.value = [d];
    observationDate.value = date;
    observationOpen.value = true;
}

function bulk() {
    observationDevices.value = cameras.value.filter((d) => selectedIds.value.includes(d.id));
    observationDate.value = props.workspace.date;
    observationOpen.value = true;
}

function reviewOutage(d: Device) {
    if (['IP Camera', 'Analog Camera'].includes(d.type)) review(d, props.workspace.today);
    else detailDevice.value = d;
}

function deviceEdit(d: Device | null) {
    editingDevice.value = d;
    deviceOpen.value = true;
}

function accountEdit(u: Account | null) {
    editingAccount.value = u;
    accountOpen.value = true;
}

function act(action: string) {
    incidentAction.value = action;
    actionOpen.value = true;
}

function format(value: string | null) {
    return value
        ? new Date(value).toLocaleString('en-PH', {
              timeZone: 'Asia/Manila',
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : 'Not recorded';
}

function duration(start: string, end: string | null) {
    const minutes = Math.max(
        0,
        Math.floor(
            ((end ? new Date(end) : new Date()).getTime() - new Date(start).getTime()) / 60000,
        ),
    );
    return `${Math.floor(minutes / 1440)}d ${Math.floor((minutes % 1440) / 60)}h ${minutes % 60}m`;
}

function tomorrow() {
    const d = new Date(`${props.workspace.today}T12:00:00+08:00`);
    d.setUTCDate(d.getUTCDate() + 1);
    return d.toISOString().slice(0, 10);
}

function snooze(type: string, id: number, undo: boolean) {
    router.post(
        '/followups',
        { subject_type: type, subject_id: id, snoozed_until: undo ? null : tomorrow() },
        { preserveScroll: true },
    );
}

function editBranch(b: Branch | null) {
    editingBranch.value = b;
    branchForm.reset();
    branchForm.clearErrors();
    if (b) {
        branchForm.name = b.name;
        branchForm.code = b.code;
        branchForm.address = b.address || '';
        branchForm.active = b.active;
        branchForm.cluster_id = b.cluster_id || '';
    }
    branchOpen.value = true;
}

function saveBranch() {
    const opts = { onSuccess: () => (branchOpen.value = false) };
    editingBranch.value
        ? branchForm.put(`/branches/${editingBranch.value.id}`, opts)
        : branchForm.post('/branches', opts);
}
</script>

<template>
    <Head :title="titles[section]" /><AppLayout
        :section="section"
        :title="titles[section]"
    >
        <div class="rw-page-heading">
            <div>
                <div class="form-eyebrow">ROSE BAKESHOP · OPERATIONS</div>
                <h1>{{ titles[section] }}</h1>
                <p>{{ descriptions[section] }}</p>
            </div>
            <div class="rw-inline-actions">
                <Link
                    v-if="section === 'cameras'"
                    href="/reports"
                    class="rw-btn"
                    >Export daily report</Link
                ><button
                    v-if="
                        section === 'devices' &&
                        workspace.permissions.manage &&
                        workspace.branches.length
                    "
                    class="rw-btn primary"
                    @click="deviceEdit(null)"
                >
                    ＋ Add device</button
                ><button
                    v-if="
                        section === 'incidents' &&
                        workspace.permissions.write &&
                        workspace.devices.length
                    "
                    class="rw-btn primary"
                    @click="newIncidentOpen = true"
                >
                    ＋ Create incident</button
                ><button
                    v-if="section === 'users' && workspace.permissions.admin"
                    class="rw-btn primary"
                    @click="accountEdit(null)"
                >
                    ＋ Add user</button
                ><button
                    v-if="section === 'branches' && workspace.permissions.admin"
                    class="rw-btn primary"
                    @click="editBranch(null)"
                >
                    ＋ Add branch
                </button>
            </div>
        </div>
        <div
            v-if="!workspace.branches.length"
            class="rw-card rw-empty"
        >
            <h2>
                {{
                    workspace.permissions.admin
                        ? 'Start with your first branch'
                        : 'No branch access assigned'
                }}
            </h2>
            <p>
                {{
                    workspace.permissions.admin
                        ? 'Add a branch, then register its devices to begin daily checks.'
                        : 'Ask your administrator to assign the branches you support.'
                }}
            </p>
            <button
                v-if="workspace.permissions.admin"
                class="rw-btn primary"
                @click="editBranch(null)"
            >
                Add first branch
            </button>
        </div>
        <template v-if="['overview', 'checklist'].includes(section)"
            ><div class="rw-metrics">
                <article
                    v-for="[label, value] in metricItems"
                    :key="String(label)"
                    class="rw-card"
                >
                    <span>{{ label }}</span
                    ><strong>{{ value }}</strong>
                </article>
            </div></template
        >
        <template v-if="section === 'overview'"
            ><div class="rw-callout">
                <div>
                    <h2>Start your daily rounds</h2>
                    <p>
                        Check each camera, attach evidence, and follow up anything that needs
                        attention.
                    </p>
                </div>
                <Link
                    href="/checklist"
                    class="rw-btn primary"
                    >Open today’s checklist</Link
                >
            </div>
            <article class="rw-card rw-overview-workspace">
                <WorkspaceFilters
                    v-model:branch="branch"
                    v-model:cluster="clusterFilter"
                    v-model:search="search"
                    v-model:status="statusFilter"
                    :section="section"
                    :branches="workspace.branches"
                    :clusters="workspace.clusters || []"
                    :date="workspace.date"
                    :today="workspace.today"
                    @date-change="dateChange"
                />
                <p
                    v-if="workspace.date !== workspace.today"
                    class="rw-note rw-filter-note"
                >
                    Past date selected.
                    <Link :href="`/checklist?date=${workspace.today}`">Open today's checklist</Link>
                    to record actions.
                </p>
                <div class="rw-columns">
                    <article class="rw-overview-panel">
                        <div class="rw-card-heading">
                            <h2>Branches at a glance</h2>
                            <Link href="/branches">View branches</Link>
                        </div>
                        <div
                            v-for="b in visibleBranches"
                            :key="b.id"
                            class="rw-list-row"
                        >
                            <div>
                                <strong>{{ b.name }}</strong
                                ><small
                                    >{{
                                        cameras.filter((d) => d.branch_id === b.id).length
                                    }}
                                    cameras</small
                                >
                            </div>
                            <span
                                >{{
                                    cameras.filter(
                                        (d) => d.branch_id === b.id && d.status === 'online',
                                    ).length
                                }}
                                online</span
                            >
                        </div>
                    </article>
                    <article class="rw-overview-panel">
                        <div class="rw-card-heading">
                            <h2>Needs attention</h2>
                            <Link href="/reminders">Follow up</Link>
                        </div>
                        <div
                            v-for="d in cameras
                                .filter(matching)
                                .filter((d) => d.status === 'offline')
                                .slice(0, 6)"
                            :key="d.id"
                            class="rw-list-row"
                        >
                            <div>
                                <strong>{{ d.asset_id }}</strong
                                ><small>{{ d.name }} · {{ d.branch.name }}</small>
                            </div>
                            <button
                                class="rw-btn"
                                @click="review(d)"
                            >
                                Review
                            </button>
                        </div>
                        <p
                            v-if="!cameras.filter(matching).some((d) => d.status === 'offline')"
                            class="rw-empty"
                        >
                            No cameras currently recorded offline.
                        </p>
                    </article>
                </div>
            </article></template
        >

        <template v-if="section === 'cameras'">
            <article class="rw-card rw-register">
                <div class="rw-register-toolbar">
                    <label class="rw-register-search"
                        ><span class="rw-sr-only">Search camera, branch or location</span
                        ><svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            aria-hidden="true"
                        >
                            <circle
                                cx="11"
                                cy="11"
                                r="7"
                            />
                            <path d="m20 20-4-4" /></svg
                        ><input
                            v-model="search"
                            type="search"
                            placeholder="Search camera, branch or location"
                    /></label>
                    <ClusterFilter
                        v-model="clusterFilter"
                        :clusters="workspace.clusters || []"
                        compact
                    />
                    <label
                        ><span class="rw-sr-only">Filter by branch</span
                        ><select v-model="branch">
                            <option value="all">All branches</option>
                            <option
                                v-for="b in clusterBranches"
                                :key="b.id"
                                :value="String(b.id)"
                            >
                                {{ b.name }}
                            </option>
                        </select></label
                    >
                    <label
                        ><span class="rw-sr-only">Filter by current camera status</span
                        ><select v-model="statusFilter">
                            <option value="all">All current statuses</option>
                            <option value="online">Online</option>
                            <option value="offline">Offline</option>
                            <option value="degraded">Degraded</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="unknown">Unknown</option>
                        </select></label
                    >
                    <label
                        ><span class="rw-sr-only">Select register month</span
                        ><select
                            :value="workspace.date.slice(0, 7)"
                            @change="changeRegisterMonth"
                        >
                            <option
                                v-for="month in registerMonths"
                                :key="month.value"
                                :value="month.value"
                            >
                                {{ month.label }}
                            </option>
                        </select></label
                    >
                </div>
                <div class="rw-register-summary">
                    <div>
                        <h2>{{ registerMonthLabel }} status sheet</h2>
                        <p>
                            {{ visibleCameras.length }} cameras shown. Daily totals reflect the
                            cameras shown below. Total recorded includes day-off and holiday
                            entries.
                        </p>
                        <p
                            v-if="workspace.permissions.write && dates.includes(workspace.today)"
                            class="rw-note"
                        >
                            Today: checked = Online, unchecked = Offline. Changes save immediately.
                            Use Details for notes, images and tickets.
                        </p>
                        <button
                            v-if="dates.includes(workspace.today)"
                            type="button"
                            class="rw-link"
                            @click="jumpToToday"
                        >
                            Jump to today
                        </button>
                    </div>
                    <div
                        class="rw-register-legend"
                        aria-label="Camera status legend"
                    >
                        <span><i class="online"></i>Online</span
                        ><span><i class="offline"></i>Offline</span
                        ><span><i class="degraded"></i>Degraded</span
                        ><span><i class="maintenance"></i>Maintenance</span
                        ><span><i class="nodata"></i>No data</span>
                        <span><i class="day-off"></i>Day off / holiday</span>
                    </div>
                </div>
                <div
                    ref="registerScroll"
                    class="rw-matrix-scroll"
                >
                    <table class="rw-matrix rw-register-matrix">
                        <caption class="rw-sr-only">
                            {{
                                registerMonthLabel
                            }}
                            daily camera status. Past dates are read-only.
                        </caption>
                        <thead>
                            <tr>
                                <th
                                    class="sticky-camera"
                                    rowspan="2"
                                    scope="col"
                                >
                                    Camera / branch
                                </th>
                                <th
                                    class="rw-register-month"
                                    :colspan="dates.length"
                                    scope="colgroup"
                                >
                                    <span class="rw-register-month-label">{{
                                        registerMonthLabel
                                    }}</span>
                                </th>
                            </tr>
                            <tr>
                                <th
                                    v-for="day in dates"
                                    :key="day"
                                    class="rw-register-day"
                                    :class="{
                                        today: day === workspace.today,
                                        'rw-quick-day':
                                            day === workspace.today && workspace.permissions.write,
                                    }"
                                    scope="col"
                                >
                                    {{ Number(day.slice(8)) }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="d in visibleCameras"
                                :key="d.id"
                            >
                                <th
                                    class="sticky-camera"
                                    scope="row"
                                >
                                    <div class="rw-matrix-camera">
                                        <div>
                                            <strong
                                                ><Link
                                                    :href="`/devices/${d.id}`"
                                                    class="rw-device-link"
                                                    >{{ d.asset_id }} &#183; {{ d.name }}</Link
                                                ></strong
                                            ><small>{{ d.branch.name }}</small>
                                            <small v-if="d.status_reported_on"
                                                >Worksheet status ·
                                                {{ d.status_reported_on }}</small
                                            >
                                        </div>
                                        <StatusBadge :status="d.status" />
                                    </div>
                                </th>
                                <td
                                    v-for="day in dates"
                                    :key="day"
                                    :class="[
                                        observationStatus(observation(d.id, day)),
                                        {
                                            past: day < workspace.today,
                                            future: day > workspace.today,
                                            'rw-quick-cell':
                                                day === workspace.today &&
                                                workspace.permissions.write,
                                        },
                                    ]"
                                >
                                    <QuickCameraStatus
                                        v-if="
                                            day === workspace.today && workspace.permissions.write
                                        "
                                        :device="d"
                                        :date="day"
                                        :record="observation(d.id, day)"
                                        @saved="quickStatusSaved"
                                    />
                                    <button
                                        :class="{
                                            'rw-quick-details':
                                                day === workspace.today &&
                                                workspace.permissions.write,
                                        }"
                                        :disabled="day > workspace.today"
                                        :aria-label="`${d.asset_id}, ${day}: ${observationLabel(observation(d.id, day))}. ${day > workspace.today ? 'Future date' : day === workspace.today ? 'Open daily actions' : 'View read-only record'}`"
                                        @click="review(d, day)"
                                    >
                                        <span>{{
                                            day === workspace.today && workspace.permissions.write
                                                ? 'Details'
                                                : observationStatus(observation(d.id, day)) ===
                                                    'unchecked'
                                                  ? '\u2014'
                                                  : observationLabel(observation(d.id, day))
                                        }}</span
                                        ><small
                                            v-if="
                                                observation(d.id, day)?.attachments.length ||
                                                observation(d.id, day)?.ticket
                                            "
                                            ><span
                                                v-if="observation(d.id, day)?.attachments.length"
                                                title="Images attached"
                                                >&#9639;
                                                {{
                                                    observation(d.id, day)?.attachments.length
                                                }}</span
                                            >
                                            <span
                                                v-if="observation(d.id, day)?.ticket?.external_url"
                                                title="External ticket confirmed"
                                                >&#9636;</span
                                            ><span
                                                v-else-if="observation(d.id, day)?.ticket"
                                                title="Ticket request pending confirmation"
                                                >&#9719;</span
                                            ></small
                                        >
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                        <RegisterSummaryRows
                            :devices="visibleCameras"
                            :dates="dates"
                            :observations="workspace.observations"
                            :today="workspace.today"
                        />
                    </table>
                </div>
                <p
                    v-if="!visibleCameras.length"
                    class="rw-empty"
                >
                    No cameras match this view. Add cameras in Device management.
                </p>
                <div class="rw-register-footer">
                    <p>
                        &#9639; Image evidence &#183; &#9636; Confirmed external ticket &#183;
                        &#9719; Request awaiting confirmation. Past dates are read-only.
                    </p>
                    <p>Records and evidence are stored on the server.</p>
                </div>
            </article>
        </template>

        <template v-if="section === 'checklist'"
            ><div class="rw-guide">
                <strong>1. Review</strong><span>Check the camera or branch.</span
                ><strong>2. Record</strong><span>Save status and evidence.</span
                ><strong>3. Follow up</strong><span>Request a ticket or report an incident.</span>
            </div>
            <article class="rw-card rw-checklist">
                <div
                    class="rw-checklist-toolbar"
                    role="group"
                    aria-label="Checklist filters"
                >
                    <ClusterFilter
                        v-model="clusterFilter"
                        :clusters="workspace.clusters || []"
                    />
                    <label>
                        Branch
                        <select v-model="branch">
                            <option value="all">All branches</option>
                            <option
                                v-for="b in clusterBranches"
                                :key="b.id"
                                :value="String(b.id)"
                            >
                                {{ b.name }}
                            </option>
                        </select>
                    </label>
                    <label>
                        Show
                        <select v-model="statusFilter">
                            <option value="all">All cameras</option>
                            <option value="unchecked">Not checked</option>
                            <option value="checked">Checked</option>
                            <option value="online">Online</option>
                            <option value="offline">Offline</option>
                            <option value="degraded">Degraded</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="unknown">Unknown</option>
                        </select>
                    </label>
                    <button
                        v-if="canActOnSelectedDate"
                        class="rw-btn"
                        :disabled="!visibleCameras.length"
                        @click="selectedIds = visibleCameras.map((d) => d.id)"
                    >
                        Select visible cameras
                    </button>
                    <label class="rw-checklist-date">
                        <span class="rw-sr-only">Record date</span>
                        <input
                            type="date"
                            :value="workspace.date"
                            :max="workspace.today"
                            @change="dateChange"
                        />
                    </label>
                    <label class="rw-checklist-search">
                        <span class="rw-sr-only">Search cameras or branches</span>
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Search cameras or branches"
                        />
                    </label>
                </div>
                <div class="rw-checklist-progress">
                    <progress
                        :value="checkedCount"
                        :max="Math.max(checklistTotal, 1)"
                        :aria-label="`${checkedCount} of ${checklistTotal} cameras checked for ${workspace.date}`"
                    >
                        {{ checkedCount }} / {{ checklistTotal }}
                    </progress>
                    <p v-if="!checklistTotal">No cameras in this view.</p>
                    <p v-else-if="checkedCount === checklistTotal">
                        All cameras have a status observation.
                    </p>
                    <p v-else>
                        {{ checklistTotal - checkedCount }}
                        {{
                            checklistTotal - checkedCount === 1
                                ? 'camera still needs'
                                : 'cameras still need'
                        }}
                        a status observation
                    </p>
                    <small>{{ checkedCount }} / {{ checklistTotal }} checked</small>
                </div>
                <div
                    v-for="d in visibleCameras"
                    :key="d.id"
                    class="rw-list-row"
                >
                    <input
                        v-if="canActOnSelectedDate"
                        v-model="selectedIds"
                        type="checkbox"
                        :value="d.id"
                        :aria-label="`Select ${d.asset_id}`"
                    />
                    <div class="rw-grow">
                        <strong
                            ><Link
                                :href="`/devices/${d.id}`"
                                class="rw-device-link"
                                >{{ d.asset_id }} &#183; {{ d.name }}</Link
                            ></strong
                        ><small
                            >{{ d.branch.name }} ·
                            {{
                                hasStatusCheck(observation(d.id, workspace.date))
                                    ? 'Checked'
                                    : 'Not checked for this date'
                            }}</small
                        >
                    </div>
                    <StatusBadge :status="observation(d.id, workspace.date)?.status" /><button
                        class="rw-btn"
                        @click="review(d)"
                    >
                        {{
                            !canActOnSelectedDate
                                ? 'View record'
                                : hasStatusCheck(observation(d.id, workspace.date))
                                  ? 'Review record'
                                  : 'Check camera'
                        }}
                    </button>
                </div>
                <p
                    v-if="!visibleCameras.length"
                    class="rw-empty"
                >
                    No cameras match this view.
                </p>
            </article>
            <div
                v-if="canActOnSelectedDate"
                class="rw-callout"
            >
                <div>
                    <h2>Bulk status update · {{ selectedIds.length }} selected</h2>
                    <p>
                        Use for a confirmed shared outage or restoration. Review the list before
                        saving.
                    </p>
                </div>
                <button
                    class="rw-btn primary"
                    :disabled="!selectedIds.length"
                    @click="bulk"
                >
                    Update selected cameras
                </button>
            </div></template
        >

        <template v-if="section === 'devices'"
            ><article class="rw-card">
                <WorkspaceFilters
                    v-model:branch="branch"
                    v-model:cluster="clusterFilter"
                    v-model:search="search"
                    v-model:status="statusFilter"
                    :section="section"
                    :branches="workspace.branches"
                    :clusters="workspace.clusters || []"
                    :date="workspace.date"
                    :today="workspace.today"
                    @date-change="dateChange"
                />

                <div class="rw-table-scroll">
                    <table class="rw-table">
                        <thead>
                            <tr>
                                <th>Asset / device</th>
                                <th>Branch / type</th>
                                <th>Current status</th>
                                <th>Connected to</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="d in visibleDevices"
                                :key="d.id"
                            >
                                <td>
                                    <strong>{{ d.asset_id }}</strong
                                    ><small>{{ d.name }}</small
                                    ><small>{{ d.ip || 'No network address' }}</small>
                                </td>
                                <td>
                                    {{ d.branch.name }}<small>{{ d.type }}</small>
                                </td>
                                <td>
                                    <StatusBadge :status="d.status" /><small>{{
                                        format(d.status_observed_at)
                                    }}</small>
                                </td>
                                <td>
                                    {{
                                        workspace.devices.find((p) => p.id === d.parent_id)
                                            ?.asset_id || 'Standalone'
                                    }}<small v-if="d.channel">Channel {{ d.channel }}</small>
                                </td>
                                <td>
                                    <div class="rw-inline-actions">
                                        <Link
                                            :href="`/devices/${d.id}`"
                                            class="rw-btn"
                                            >Dashboard</Link
                                        >
                                        <button
                                            class="rw-btn"
                                            @click="detailDevice = d"
                                        >
                                            Details</button
                                        ><button
                                            v-if="workspace.permissions.manage"
                                            class="rw-btn"
                                            @click="deviceEdit(d)"
                                        >
                                            Edit / mark status
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-if="!visibleDevices.length"
                    class="rw-empty"
                >
                    No devices match. Register a branch’s equipment to get started.
                </p>
            </article></template
        >

        <template v-if="section === 'evidence'"
            ><article class="rw-card rw-evidence-workspace">
                <WorkspaceFilters
                    v-model:branch="branch"
                    v-model:cluster="clusterFilter"
                    v-model:search="search"
                    v-model:status="statusFilter"
                    :section="section"
                    :branches="workspace.branches"
                    :clusters="workspace.clusters || []"
                    :date="workspace.date"
                    :today="workspace.today"
                    @date-change="dateChange"
                />
                <p class="rw-note rw-filter-note">
                    Evidence for {{ workspace.date.slice(0, 7) }}. Select another month using the
                    date filter. Files are private to authorized branch users.
                </p>
                <div
                    v-if="evidence.length"
                    class="rw-gallery padded"
                >
                    <article
                        v-for="item in evidence"
                        :key="item.a.id"
                        class="rw-evidence-item"
                    >
                        <button
                            class="rw-image-button"
                            @click="preview = item.a"
                        >
                            <img
                                :src="item.a.url"
                                :alt="item.a.name"
                                loading="lazy"
                            />
                        </button>
                        <div class="padded">
                            <strong>{{ item.d.asset_id }} · {{ item.d.name }}</strong>
                            <p>{{ item.o.date }}</p>
                            <button
                                class="rw-link"
                                @click="review(item.d, item.o.date)"
                            >
                                Open daily record
                            </button>
                        </div>
                    </article>
                </div>
                <div
                    v-if="!evidence.length"
                    class="rw-empty"
                >
                    <h2>No screenshots in this view</h2>
                    <p>
                        Open a camera record and select Attach images to capture or upload evidence.
                    </p>
                    <Link
                        href="/cameras"
                        class="rw-btn"
                        >Go to Cameras</Link
                    >
                </div>
            </article></template
        >

        <template v-if="section === 'outages'"
            ><p class="rw-note">
                Times show when a state was recorded, rather than inferred stream-failure times.
                Historical status corrections do not rewrite these intervals.
            </p>
            <article class="rw-card">
                <WorkspaceFilters
                    v-model:branch="branch"
                    v-model:cluster="clusterFilter"
                    v-model:search="search"
                    v-model:status="statusFilter"
                    :section="section"
                    :branches="workspace.branches"
                    :clusters="workspace.clusters || []"
                    :date="workspace.date"
                    :today="workspace.today"
                    @date-change="dateChange"
                />

                <div
                    v-for="o in outages"
                    :key="o.id"
                    class="rw-list-row"
                >
                    <div class="rw-grow">
                        <strong>{{ o.device.asset_id }} · {{ o.device.name }}</strong
                        ><small
                            >{{ o.device.branch.name }} · Offline recorded
                            {{ format(o.started_at) }}</small
                        ><small
                            >{{
                                o.restored_at
                                    ? `Restored ${format(o.restored_at)}`
                                    : 'Restoration not yet confirmed'
                            }}
                            · {{ duration(o.started_at, o.restored_at) }}</small
                        ><small>{{ o.notes }}</small>
                    </div>
                    <StatusBadge :status="o.restored_at ? 'online' : 'offline'" /><button
                        class="rw-btn"
                        @click="reviewOutage(o.device)"
                    >
                        {{ o.restored_at ? 'View record' : 'Record restoration' }}
                    </button>
                </div>
                <p
                    v-if="!outages.length"
                    class="rw-empty"
                >
                    No outages recorded for these cameras.
                </p>
            </article></template
        >

        <template v-if="section === 'branches'"
            ><article class="rw-card rw-branch-workspace">
                <WorkspaceFilters
                    v-model:branch="branch"
                    v-model:cluster="clusterFilter"
                    v-model:search="search"
                    v-model:status="statusFilter"
                    :section="section"
                    :branches="workspace.branches"
                    :clusters="workspace.clusters || []"
                    :date="workspace.date"
                    :today="workspace.today"
                    @date-change="dateChange"
                />
                <div class="rw-branch-grid">
                    <article
                        v-for="b in visibleBranches"
                        :key="b.id"
                        class="rw-branch-panel"
                    >
                        <div class="rw-card-heading">
                            <div>
                                <h2>{{ b.name }}</h2>
                                <small
                                    >{{ b.code }} · {{ b.active ? 'Active' : 'Inactive' }} ·
                                    {{ b.address
                                    }}<template v-if="b.cluster">
                                        · {{ b.cluster.name }}</template
                                    ></small
                                >
                            </div>
                            <button
                                v-if="workspace.permissions.admin"
                                class="rw-link"
                                @click="editBranch(b)"
                            >
                                Edit branch
                            </button>
                        </div>
                        <div class="padded">
                            <div class="rw-branch-stats">
                                <div>
                                    <strong>{{
                                        cameras.filter(
                                            (d) => d.branch_id === b.id && d.status === 'online',
                                        ).length
                                    }}</strong
                                    >Online
                                </div>
                                <div>
                                    <strong>{{
                                        cameras.filter(
                                            (d) => d.branch_id === b.id && d.status === 'offline',
                                        ).length
                                    }}</strong
                                    >Offline
                                </div>
                                <div>
                                    <strong>{{
                                        activeIncidents.filter((i) => i.branch_id === b.id).length
                                    }}</strong
                                    >Active incidents
                                </div>
                            </div>
                            <p>
                                Checked
                                {{
                                    cameras.filter(
                                        (d) =>
                                            d.branch_id === b.id &&
                                            hasStatusCheck(observation(d.id, workspace.today)),
                                    ).length
                                }}
                                of {{ cameras.filter((d) => d.branch_id === b.id).length }} cameras
                                today
                            </p>
                            <div class="rw-inline-actions">
                                <Link
                                    :href="`/checklist?branch_id=${b.id}`"
                                    class="rw-btn"
                                    >Open checklist</Link
                                ><Link
                                    :href="`/incidents?branch_id=${b.id}`"
                                    class="rw-link"
                                    >Review incidents</Link
                                >
                            </div>
                        </div>
                    </article>
                </div>
                <p
                    v-if="!visibleBranches.length"
                    class="rw-empty"
                >
                    No branches match this view.
                </p>
            </article></template
        >

        <template v-if="section === 'incidents'"
            ><article class="rw-card rw-incident-workspace">
                <WorkspaceFilters
                    v-model:branch="branch"
                    v-model:cluster="clusterFilter"
                    v-model:search="search"
                    v-model:status="statusFilter"
                    :section="section"
                    :branches="workspace.branches"
                    :clusters="workspace.clusters || []"
                    :date="workspace.date"
                    :today="workspace.today"
                    @date-change="dateChange"
                >
                    <label
                        >View<select v-model="incidentState">
                            <option value="active">Active incidents</option>
                            <option value="resolved">Awaiting verification</option>
                            <option value="closed">Closed</option>
                            <option value="all">All incidents</option>
                        </select></label
                    >
                </WorkspaceFilters>
                <div class="rw-incident-layout">
                    <article class="rw-incident-list">
                        <button
                            v-for="i in filteredIncidents"
                            :key="i.id"
                            class="rw-incident-row"
                            :class="{ selected: selectedIncident?.id === i.id }"
                            @click="selectedIncidentId = i.id"
                        >
                            <strong>{{ i.title }}</strong
                            ><small>{{ i.reference }}</small
                            ><small
                                >{{ i.device.asset_id }} · {{ i.branch.name }} ·
                                {{ i.priority }}</small
                            ><StatusBadge :status="i.status" />
                        </button>
                        <p
                            v-if="!filteredIncidents.length"
                            class="rw-empty"
                        >
                            No matching incidents.
                        </p>
                    </article>
                    <article
                        v-if="selectedIncident"
                        class="rw-incident-detail"
                    >
                        <div class="rw-card-heading">
                            <div>
                                <h2>{{ selectedIncident.title }}</h2>
                                <small>{{ selectedIncident.reference }}</small>
                            </div>
                            <StatusBadge :status="selectedIncident.status" />
                        </div>
                        <div class="padded">
                            <p>{{ selectedIncident.description }}</p>
                            <dl class="rw-details">
                                <div>
                                    <dt>Device / branch</dt>
                                    <dd>
                                        {{ selectedIncident.device.asset_id }} ·
                                        {{ selectedIncident.branch.name }}
                                    </dd>
                                </div>
                                <div>
                                    <dt>Assigned to</dt>
                                    <dd>{{ selectedIncident.assignee?.name || 'Unassigned' }}</dd>
                                </div>
                                <div>
                                    <dt>Created</dt>
                                    <dd>{{ format(selectedIncident.created_at) }}</dd>
                                </div>
                                <div>
                                    <dt>Target</dt>
                                    <dd>{{ format(selectedIncident.due_at) }}</dd>
                                </div>
                            </dl>
                            <div
                                class="rw-inline-actions"
                                v-if="workspace.permissions.write"
                            >
                                <template
                                    v-if="
                                        ['assigned', 'in_progress', 'on_hold'].includes(
                                            selectedIncident.status,
                                        )
                                    "
                                    ><button
                                        class="rw-btn"
                                        @click="act('log')"
                                    >
                                        Add action log</button
                                    ><button
                                        class="rw-btn primary"
                                        @click="act('resolve')"
                                    >
                                        Resolve
                                    </button></template
                                ><button
                                    v-if="
                                        selectedIncident.status === 'resolved' &&
                                        workspace.permissions.verify
                                    "
                                    class="rw-btn primary"
                                    @click="act('verify')"
                                >
                                    Verify & close</button
                                ><button
                                    v-if="['resolved', 'closed'].includes(selectedIncident.status)"
                                    class="rw-btn"
                                    @click="act('reopen')"
                                >
                                    Reopen</button
                                ><button
                                    class="rw-btn"
                                    @click="act('document')"
                                >
                                    Signed document link
                                </button>
                            </div>
                            <p v-if="selectedIncident.signed_document_url">
                                <a
                                    :href="selectedIncident.signed_document_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    >Open signed incident report</a
                                >
                            </p>
                            <div
                                v-if="selectedIncident.resolution"
                                class="rw-callout"
                            >
                                <div>
                                    <h3>Resolution</h3>
                                    <p>Cause: {{ selectedIncident.resolution.cause }}</p>
                                    <p>Work: {{ selectedIncident.resolution.work }}</p>
                                    <p>
                                        Preventive action:
                                        {{
                                            selectedIncident.resolution.prevention ||
                                            'Not specified'
                                        }}
                                    </p>
                                    <p>Restored: {{ format(selectedIncident.restored_at) }}</p>
                                </div>
                            </div>
                            <p class="rw-note">
                                Incident resolution and camera status are separate. Record an Online
                                observation after checking the camera to close its outage.
                            </p>
                            <IncidentHistory
                                :logs="selectedIncident.logs"
                                @preview="preview = $event"
                            />
                        </div>
                    </article>
                </div></article
        ></template>

        <template v-if="section === 'tickets'"
            ><p class="rw-note">
                {{
                    workspace.ticket_configured
                        ? 'Ticket requests are processed by a background worker. Status refreshes while creation is pending.'
                        : 'External ticket integration is not connected yet. Requests are retained and can be retried after configuration.'
                }}
            </p>
            <article class="rw-card">
                <WorkspaceFilters
                    v-model:branch="branch"
                    v-model:cluster="clusterFilter"
                    v-model:search="search"
                    v-model:status="statusFilter"
                    :section="section"
                    :branches="workspace.branches"
                    :clusters="workspace.clusters || []"
                    :date="workspace.date"
                    :today="workspace.today"
                    @date-change="dateChange"
                />

                <LiveTicketList
                    :date="workspace.date"
                    :request-ids="tickets.map(({ t }) => t.id)"
                    :enabled="!!workspace.ticket_view_configured"
                />
                <div
                    v-for="{ o, t, d } in tickets"
                    :key="t.id"
                    class="rw-list-row"
                >
                    <div class="rw-grow">
                        <strong>{{ t.title }}</strong
                        ><small>{{ d.asset_id }} · {{ d.branch.name }} · {{ o.date }}</small>
                        <p
                            v-if="t.error"
                            class="rw-note"
                        >
                            {{ t.error }}
                        </p>
                    </div>
                    <StatusBadge :status="t.status" /><a
                        v-if="t.external_url"
                        :href="t.external_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="rw-btn"
                        >Open {{ t.external_id }}</a
                    ><button
                        v-else-if="
                            workspace.permissions.write &&
                            o.date === workspace.today &&
                            ['failed', 'unavailable', 'pending'].includes(t.status)
                        "
                        class="rw-btn"
                        @click="router.post(`/tickets/${t.id}/retry`, {}, { preserveScroll: true })"
                    >
                        Retry creation</button
                    ><button
                        class="rw-link"
                        @click="review(d, o.date)"
                    >
                        Daily record
                    </button>
                </div>
                <p
                    v-if="!tickets.length"
                    class="rw-empty"
                >
                    No ticket requests for this month. Select Create ticket in a camera daily
                    record.
                </p>
            </article></template
        >

        <template v-if="section === 'reminders'">
            <article class="rw-card">
                <WorkspaceFilters
                    v-model:branch="branch"
                    v-model:cluster="clusterFilter"
                    v-model:search="search"
                    v-model:status="statusFilter"
                    :section="section"
                    :branches="workspace.branches"
                    :clusters="workspace.clusters || []"
                    :date="workspace.date"
                    :today="workspace.today"
                    @date-change="dateChange"
                >
                    <label class="rw-card-filter-toggle">
                        <input
                            v-model="showSnoozed"
                            type="checkbox"
                        />
                        Include snoozed reminders
                    </label>
                </WorkspaceFilters>

                <div
                    v-for="item in reminderItems"
                    :key="item.type + item.id"
                    class="rw-list-row"
                >
                    <div class="rw-grow">
                        <strong>{{ item.title }}</strong
                        ><small>{{ item.description }}</small
                        ><small v-if="item.snoozed && item.snoozed > workspace.today"
                            >Snoozed until {{ item.snoozed }}</small
                        >
                    </div>
                    <button
                        v-if="item.device"
                        class="rw-btn"
                        @click="review(item.device, workspace.today)"
                    >
                        Review camera</button
                    ><Link
                        v-else
                        href="/incidents"
                        class="rw-btn"
                        >Review incident</Link
                    ><button
                        class="rw-link"
                        @click="
                            snooze(
                                item.type,
                                item.id,
                                !!item.snoozed && item.snoozed > workspace.today,
                            )
                        "
                    >
                        {{
                            item.snoozed && item.snoozed > workspace.today
                                ? 'Undo snooze'
                                : 'Remind tomorrow'
                        }}
                    </button>
                </div>
                <p
                    v-if="!reminderItems.length"
                    class="rw-empty"
                >
                    No follow-ups in this view.
                </p>
            </article></template
        >

        <template v-if="section === 'reports'"
            ><div class="rw-callout">
                <div>
                    <h2>
                        {{ workspace.date }} ·
                        {{
                            branch === 'all'
                                ? 'All assigned branches'
                                : workspace.branches.find((b) => b.id === Number(branch))?.name
                        }}
                    </h2>
                    <p class="rw-note">Cluster: {{ selectedClusterLabel }}</p>
                    <p>
                        The full HTML report includes embedded screenshots, external ticket links
                        and current incident summaries. Open the downloaded report and use Print to
                        save a PDF. CSV contains camera observations. Choose Branch Issues Report
                        for offline equipment and recorded concerns only.
                    </p>
                </div>
                <div class="rw-inline-actions">
                    <Link
                        class="rw-btn primary"
                        :href="issueReportUrl"
                        >Branch Issues Report</Link
                    >
                    <a
                        class="rw-btn"
                        :href="reportUrl('html')"
                        >Download full report</a
                    ><a
                        class="rw-btn"
                        :href="reportUrl('csv')"
                        >Camera CSV</a
                    >
                </div>
            </div>
            <article class="rw-card">
                <WorkspaceFilters
                    v-model:branch="branch"
                    v-model:cluster="clusterFilter"
                    v-model:search="search"
                    v-model:status="statusFilter"
                    :section="section"
                    :branches="workspace.branches"
                    :clusters="workspace.clusters || []"
                    :date="workspace.date"
                    :today="workspace.today"
                    @date-change="dateChange"
                />

                <div
                    v-for="d in visibleCameras"
                    :key="d.id"
                    class="rw-list-row"
                >
                    <div class="rw-grow">
                        <strong>{{ d.asset_id }} · {{ d.name }}</strong
                        ><small
                            >{{ d.branch.name }} ·
                            {{ observation(d.id, workspace.date)?.attachments.length || 0 }}
                            images</small
                        >
                        <p>
                            {{
                                observation(d.id, workspace.date)?.notes ||
                                'No saved observation for this date.'
                            }}
                        </p>
                    </div>
                    <StatusBadge :status="observation(d.id, workspace.date)?.status" />
                </div></article
        ></template>

        <template v-if="section === 'users' && workspace.permissions.admin"
            ><article class="rw-card">
                <WorkspaceFilters
                    v-model:branch="branch"
                    v-model:cluster="clusterFilter"
                    v-model:search="search"
                    v-model:status="statusFilter"
                    :section="section"
                    :branches="workspace.branches"
                    :clusters="workspace.clusters || []"
                    :date="workspace.date"
                    :today="workspace.today"
                    @date-change="dateChange"
                />

                <p
                    v-if="clusterFilter !== 'all' || branch !== 'all'"
                    class="rw-note padded"
                >
                    Accounts with access to the selected branches. All-branch accounts appear in
                    every matching cluster.
                </p>
                <div class="rw-table-scroll">
                    <table class="rw-table">
                        <thead>
                            <tr>
                                <th>Person</th>
                                <th>Role / team</th>
                                <th>Branch access</th>
                                <th>Status</th>
                                <th>Manage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="u in accounts"
                                :key="u.id"
                            >
                                <td>
                                    <strong>{{ u.name }}</strong
                                    ><small>{{ u.email }}</small>
                                    <small v-if="u.idcode">Employee ID: {{ u.idcode }}</small>
                                </td>
                                <td>
                                    {{ u.role }}<small>{{ u.team }}</small>
                                </td>
                                <td>
                                    {{
                                        u.all_branches || u.role === 'admin'
                                            ? 'All branches'
                                            : u.branches.map((b) => b.name).join(', ')
                                    }}
                                </td>
                                <td><StatusBadge :status="u.active ? 'active' : 'inactive'" /></td>
                                <td>
                                    <button
                                        class="rw-btn"
                                        @click="accountEdit(u)"
                                    >
                                        Edit user
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </article>
            <article class="rw-card space-top">
                <div class="rw-card-heading">
                    <h2>Account and operations audit</h2>
                    <small>Latest 200 entries</small>
                </div>
                <div
                    v-for="a in visibleAudits"
                    :key="a.id"
                    class="rw-list-row"
                >
                    <div>
                        <strong>{{ a.action }} · {{ a.subject_type }} #{{ a.subject_id }}</strong
                        ><small>{{ a.user?.name || 'System' }} · {{ format(a.created_at) }}</small>
                        <p>{{ a.reason }}</p>
                    </div>
                </div>
            </article></template
        >

        <template v-if="section === 'process'"
            ><div class="rw-process">
                <article
                    v-for="([title, description, path], index) in [
                        [
                            'Check the camera',
                            'Record Online or Offline and attach evidence.',
                            '/checklist',
                        ],
                        [
                            'Follow up the outage',
                            'Confirm restoration; record a new status observation.',
                            '/outages',
                        ],
                        [
                            'Request external support',
                            'Create ticket sends the record to the configured provider.',
                            '/tickets',
                        ],
                        [
                            'Document the incident',
                            'Record assignment, findings, work and signed report documents.',
                            '/incidents',
                        ],
                        [
                            'Verify and hand over',
                            'A supervisor verifies the resolution. Export the daily report.',
                            '/reports',
                        ],
                    ]"
                    :key="title"
                    class="rw-card padded"
                >
                    <span class="rw-step">{{ index + 1 }}</span>
                    <h2>{{ title }}</h2>
                    <p>{{ description }}</p>
                    <Link
                        :href="path"
                        class="rw-btn"
                        >Open workflow</Link
                    >
                </article>
            </div></template
        >

        <AppModal
            :open="!!detailDevice"
            :title="detailDevice?.asset_id || 'Device details'"
            @close="detailDevice = null"
            ><template v-if="detailDevice"
                ><h3>{{ detailDevice.name }}</h3>
                <StatusBadge :status="detailDevice.status" />
                <p>
                    <Link
                        :href="`/devices/${detailDevice.id}`"
                        class="rw-btn"
                        >Open device dashboard</Link
                    >
                </p>
                <dl class="rw-details">
                    <div
                        v-for="[label, value] in [
                            ['Branch', detailDevice.branch.name],
                            ['Type', detailDevice.type],
                            ['Location', detailDevice.location],
                            [
                                'Brand / model',
                                [detailDevice.brand, detailDevice.model]
                                    .filter(Boolean)
                                    .join(' / '),
                            ],
                            ['Serial', detailDevice.serial],
                            ['IPv4 address', detailDevice.ip],
                            ['Responsible team', detailDevice.owner],
                            [
                                'Connected to',
                                workspace.devices.find((d) => d.id === detailDevice?.parent_id)
                                    ?.asset_id,
                            ],
                            ['Channel', detailDevice.channel],
                            ['Recorder capacity', detailDevice.channels],
                            ['Storage (TB)', detailDevice.storage_tb],
                            ['Disk health', detailDevice.disk_health],
                            ['Installation date', detailDevice.installed_at],
                            ['Warranty ends', detailDevice.warranty_at],
                        ]"
                        :key="String(label)"
                    >
                        <dt>{{ label }}</dt>
                        <dd>{{ value || 'Not recorded' }}</dd>
                    </div>
                </dl>
                <p>{{ detailDevice.notes }}</p>
                <h3>Connected equipment</h3>
                <p
                    v-for="d in workspace.devices.filter((d) => d.parent_id === detailDevice?.id)"
                    :key="d.id"
                >
                    <button
                        class="rw-link"
                        @click="detailDevice = d"
                    >
                        {{ d.asset_id }} ? {{ d.name }}
                    </button>
                </p>
                <p v-if="!workspace.devices.some((d) => d.parent_id === detailDevice?.id)">
                    No linked equipment.
                </p>
                <button
                    v-if="workspace.permissions.manage"
                    class="rw-btn primary"
                    @click="
                        deviceEdit(detailDevice);
                        detailDevice = null;
                    "
                >
                    Edit device
                </button></template
            ></AppModal
        >
        <DeviceEditor
            :open="deviceOpen"
            :device="editingDevice"
            :branches="workspace.branches"
            :devices="workspace.devices"
            @close="deviceOpen = false"
        />
        <ObservationEditor
            :open="observationOpen"
            :devices="observationDevices"
            :date="observationDate"
            :today="workspace.today"
            :record="
                observationDevices.length === 1
                    ? observation(observationDevices[0].id, observationDate)
                    : null
            "
            :can-write="workspace.permissions.write"
            :ticket-configured="workspace.ticket_configured"
            :ticket-boards="workspace.ticket_boards"
            :ticket-default-board="workspace.ticket_default_board"
            @close="
                observationOpen = false;
                selectedIds = [];
            "
        />
        <IncidentEditor
            :open="newIncidentOpen"
            :workspace="workspace"
            @close="newIncidentOpen = false"
        />
        <IncidentAction
            :open="actionOpen"
            :incident="selectedIncident"
            :action="incidentAction"
            :workspace="workspace"
            @close="actionOpen = false"
        />
        <AccountEditor
            :open="accountOpen"
            :account="editingAccount"
            :branches="workspace.branches"
            @close="accountOpen = false"
        />
        <AppModal
            :open="!!preview"
            :title="preview?.name || 'Evidence preview'"
            wide
            @close="preview = null"
            ><template v-if="preview"
                ><img
                    v-if="preview.mime.startsWith('image/')"
                    class="rw-preview-image"
                    :src="preview.url"
                    :alt="preview.name"
                /><iframe
                    v-else
                    :src="preview.url"
                    :title="preview.name"
                    class="rw-pdf-preview"
                ></iframe>
                <div class="rw-form-actions">
                    <a
                        class="rw-btn"
                        :href="preview.download_url"
                        >Download evidence</a
                    >
                </div></template
            ></AppModal
        >
        <AppModal
            :open="branchOpen"
            :title="editingBranch ? 'Edit branch' : 'Add branch'"
            :busy="branchForm.processing"
            @close="branchOpen = false"
            ><form @submit.prevent="saveBranch">
                <FormErrors :errors="branchForm.errors" /><label
                    >Branch name *<input
                        v-model="branchForm.name"
                        required
                        maxlength="100" /></label
                ><label
                    >Branch code *<input
                        v-model="branchForm.code"
                        required
                        maxlength="30"
                        placeholder="MKT-CENTRAL" /></label
                ><label>
                    Cluster
                    <select v-model="branchForm.cluster_id">
                        <option value="">Unassigned</option>
                        <option
                            v-for="cluster in workspace.clusters || []"
                            :key="cluster.id"
                            :value="cluster.id"
                        >
                            {{ cluster.name }}
                        </option>
                    </select> </label
                ><label
                    >Address<input
                        v-model="branchForm.address"
                        maxlength="255" /></label
                ><label class="rw-check"
                    ><input
                        v-model="branchForm.active"
                        type="checkbox"
                    />Active branch</label
                >
                <div class="rw-form-actions">
                    <button
                        type="button"
                        class="rw-btn"
                        @click="branchOpen = false"
                    >
                        Cancel</button
                    ><button
                        class="rw-btn primary"
                        :disabled="branchForm.processing"
                    >
                        Save branch
                    </button>
                </div>
            </form></AppModal
        >
    </AppLayout>
</template>
