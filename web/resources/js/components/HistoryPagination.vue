<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { HistoryPage } from '../device-dashboard';

defineProps<{ page: HistoryPage<unknown> }>();
</script>

<template>
    <nav
        v-if="page.total"
        class="dd-pagination"
        aria-label="History pages"
    >
        <p>{{ page.from }}–{{ page.to }} of {{ page.total }}</p>
        <div class="rw-inline-actions">
            <Link
                v-if="page.prev_page_url"
                :href="page.prev_page_url"
                class="rw-btn"
                preserve-scroll
                >Previous</Link
            >
            <span>Page {{ page.current_page }} of {{ page.last_page }}</span>
            <Link
                v-if="page.next_page_url"
                :href="page.next_page_url"
                class="rw-btn"
                preserve-scroll
                >Next</Link
            >
        </div>
    </nav>
</template>

<style scoped>
.dd-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    padding: 16px 20px;
    border-top: 1px solid #ecdcd0;
    color: #806b65;
    font-size: 12px;
}
.dd-pagination p {
    margin: 0;
}
</style>
