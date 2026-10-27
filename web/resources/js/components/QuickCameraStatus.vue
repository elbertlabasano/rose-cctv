<script setup lang="ts">
import axios from 'axios';
import { onUnmounted, ref } from 'vue';
import type { Device, Observation, QuickStatusResult } from '../types';

const props = defineProps<{ device: Device; date: string; record: Observation | null }>();
const emit = defineEmits<{ saved: [result: QuickStatusResult] }>();
const busy = ref(false);
const error = ref('');
const savedStatus = ref('');
let request: AbortController | null = null;
let active = true;

function choose(event: Event) {
    const checkbox = event.target as HTMLInputElement;
    const status = checkbox.checked ? 'online' : 'offline';
    // Keep the saved selection until the server confirms the requested status.
    checkbox.checked = props.record?.status === 'online';
    void save(status);
}

async function save(status: 'online' | 'offline') {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    savedStatus.value = '';
    request = new AbortController();
    try {
        const response = await axios.post<QuickStatusResult>(
            '/observations/quick-status',
            {
                device_id: props.device.id,
                date: props.date,
                status,
            },
            { signal: request.signal, headers: { Accept: 'application/json' } },
        );
        if (!active) return;
        if (
            response.data.device?.id !== props.device.id ||
            response.data.observation?.device_id !== props.device.id ||
            response.data.observation.date !== props.date ||
            response.data.observation.status !== status
        ) {
            throw new Error('Save was not confirmed.');
        }
        emit('saved', response.data);
        savedStatus.value = status;
    } catch (failure) {
        if (!active || axios.isCancel(failure)) return;
        const code = axios.isAxiosError(failure) ? failure.response?.status : null;
        if (code === 422 && axios.isAxiosError(failure)) {
            const messages = Object.values(failure.response?.data?.errors || {}).flat();
            error.value =
                typeof messages[0] === 'string'
                    ? messages[0]
                    : 'Unable to save. Refresh and try again.';
        } else if (code === 401 || code === 419) {
            error.value = 'Your session expired. Refresh to sign in again.';
        } else if (code === 403) {
            error.value = 'You no longer have access. Refresh this page.';
        } else {
            error.value = 'Save not confirmed. Refresh or try again.';
        }
    } finally {
        if (active) busy.value = false;
    }
}

onUnmounted(() => {
    active = false;
    request?.abort();
});
</script>

<template>
    <div
        class="quick-status"
        :aria-busy="busy"
    >
        <div class="quick-options">
            <label
                class="quick-choice"
                :class="{ offline: record?.status !== 'online' }"
                title="Checked = Online. Unchecked = Offline. Changes save immediately."
            >
                <input
                    type="checkbox"
                    :checked="record?.status === 'online'"
                    :disabled="busy"
                    :aria-label="`${device.asset_id} online for ${date}`"
                    @change="choose"
                />
            </label>
        </div>
        <small
            v-if="busy"
            class="quick-progress"
            role="status"
            >Saving…</small
        >
        <small
            v-if="error"
            class="quick-error"
            role="alert"
            >{{ error }}</small
        >
        <span
            class="rw-sr-only"
            role="status"
            aria-live="polite"
        >
            {{ savedStatus ? `${device.asset_id} saved as ${savedStatus}.` : '' }}
        </span>
    </div>
</template>

<style scoped>
.quick-status {
    position: relative;
    padding: 2px 6px 0;
}
.quick-options {
    display: flex;
    justify-content: center;
    gap: 10px;
}
.quick-status .quick-choice {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    min-height: 28px;
    gap: 5px;
    color: #226d57;
    font-size: 11px;
    font-weight: 500;
    white-space: nowrap;
    cursor: pointer;
}
.quick-status .quick-choice.offline {
    color: #b13936;
}
.quick-choice input {
    appearance: auto;
    width: 15px;
    height: 15px;
    margin: 0;
    padding: 0;
    accent-color: #2e7a63;
    cursor: pointer;
}
.quick-choice.offline input {
    accent-color: #bb4540;
}
.quick-choice input:focus-visible {
    outline: 2px solid #65201c;
    outline-offset: 2px;
}
.quick-choice input:disabled {
    cursor: wait;
    opacity: 0.65;
}
.quick-progress,
.quick-error {
    display: block;
    padding-top: 5px;
    font-size: 10px;
    line-height: 1.4;
}
.quick-error {
    color: #ad2f2a;
}
@media (max-width: 600px) {
    .quick-status .quick-choice {
        min-width: 44px;
        min-height: 44px;
    }
}
</style>
