<script setup lang="ts">
defineProps<{ errors: Record<string, string> }>();
</script>
<template>
    <div
        v-if="Object.keys(errors).length"
        class="rw-errors"
        role="alert"
    >
        <strong>Please review the following:</strong>
        <ul>
            <li
                v-for="(message, key) in errors"
                :key="key"
            >
                {{ message }}
            </li>
        </ul>
    </div>
</template>
