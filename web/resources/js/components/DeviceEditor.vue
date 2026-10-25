<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch, computed } from 'vue';
import type { Branch, Device } from '../types';
import AppModal from './AppModal.vue';
import FormErrors from './FormErrors.vue';

const props = defineProps<{
    open: boolean;
    device: Device | null;
    branches: Branch[];
    devices: Device[];
}>();

const emit = defineEmits<{ close: [] }>();

const form = useForm({
    asset_id: '',
    name: '',
    branch_id: '',
    type: 'DVR',
    status: 'unknown',
    location: '',
    brand: '',
    model: '',
    serial: '',
    ip: '',
    owner: 'CCTV Operations',
    parent_id: '',
    channel: '',
    channels: '16',
    storage_tb: '4',
    disk_health: 'Unknown',
    installed_at: '',
    warranty_at: '',
    notes: '',
    reason: '',
});

const recorder = computed(() => ['DVR', 'NVR'].includes(form.type));

const parents = computed(() =>
    props.devices.filter(
        (d) => d.id !== props.device?.id && d.branch_id === Number(form.branch_id),
    ),
);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.reset();
        form.clearErrors();
        form.branch_id = String(props.branches[0]?.id || '');
        if (props.device) {
            Object.keys(form.data()).forEach((key) => {
                if (key in props.device!)
                    (form as unknown as Record<string, unknown>)[key] = String(
                        (props.device as unknown as Record<string, unknown>)[key] ?? '',
                    );
            });
            form.reason = '';
        }
    },
);

const types = [
    'DVR',
    'NVR',
    'IP Camera',
    'Analog Camera',
    'PoE Switch',
    'Router',
    'UPS',
    'HDD / Storage',
    'Power Supply',
    'Other',
];

function save() {
    if (!recorder.value) {
        form.channels = '';
        form.storage_tb = '';
        form.disk_health = '';
    }
    if (!form.parent_id) form.channel = '';
    const options = { onSuccess: () => emit('close') };
    props.device
        ? form.put(`/devices/${props.device.id}`, options)
        : form.post('/devices', options);
}
</script>
<template>
    <AppModal
        :open="open"
        :title="device ? 'Edit device / mark status' : 'Add device'"
        :busy="form.processing"
        @close="emit('close')"
        ><form @submit.prevent="save">
            <FormErrors :errors="form.errors" />
            <details
                class="rw-accordion"
                open
            >
                <summary>Device details <small>Identity, branch and manufacturer</small></summary>
                <div class="rw-form-grid">
                    <label
                        >Asset ID *<input
                            v-model="form.asset_id"
                            required
                            :readonly="!!device"
                            maxlength="50" /></label
                    ><label
                        >Device name *<input
                            v-model="form.name"
                            required
                            maxlength="100" /></label
                    ><label
                        >Device type<select v-model="form.type">
                            <option
                                v-for="type in types"
                                :key="type"
                            >
                                {{ type }}
                            </option>
                        </select></label
                    ><label
                        >Branch<select
                            v-model="form.branch_id"
                            required
                        >
                            <option
                                v-for="branch in branches"
                                :key="branch.id"
                                :value="String(branch.id)"
                            >
                                {{ branch.name }}
                            </option>
                        </select></label
                    ><label
                        >Location<input
                            v-model="form.location"
                            maxlength="255" /></label
                    ><label
                        >Brand<input
                            v-model="form.brand"
                            maxlength="255" /></label
                    ><label
                        >Model<input
                            v-model="form.model"
                            maxlength="255" /></label
                    ><label
                        >Serial number<input
                            v-model="form.serial"
                            maxlength="255"
                    /></label>
                </div>
            </details>
            <details class="rw-accordion">
                <summary>
                    Connections & storage <small>Network and recorder settings</small>
                </summary>
                <div class="rw-form-grid">
                    <label
                        >IPv4 address<input
                            v-model="form.ip"
                            placeholder="192.168.1.20" /></label
                    ><label
                        >Connected to<select v-model="form.parent_id">
                            <option value="">Standalone</option>
                            <option
                                v-for="parent in parents"
                                :key="parent.id"
                                :value="String(parent.id)"
                            >
                                {{ parent.asset_id }} · {{ parent.name }}
                            </option>
                        </select></label
                    ><label v-if="['IP Camera', 'Analog Camera'].includes(form.type)"
                        >Recorder channel<input
                            v-model="form.channel"
                            type="number"
                            min="1"
                            max="256" /></label
                    ><template v-if="recorder"
                        ><label
                            >Channel capacity *<input
                                v-model="form.channels"
                                type="number"
                                min="1"
                                max="256" /></label
                        ><label
                            >Storage (TB)<input
                                v-model="form.storage_tb"
                                type="number"
                                min="0"
                                step="0.1" /></label
                        ><label
                            >Disk health<select v-model="form.disk_health">
                                <option>Unknown</option>
                                <option>Healthy</option>
                                <option>Warning</option>
                                <option>Failed</option>
                            </select></label
                        ></template
                    >
                </div>
            </details>
            <details
                class="rw-accordion"
                open
            >
                <summary>
                    Status & maintenance <small>State, ownership and change notes</small>
                </summary>
                <div class="rw-form-grid">
                    <label
                        >Status<select v-model="form.status">
                            <option
                                v-for="state in [
                                    'unknown',
                                    'online',
                                    'offline',
                                    'degraded',
                                    'maintenance',
                                ]"
                                :key="state"
                            >
                                {{ state }}
                            </option>
                        </select></label
                    ><label>Responsible team<input v-model="form.owner" /></label
                    ><label
                        >Installed<input
                            v-model="form.installed_at"
                            type="date" /></label
                    ><label
                        >Warranty ends<input
                            v-model="form.warranty_at"
                            type="date" /></label
                    ><label class="rw-full"
                        >Asset notes<textarea
                            v-model="form.notes"
                            maxlength="2000"
                        ></textarea></label
                    ><label class="rw-full"
                        >Reason / action taken *<textarea
                            v-model="form.reason"
                            required
                            maxlength="2000"
                        ></textarea>
                    </label>
                </div>
            </details>
            <div class="rw-form-actions">
                <button
                    type="button"
                    class="rw-btn"
                    :disabled="form.processing"
                    @click="emit('close')"
                >
                    Cancel</button
                ><button
                    class="rw-btn primary"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Saving…' : 'Save device' }}
                </button>
            </div>
        </form></AppModal
    >
</template>
