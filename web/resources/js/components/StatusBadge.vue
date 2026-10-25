<script setup lang="ts">defineProps<{status:string|null|undefined}>();</script>
<template><span class="rw-status" :class="status||'unknown'">{{ status ? status.replaceAll('_',' ') : 'Not checked' }}</span></template>
