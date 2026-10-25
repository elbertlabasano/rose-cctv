<script setup lang="ts">
import { computed, ref } from 'vue';
import type { DeviceDay } from '../device-dashboard';
import type { Observation } from '../types';

const props = defineProps<{ days: DeviceDay[] }>();

const emit = defineEmits<{ inspect: [record: Observation] }>();

const selected = ref<DeviceDay | null>(null);

const labels: Record<string, string> = {
    online: 'Online',
    offline: 'Offline',
    degraded: 'Degraded',
    maintenance: 'Maintenance',
    unknown: 'Unknown',
    unchecked: 'Not checked',
};

const distribution = computed(() =>
    Object.entries(labels).map(([status, label]) => ({
        status,
        label,
        count: props.days.filter((day) => day.status === status).length,
    })),
);

const checked = computed(() => props.days.filter((day) => day.status !== 'unchecked').length);
const maxMinutes = computed(() => Math.max(1, ...props.days.map((day) => day.downtime_minutes)));
const totalMinutes = computed(() => props.days.reduce((sum, day) => sum + day.downtime_minutes, 0));

function dayLabel(value: string) {
    return new Date(`${value}T12:00:00Z`).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });
}

function inspect(day: DeviceDay) {
    selected.value = day;
    if (day.record) emit('inspect', day.record);
}
</script>

<template>
    <section
        class="device-trends"
        aria-label="Device observation trends"
    >
        <div class="trend-heading">
            <div>
                <h2>Daily status observations</h2>
                <p>{{ checked }} of {{ days.length }} days checked in this period</p>
            </div>
        </div>
        <div
            class="trend-days"
            aria-label="Daily observation chart"
        >
            <button
                v-for="day in days"
                :key="day.date"
                type="button"
                class="trend-day"
                :class="day.status"
                :aria-pressed="selected?.date === day.date"
                :aria-label="`${dayLabel(day.date)}: ${labels[day.status] || day.status}. ${day.record ? 'View saved record.' : 'No daily record.'}`"
                :title="`${dayLabel(day.date)}: ${labels[day.status] || day.status}`"
                @click="inspect(day)"
            >
                {{ Number(day.date.slice(8))
                }}<span
                    v-if="day.record?.attachments.length"
                    class="trend-evidence"
                    aria-hidden="true"
                ></span>
            </button>
        </div>
        <p
            v-if="selected"
            class="trend-selection"
            role="status"
        >
            {{ dayLabel(selected.date) }}: {{ labels[selected.status] || selected.status }}.
            {{ selected.record ? 'Saved daily record selected.' : 'No daily record saved.' }}
        </p>
        <ul
            class="trend-legend"
            aria-label="Observation distribution"
        >
            <li
                v-for="item in distribution"
                :key="item.status"
            >
                <i :class="item.status"></i>{{ item.label }} <strong>{{ item.count }}</strong>
            </li>
        </ul>

        <div class="trend-downtime-heading">
            <h2>Recorded downtime</h2>
            <p>{{ Math.round(totalMinutes).toLocaleString() }} minutes in this period</p>
        </div>
        <div
            class="trend-bars"
            role="img"
            :aria-label="`Recorded downtime by day. ${Math.round(totalMinutes)} total minutes. Maximum daily value ${Math.round(maxMinutes)} minutes. See chart data below for exact values.`"
        >
            <div
                v-for="day in days"
                :key="day.date"
                class="trend-bar-slot"
                :title="`${dayLabel(day.date)}: ${day.downtime_minutes} minutes`"
            >
                <span :style="{ height: `${(day.downtime_minutes / maxMinutes) * 100}%` }"></span>
            </div>
        </div>
        <div class="trend-axis">
            <span>{{ dayLabel(days[0]?.date || '') }}</span
            ><span>{{ dayLabel(days[days.length - 1]?.date || '') }}</span>
        </div>
        <p class="trend-note">
            These charts show saved checks and recorded outage intervals. Blank days are not assumed
            online. Downtime is capped at the current time for open outages.
        </p>
        <details class="trend-data">
            <summary>View chart data</summary>
            <div class="rw-table-scroll">
                <table class="rw-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Observation</th>
                            <th>Downtime (minutes)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="day in days"
                            :key="day.date"
                        >
                            <td>{{ day.date }}</td>
                            <td>{{ labels[day.status] || day.status }}</td>
                            <td>{{ day.downtime_minutes }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>
    </section>
</template>

<style scoped>
.device-trends {
    padding: 22px;
    min-width: 0;
}
.trend-heading p,
.trend-downtime-heading p {
    margin: 6px 0 0;
    color: #806b65;
}
.trend-days {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(34px, 1fr));
    gap: 6px;
    margin-top: 22px;
}
.trend-day {
    position: relative;
    min-height: 38px;
    border: 1px solid transparent;
    border-radius: 7px;
    font: inherit;
    font-size: 12px;
}
.trend-day:hover,
.trend-day[aria-pressed='true'] {
    outline: 2px solid #bd8534;
    outline-offset: 1px;
}
.trend-evidence {
    position: absolute;
    right: 4px;
    bottom: 4px;
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: currentColor;
}
.online {
    background: #e5f5ef;
    color: #18715e;
}
.offline {
    background: #fbe8e8;
    color: #b52525;
}
.degraded {
    background: #fff2d8;
    color: #916014;
}
.maintenance {
    background: #ebf0fc;
    color: #395d9a;
}
.unknown {
    background: #e8edef;
    color: #5b6b73;
}
.unchecked {
    background: #f5f3ef;
    color: #81766a;
}
.trend-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 10px 16px;
    list-style: none;
    padding: 0;
    margin: 18px 0;
}
.trend-legend li {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    color: #806b65;
}
.trend-legend i {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 3px;
    border: 1px solid currentColor;
}
.trend-legend strong {
    font-weight: 550;
    color: #452626;
}
.trend-selection {
    color: #614940;
    line-height: 1.6;
}
.trend-downtime-heading {
    border-top: 1px solid #eaded3;
    margin-top: 24px;
    padding-top: 22px;
}
.trend-bars {
    display: flex;
    align-items: end;
    gap: 3px;
    height: 110px;
    border-bottom: 1px solid #eaded3;
    margin-top: 24px;
}
.trend-bar-slot {
    display: flex;
    align-items: end;
    flex: 1;
    min-width: 0;
    height: 100%;
    background: #fffaf5;
}
.trend-bar-slot span {
    width: 100%;
    background: #9c4b3f;
    border-radius: 3px 3px 0 0;
}
.trend-axis {
    display: flex;
    justify-content: space-between;
    margin-top: 7px;
    font-size: 10px;
    color: #806b65;
}
.trend-note {
    color: #806b65;
    line-height: 1.7;
    margin: 18px 0;
}
.trend-data summary {
    cursor: pointer;
    color: #5d1616;
    font-size: 12px;
    padding: 6px 0;
}
.trend-data .rw-table-scroll {
    margin-top: 12px;
}
@media (max-width: 600px) {
    .device-trends {
        padding: 16px;
    }
}
</style>
