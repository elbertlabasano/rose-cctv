<script setup lang="ts">
import axios from 'axios';
import { computed, onUnmounted, ref, watch } from 'vue';
import StatusBadge from './StatusBadge.vue';

const props = defineProps<{ date: string; requestIds: number[]; enabled: boolean }>();

interface LiveTicket {
    request_id: number;
    id: string;
    url: string;
    subject: string;
    description: string;
    status: string;
    priority: string;
    board: string;
    assignees: string[];
    due_date: string;
    updated_at: string;
}

const items = ref<LiveTicket[]>([]);
const page = ref(1);
const hasMore = ref(false);
const loaded = ref(false);
const loading = ref(false);
const error = ref('');
let request: AbortController | null = null;
let generation = 0;
const visible = computed(() =>
    items.value.filter((item) => props.requestIds.includes(item.request_id)),
);

async function load(nextPage = page.value) {
    request?.abort();
    request = new AbortController();
    const current = ++generation;
    loading.value = true;
    error.value = '';
    try {
        const response = await axios.get('/tickets/external', {
            params: { date: props.date, page: nextPage },
            signal: request.signal,
            headers: { Accept: 'application/json' },
        });
        if (current !== generation) return;
        items.value = response.data.data;
        page.value = response.data.page;
        hasMore.value = response.data.has_more;
        loaded.value = true;
    } catch (failure) {
        if (current !== generation || axios.isCancel(failure)) return;
        error.value =
            axios.isAxiosError(failure) && typeof failure.response?.data?.message === 'string'
                ? failure.response.data.message
                : 'Live tickets could not be loaded. Please try again later.';
    } finally {
        if (current === generation) loading.value = false;
    }
}

watch(
    () => [props.date, props.enabled],
    () => {
        request?.abort();
        generation++;
        items.value = [];
        page.value = 1;
        hasMore.value = false;
        loaded.value = false;
        loading.value = false;
        error.value = '';
    },
);
onUnmounted(() => request?.abort());
</script>

<template>
    <section
        class="live-tickets"
        aria-labelledby="live-tickets-heading"
        :aria-busy="loading"
    >
        <div class="live-ticket-heading">
            <div>
                <h2 id="live-tickets-heading">Live tickets from Support Hub</h2>
                <p>
                    View linked tickets for this month and your selected filters. Changes are
                    managed in Support Hub.
                </p>
            </div>
            <button
                class="rw-btn"
                type="button"
                :disabled="!enabled || loading"
                @click="load()"
            >
                {{ loading ? 'Loading…' : loaded ? 'Refresh live tickets' : 'Load live tickets' }}
            </button>
        </div>
        <p
            v-if="!enabled"
            class="rw-note"
        >
            Ticket viewing is not configured yet.
        </p>
        <p
            v-if="error"
            class="rw-errors"
            role="alert"
        >
            {{ error }}
        </p>
        <template v-if="loaded">
            <p
                class="rw-note"
                role="status"
            >
                Support Hub page {{ page }} · {{ visible.length }} matching
                {{ visible.length === 1 ? 'ticket' : 'tickets' }}. The API lists tickets for the
                configured assignee; unrelated tickets are hidden.
            </p>
            <article
                v-for="ticket in visible"
                :key="ticket.request_id"
                class="live-ticket"
            >
                <div class="live-ticket-heading">
                    <div>
                        <strong>{{ ticket.id }} · {{ ticket.subject }}</strong>
                        <p>{{ ticket.board || 'Backlog' }} · {{ ticket.priority }} priority</p>
                    </div>
                    <StatusBadge :status="ticket.status" />
                    <a
                        class="rw-btn"
                        :href="ticket.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        >View in Support Hub</a
                    >
                </div>
                <p>
                    Assigned to {{ ticket.assignees.join(', ') || 'Unassigned'
                    }}<template v-if="ticket.due_date"> · Due {{ ticket.due_date }}</template>
                </p>
                <details v-if="ticket.description">
                    <summary>View description</summary>
                    <p class="live-ticket-description">{{ ticket.description }}</p>
                </details>
            </article>
            <p
                v-if="!visible.length"
                class="rw-note"
            >
                No matching tickets on this provider page. Try another page or adjust the filters.
                Tickets assigned to someone else will not appear in this feed.
            </p>
            <nav
                class="live-ticket-pages"
                aria-label="Support Hub ticket pages"
            >
                <button
                    class="rw-btn"
                    type="button"
                    :disabled="loading || page <= 1"
                    @click="load(page - 1)"
                >
                    Previous page
                </button>
                <span>Page {{ page }}</span>
                <button
                    class="rw-btn"
                    type="button"
                    :disabled="loading || !hasMore || page >= 1000"
                    @click="load(page + 1)"
                >
                    Next page
                </button>
            </nav>
        </template>
    </section>
</template>

<style scoped>
.live-tickets {
    padding: 22px;
    border-bottom: 1px solid var(--line);
    background: #fdf8f2;
}
.live-tickets h2 {
    margin: 0;
    font-size: 17px;
}
.live-tickets p {
    line-height: 1.7;
}
.live-ticket-heading,
.live-ticket-pages {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
}
.live-ticket-heading > div {
    flex: 1 1 240px;
    min-width: 0;
}
.live-ticket {
    margin-top: 16px;
    padding: 18px;
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 14px;
    overflow-wrap: anywhere;
}
.live-ticket details {
    margin-top: 12px;
}
.live-ticket summary {
    cursor: pointer;
}
.live-ticket-description {
    white-space: pre-wrap;
}
.live-ticket-pages {
    margin-top: 18px;
}
@media (max-width: 550px) {
    .live-tickets {
        padding: 16px;
    }
}
</style>
