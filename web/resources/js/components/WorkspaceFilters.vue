<script setup lang="ts">
import { computed } from 'vue';
import type { Branch } from '../types';

const props = defineProps<{
    section: string;
    branches: Branch[];
    date: string;
    today: string;
}>();

const branch = defineModel<string>('branch', { required: true });
const search = defineModel<string>('search', { required: true });
const status = defineModel<string>('status', { required: true });

const emit = defineEmits<{ dateChange: [event: Event] }>();

const hasDate = computed(() =>
    ['overview', 'evidence', 'reports', 'tickets'].includes(props.section),
);

const searchPlaceholder = computed(() => {
    if (props.section === 'users') return 'Name, email, or role';
    if (props.section === 'branches') return 'Branch name, code, or address';
    if (props.section === 'incidents') return 'Incident, camera, or branch';
    if (props.section === 'reminders') return 'Search reminders';
    return 'Camera, device, or branch';
});
</script>

<template>
    <div
        v-if="branches.length"
        class="rw-card-filters"
        role="group"
        :aria-label="`${section} filters`"
    >
        <label v-if="section !== 'users'">
            Branch
            <select v-model="branch">
                <option value="all">All branches</option>
                <option
                    v-for="item in branches"
                    :key="item.id"
                    :value="String(item.id)"
                >
                    {{ item.name }}
                </option>
            </select>
        </label>
        <label v-if="section === 'devices'">
            Show
            <select v-model="status">
                <option value="all">All statuses</option>
                <option value="online">Online</option>
                <option value="offline">Offline</option>
                <option value="degraded">Degraded</option>
                <option value="maintenance">Maintenance</option>
                <option value="unknown">Unknown</option>
            </select>
        </label>
        <slot />
        <label v-if="hasDate">
            Date
            <input
                type="date"
                :value="date"
                :max="today"
                @change="emit('dateChange', $event)"
            />
        </label>
        <label
            v-if="section !== 'overview'"
            class="rw-card-filter-search"
        >
            <span class="rw-sr-only">{{ searchPlaceholder }}</span>
            <input
                v-model="search"
                type="search"
                :placeholder="searchPlaceholder"
            />
        </label>
    </div>
</template>
