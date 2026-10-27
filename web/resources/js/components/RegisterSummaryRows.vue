<script setup lang="ts">
import { computed } from 'vue';
import type { Device, Observation } from '../types';
import { observationStatus } from '../observation-status';

const props = defineProps<{
    devices: Device[];
    dates: string[];
    observations: Observation[];
    today: string;
}>();
const rows = [
    { key: 'online', label: 'Online' },
    { key: 'offline', label: 'Offline' },
    { key: 'no_device', label: 'No device' },
    { key: 'day_off', label: 'Day off / holiday' },
    { key: 'recorded', label: 'Total recorded' },
    { key: 'unchecked', label: 'Not checked' },
];
const summaries = computed(() => {
    const records = new Map(
        props.observations.map((record) => [`${record.device_id}:${record.date}`, record]),
    );
    return new Map(
        props.dates.map((date) => {
            const result: Record<string, number> = {
                online: 0,
                offline: 0,
                no_device: 0,
                day_off: 0,
                recorded: 0,
                unchecked: 0,
            };
            for (const device of props.devices) {
                const status = observationStatus(records.get(`${device.id}:${date}`));
                if (status === 'unchecked') result.unchecked++;
                else {
                    result.recorded++;
                    if (status === 'holiday' || status === 'day_off') result.day_off++;
                    else if (status in result) result[status]++;
                }
            }
            return [date, result];
        }),
    );
});
</script>

<template>
    <tfoot
        class="rw-daily-summary"
        aria-label="Daily summary for the visible cameras"
    >
        <tr
            v-for="row in rows"
            :key="row.key"
            :class="`summary-${row.key}`"
        >
            <th
                class="sticky-camera"
                scope="row"
            >
                {{ row.label }}
            </th>
            <td
                v-for="date in dates"
                :key="date"
                :class="{ future: date > today }"
                :aria-label="`${date}: ${row.label}, ${date > today ? 'future date' : summaries.get(date)?.[row.key] || 0}`"
            >
                {{ date > today ? '—' : summaries.get(date)?.[row.key] || 0 }}
            </td>
        </tr>
    </tfoot>
</template>

<style scoped>
.rw-daily-summary th.sticky-camera {
    padding: 7px 16px;
    font-size: 11px;
    font-weight: 650;
    background: #fff8f1;
}
.rw-daily-summary td {
    padding: 7px 4px;
    text-align: center;
    font-size: 11px;
    font-weight: 600;
}
.summary-online td {
    background: #e7f3ed;
    color: #2b7868;
}
.summary-offline td {
    background: #f8e9e8;
    color: #b84141;
}
.summary-no_device td {
    background: #eaf0f7;
    color: #426282;
}
.summary-day_off td {
    background: #fff6d7;
    color: #8a691d;
}
.summary-recorded td {
    background: #fbecd2;
    color: #703d1c;
}
.summary-unchecked td {
    background: #f5f6f4;
    color: #82918e;
    border-bottom: 2px solid #dac7b7;
}
.rw-daily-summary td.future {
    background: #f7f6f3;
    color: #a5aaa7;
}
</style>
