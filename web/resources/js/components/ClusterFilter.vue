<script setup lang="ts">
import type { Cluster } from '../types';

defineProps<{ clusters: Cluster[]; compact?: boolean }>();
const cluster = defineModel<string>({ required: true });
</script>

<template>
    <label>
        <span :class="{ 'rw-sr-only': compact }">Cluster</span>
        <select
            v-model="cluster"
            aria-label="Filter by cluster"
        >
            <option value="all">All clusters</option>
            <option value="clustered">Assigned clusters</option>
            <option
                v-for="item in clusters"
                :key="item.id"
                :value="String(item.id)"
            >
                {{ item.name }}
            </option>
            <option value="unassigned">Unassigned branches</option>
        </select>
    </label>
</template>
