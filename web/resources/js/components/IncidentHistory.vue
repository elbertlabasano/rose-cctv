<script setup lang="ts">
import type { Evidence, IncidentLog } from '../types';

defineProps<{ logs: IncidentLog[] }>();

const emit = defineEmits<{ preview: [attachment: Evidence] }>();

const actionLabels: Record<string, string> = {
    log: 'Action log',
    document: 'Signed document',
    resolve: 'Resolution',
    verify: 'Verification',
    reopen: 'Reopened',
};

function label(value: string) {
    const words = value.replaceAll('_', ' ');
    return words.charAt(0).toUpperCase() + words.slice(1);
}

function timestamp(value: string) {
    const date = new Date(value);
    if (!Number.isFinite(date.getTime())) return 'Date unavailable';

    return `${new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
        timeZone: 'Asia/Manila',
    }).format(date)} PHT`;
}
</script>

<template>
    <section
        class="incident-history"
        aria-label="Incident action history"
    >
        <h3 class="history-heading">Action history ({{ logs.length }})</h3>
        <ol
            v-if="logs.length"
            class="history-timeline"
            role="list"
        >
            <li
                v-for="log in logs"
                :key="log.id"
                class="history-entry"
            >
                <strong class="history-title">
                    {{ actionLabels[log.action] || label(log.action) }} &middot;
                    {{ log.actor || 'Former user' }}
                </strong>
                <p class="history-notes">{{ log.notes }}</p>

                <span
                    v-if="log.details?.to && log.details.from !== log.details.to"
                    class="history-transition"
                >
                    <template v-if="log.details.from">
                        {{ label(log.details.from) }} &rarr;
                    </template>
                    {{ label(log.details.to) }}
                </span>

                <div
                    v-if="log.details?.resolution"
                    class="history-resolution"
                >
                    <p><span>Root cause:</span> {{ log.details.resolution.cause }}</p>
                    <p><span>Work performed:</span> {{ log.details.resolution.work }}</p>
                    <p v-if="log.details.resolution.prevention">
                        <span>Prevention:</span> {{ log.details.resolution.prevention }}
                    </p>
                </div>

                <div
                    v-if="log.attachments.length"
                    class="history-attachments"
                    role="group"
                    aria-label="Action evidence"
                >
                    <button
                        v-for="attachment in log.attachments"
                        :key="attachment.id"
                        type="button"
                        class="history-attachment"
                        :aria-label="`View attachment: ${attachment.name}`"
                        :title="attachment.name"
                        @click="emit('preview', attachment)"
                    >
                        <img
                            v-if="attachment.mime.startsWith('image/')"
                            :src="attachment.url"
                            alt=""
                            loading="lazy"
                            width="112"
                            height="72"
                        />
                        <span
                            v-else
                            class="history-file"
                        >
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.5"
                                aria-hidden="true"
                            >
                                <path d="M14 3H6v18h12V7l-4-4Zm0 0v5h4M9 12h6m-6 4h6" />
                            </svg>
                            PDF
                        </span>
                        <span class="history-filename">{{ attachment.name }}</span>
                    </button>
                </div>

                <time
                    class="history-time"
                    :datetime="log.created_at"
                >
                    {{ timestamp(log.created_at) }}
                </time>
            </li>
        </ol>
        <p
            v-else
            class="history-empty"
        >
            No actions recorded yet.
        </p>
    </section>
</template>

<style scoped>
.incident-history {
    margin-top: 24px;
}

.history-heading {
    margin: 0 0 18px;
    color: #806b65;
    font-size: 11px;
    font-weight: 650;
    letter-spacing: 0.8px;
    text-transform: uppercase;
}

.history-timeline {
    position: relative;
    margin: 0;
    padding: 0;
    list-style: none;
}

.history-timeline::before {
    content: '';
    position: absolute;
    top: 8px;
    bottom: 0;
    left: 9px;
    width: 1px;
    background: #d8e1e2;
}

.history-entry {
    position: relative;
    min-width: 0;
    padding: 0 0 23px 34px;
}

.history-entry:last-child {
    padding-bottom: 8px;
}

.history-entry::before {
    content: '';
    position: absolute;
    top: 2px;
    left: 2px;
    width: 15px;
    height: 15px;
    border: 3px solid #fff;
    border-radius: 50%;
    background: #3b8875;
    box-shadow: 0 0 0 1px #b7c8ca;
}

.history-title {
    display: block;
    color: #452626;
    font-size: 13px;
    font-weight: 600;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.history-notes {
    margin: 6px 0 0;
    color: #806b65;
    line-height: 1.65;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.history-transition {
    display: inline-block;
    margin-top: 7px;
    padding: 4px 8px;
    border-radius: 5px;
    background: #edf4f3;
    color: #452626;
    font-size: 11px;
    line-height: 1.4;
}

.history-resolution {
    margin-top: 8px;
}

.history-resolution p {
    margin: 4px 0 0;
    color: #806b65;
    line-height: 1.65;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.history-resolution span {
    color: #614940;
    font-weight: 500;
}

.history-attachments {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}

.history-attachment {
    width: 114px;
    max-width: 100%;
    overflow: hidden;
    padding: 0;
    border: 1px solid #eaded3;
    border-radius: 8px;
    background: #fff8f1;
    color: #74594e;
    text-align: left;
}

.history-attachment:hover {
    border-color: #b78b69;
    background: #ffeddc;
}

.history-attachment img,
.history-file {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 100%;
    height: 72px;
    object-fit: contain;
    font-size: 11px;
}

.history-file svg {
    width: 23px;
    height: 23px;
}

.history-filename {
    display: block;
    overflow: hidden;
    padding: 6px 8px;
    border-top: 1px solid #eaded3;
    font-size: 10px;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.history-time {
    display: block;
    margin-top: 7px;
    color: #849397;
    font-size: 10px;
    line-height: 1.5;
}

.history-empty {
    color: #806b65;
    line-height: 1.6;
}
</style>
