<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch, computed } from 'vue';
import type { Device, Workspace } from '../types';
import AppModal from './AppModal.vue';
import FormErrors from './FormErrors.vue';

const props = defineProps<{ open: boolean; workspace: Workspace }>();

const emit = defineEmits<{ close: [] }>();

const form = useForm({
    device_id: '',
    title: '',
    description: '',
    priority: 'medium',
    category: 'Camera offline',
    assigned_to: '',
    signed_document_url: '',
});

const chosen = computed(() => props.workspace.devices.find((d) => d.id === Number(form.device_id)));

const people = computed(() =>
    props.workspace.people.filter(
        (p) =>
            p.role === 'admin' ||
            p.all_branches ||
            p.branches.some((b) => b.id === chosen.value?.branch_id),
    ),
);

watch(
    () => props.open,
    (open) => {
        if (open) {
            form.reset();
            form.clearErrors();
        }
    },
);
</script>
<template>
    <AppModal
        :open="open"
        title="Create incident report"
        :busy="form.processing"
        @close="emit('close')"
        ><form @submit.prevent="form.post('/incidents', { onSuccess: () => emit('close') })">
            <FormErrors :errors="form.errors" />
            <div class="rw-form-grid">
                <label
                    >Camera / device *<select
                        v-model="form.device_id"
                        required
                        @change="form.assigned_to = ''"
                    >
                        <option value="">Select device</option>
                        <option
                            v-for="d in workspace.devices"
                            :key="d.id"
                            :value="String(d.id)"
                        >
                            {{ d.asset_id }} · {{ d.branch.name }}
                        </option>
                    </select></label
                ><label
                    >Priority<select v-model="form.priority">
                        <option
                            v-for="p in ['critical', 'high', 'medium', 'low']"
                            :key="p"
                        >
                            {{ p }}
                        </option>
                    </select></label
                ><label class="rw-full"
                    >Incident summary *<input
                        v-model="form.title"
                        required
                        maxlength="180" /></label
                ><label
                    >Category<select v-model="form.category">
                        <option
                            v-for="c in [
                                'Camera offline',
                                'Unstable video',
                                'Network fault',
                                'Power issue',
                                'Storage issue',
                                'Other',
                            ]"
                            :key="c"
                        >
                            {{ c }}
                        </option>
                    </select></label
                ><label
                    >Assign to<select v-model="form.assigned_to">
                        <option value="">Unassigned</option>
                        <option
                            v-for="p in people"
                            :key="p.id"
                            :value="String(p.id)"
                        >
                            {{ p.name }}
                        </option>
                    </select></label
                ><label class="rw-full"
                    >Details / initial observation<textarea
                        v-model="form.description"
                        maxlength="4000"
                    ></textarea></label
                ><label class="rw-full"
                    >Signed incident report document URL<input
                        v-model="form.signed_document_url"
                        type="url"
                        maxlength="2000"
                        placeholder="https://.../signed-report.pdf"
                /></label>
            </div>
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
                    {{ form.processing ? 'Creating…' : 'Create incident' }}
                </button>
            </div>
        </form></AppModal
    >
</template>
