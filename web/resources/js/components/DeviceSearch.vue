<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import AppModal from './AppModal.vue';
import StatusBadge from './StatusBadge.vue';

interface SearchDevice {
    id: number;
    asset_id: string;
    name: string;
    type: string;
    status: string;
    branch: string;
    url: string;
}

const emit = defineEmits<{ navigate: [] }>();

const open = ref(false);
const query = ref('');
const results = ref<SearchDevice[]>([]);
const loading = ref(false);
const error = ref('');
const hasMore = ref(false);
const activeIndex = ref(0);
const input = ref<HTMLInputElement | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);

const activeId = computed(() =>
    results.value[activeIndex.value]
        ? `device-search-${results.value[activeIndex.value].id}`
        : undefined,
);

let timer: ReturnType<typeof setTimeout> | undefined;
let request: AbortController | null = null;
let version = 0;

function cancel() {
    clearTimeout(timer);
    request?.abort();
    request = null;
    version++;
}

async function search(term: string, currentVersion: number) {
    const controller = new AbortController();
    request = controller;

    try {
        const response = await fetch(`/devices/search?${new URLSearchParams({ q: term })}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        });
        if (response.redirected || response.status === 401)
            throw new Error('Your session ended. Reload this page to sign in.');
        if (response.status === 429)
            throw new Error('Please wait a moment, then try your search again.');
        if (!response.ok) throw new Error('Device search is unavailable. Please try again.');

        const data = (await response.json()) as { devices: SearchDevice[]; has_more: boolean };
        if (!open.value || currentVersion !== version) return;
        results.value = data.devices;
        hasMore.value = data.has_more;
        activeIndex.value = 0;
    } catch (failure) {
        if (controller.signal.aborted || currentVersion !== version || !open.value) return;
        error.value =
            failure instanceof Error
                ? failure.message
                : 'Device search is unavailable. Please try again.';
    } finally {
        if (currentVersion === version) loading.value = false;
    }
}

watch([query, open], () => {
    cancel();
    results.value = [];
    hasMore.value = false;
    error.value = '';
    activeIndex.value = 0;
    const term = query.value.trim();
    loading.value = open.value && term.length >= 2;
    if (!loading.value) return;
    const currentVersion = version;
    timer = setTimeout(() => search(term, currentVersion), 250);
});

onUnmounted(cancel);

async function openSearch() {
    query.value = '';
    open.value = true;
    await nextTick();
    await nextTick();
    input.value?.focus();
}

async function closeSearch() {
    open.value = false;
    query.value = '';
    cancel();
    await nextTick();
    await nextTick();
    trigger.value?.focus();
}

function retry() {
    cancel();
    loading.value = true;
    error.value = '';
    search(query.value.trim(), version);
}

function move(direction: number) {
    if (!results.value.length) return;
    activeIndex.value =
        (activeIndex.value + direction + results.value.length) % results.value.length;
    nextTick(() =>
        document.getElementById(activeId.value || '')?.scrollIntoView({ block: 'nearest' }),
    );
}

function choose(device = results.value[activeIndex.value]) {
    if (!device || loading.value) return;
    open.value = false;
    cancel();
    emit('navigate');
    router.visit(device.url);
}
</script>

<template>
    <div class="device-search">
        <button
            ref="trigger"
            type="button"
            class="device-search-trigger"
            @click="openSearch"
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.7"
                aria-hidden="true"
            >
                <circle
                    cx="10.5"
                    cy="10.5"
                    r="6.5"
                />
                <path d="m16 16 4 4" />
            </svg>
            Search device
        </button>

        <AppModal
            :open="open"
            title="Find a device"
            @close="closeSearch"
            @keydown.esc.prevent.stop="closeSearch"
        >
            <p
                class="device-search-help"
                id="device-search-help"
            >
                Search by asset ID, name, location, or branch. Choose a device to open its
                dashboard.
            </p>
            <label class="device-search-field">
                <span class="rw-sr-only">Search devices</span>
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    aria-hidden="true"
                >
                    <circle
                        cx="10.5"
                        cy="10.5"
                        r="6.5"
                    />
                    <path d="m16 16 4 4" />
                </svg>
                <input
                    ref="input"
                    v-model="query"
                    type="search"
                    placeholder="Asset ID, device, or branch"
                    maxlength="100"
                    autocomplete="off"
                    autofocus
                    role="combobox"
                    aria-autocomplete="list"
                    aria-controls="device-search-results"
                    :aria-expanded="!!results.length"
                    :aria-activedescendant="activeId"
                    aria-describedby="device-search-help"
                    @keydown.down.prevent="move(1)"
                    @keydown.up.prevent="move(-1)"
                    @keydown.enter.prevent="choose()"
                />
            </label>
            <p
                v-if="error"
                class="device-search-error"
                role="alert"
            >
                {{ error }}
                <button
                    type="button"
                    class="rw-link"
                    @click="retry"
                >
                    Retry
                </button>
            </p>
            <p
                v-else-if="loading"
                class="device-search-message"
                role="status"
            >
                Searching devices...
            </p>
            <p
                v-else-if="query.trim().length < 2"
                class="device-search-message"
            >
                Type at least 2 characters to search your assigned branches.
            </p>
            <p
                v-else-if="!results.length"
                class="device-search-message"
                role="status"
            >
                No matching devices in your assigned branches.
            </p>
            <p
                v-else
                class="device-search-count"
                role="status"
            >
                {{ results.length }} {{ results.length === 1 ? 'device' : 'devices' }} found{{
                    hasMore ? ' — refine your search for more matches' : ''
                }}.
            </p>

            <ul
                id="device-search-results"
                class="device-search-results"
                role="listbox"
                aria-label="Matching devices"
                :aria-busy="loading"
            >
                <li
                    v-for="(device, index) in results"
                    :key="device.id"
                    role="presentation"
                >
                    <button
                        :id="`device-search-${device.id}`"
                        type="button"
                        role="option"
                        :aria-selected="activeIndex === index"
                        tabindex="-1"
                        @mousedown.prevent
                        @mousemove="activeIndex = index"
                        @click="choose(device)"
                    >
                        <span class="device-search-result-copy"
                            ><strong>{{ device.asset_id }} &middot; {{ device.name }}</strong
                            ><small>{{ device.branch }} &middot; {{ device.type }}</small></span
                        >
                        <StatusBadge :status="device.status" /><span
                            class="device-search-arrow"
                            aria-hidden="true"
                            >&rarr;</span
                        >
                    </button>
                </li>
            </ul>
            <p
                v-if="results.length"
                class="device-search-hint"
            >
                <span aria-hidden="true">&uarr; &darr;</span> to navigate &middot; Enter to open
                dashboard
            </p>
        </AppModal>
    </div>
</template>

<style scoped>
.device-search {
    flex: none;
    margin-bottom: 12px;
}
.device-search-trigger {
    display: flex;
    align-items: center;
    gap: 9px;
    width: 100%;
    min-height: 36px;
    padding: 8px 10px;
    border: 1px solid #edbba64d;
    border-radius: 9px;
    background: #ffffff0a;
    color: #f5d7c7;
    font-size: 12px;
}
.device-search-trigger:hover {
    border-color: #edbba6;
    background: #702421;
}
.device-search svg {
    flex: none;
    width: 17px;
    height: 17px;
}
.device-search-help {
    margin: 0 0 18px;
    color: #806b65;
    line-height: 1.7;
}
.device-search-field {
    position: relative;
    display: block;
}
.device-search-field svg {
    position: absolute;
    left: 13px;
    top: 14px;
    color: #806b65;
}
.device-search-field input[type='search'] {
    width: 100%;
    height: 46px;
    min-height: 46px;
    margin-top: 0;
    padding: 11px 14px 11px 40px;
    border: 1px solid #dfcfc2;
    border-radius: 10px;
    background: #fff;
    color: #452626;
    font-size: 14px;
}
.device-search-message {
    margin: 20px 0;
    color: #806b65;
    line-height: 1.7;
}
.device-search-count {
    margin: 18px 0 10px;
    color: #806b65;
    line-height: 1.6;
}
.device-search-error {
    color: #b52525;
    line-height: 1.7;
}
.device-search-results {
    list-style: none;
    margin: 0;
    padding: 0;
    max-height: min(45dvh, 380px);
    overflow-y: auto;
}
.device-search-results button {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 14px 12px;
    border: 1px solid transparent;
    border-radius: 10px;
    background: transparent;
    color: #452626;
    text-align: left;
}
.device-search-results button[aria-selected='true'] {
    background: #fff1e3;
    border-color: #ead0b6;
}
.device-search-result-copy {
    flex: 1;
    min-width: 0;
}
.device-search-result-copy strong {
    display: block;
    font-size: 13px;
    font-weight: 550;
    overflow-wrap: anywhere;
}
.device-search-result-copy small {
    display: block;
    margin-top: 6px;
    color: #806b65;
    font-size: 11px;
}
.device-search-arrow {
    color: #a47b61;
}
.device-search-hint {
    margin: 16px 0 0;
    color: #806b65;
    line-height: 1.6;
}
@media (max-width: 600px) {
    .device-search-field input[type='search'] {
        font-size: 16px;
    }
    .device-search-results button {
        flex-wrap: wrap;
    }
    .device-search-result-copy {
        flex-basis: 100%;
    }
}
</style>
