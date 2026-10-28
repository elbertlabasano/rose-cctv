<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import { defineStore } from 'pinia';
import DeviceSearch from '../components/DeviceSearch.vue';

const useNavigation = defineStore('navigation', { state: () => ({ open: false }) });

const navigation = useNavigation();

const menuButton = ref<HTMLButtonElement | null>(null);
const closeMenuButton = ref<HTMLButtonElement | null>(null);

async function openNavigation() {
    navigation.open = true;
    await nextTick();
    closeMenuButton.value?.focus();
}

async function closeNavigation() {
    navigation.open = false;
    await nextTick();
    menuButton.value?.focus();
}

const props = defineProps<{ section: string; title: string }>();

const page = usePage();

const user = computed(() => page.props.auth as { user: { name: string; role: string } });

const flash = computed(() => page.props.flash as { success?: string; error?: string });

const menuGroups = [
    {
        id: 'monitoring',
        label: 'Monitoring',
        items: [
            ['cameras', 'Cameras', 'cameras', '\u25a3'],
            ['checklist', 'Today\u2019s checklist', 'checklist', '\u2611'],
            ['evidence', 'Screenshot history', 'evidence', '\u25a7'],
            ['outages', 'Outage tracking', 'outages', '\u25f7'],
        ],
    },
    {
        id: 'cases',
        label: 'Cases & follow-ups',
        items: [
            ['incidents', 'Incidents', 'incidents', '\u25b3'],
            ['tickets', 'Tickets', 'tickets', '\u25a4'],
            ['reminders', 'Reminders', 'reminders', '\u2667'],
        ],
    },
    {
        id: 'management',
        label: 'Management',
        items: [
            ['branches', 'Branch dashboard', 'branches', '\u2302'],
            ['devices', 'Device management', 'devices', '\u25a5'],
            ['reports', 'Reports', 'reports', '\u2197'],
            ['users', 'User management', 'users', '\u2659'],
            ['backups', 'Manual backup', 'backups', '\u21e9'],
        ],
    },
];

const groups = computed(() =>
    menuGroups.map((group) => ({
        ...group,
        items: group.items.filter(
            ([id]) => !['users', 'backups'].includes(id) || user.value.user.role === 'admin',
        ),
    })),
);

const activeGroup = computed(
    () =>
        groups.value.find((group) => group.items.some(([id]) => id === props.section))?.id || null,
);

const expandedGroup = ref<string | null>('monitoring');

watch(
    () => props.section,
    () => {
        expandedGroup.value = activeGroup.value || 'monitoring';
    },
    { immediate: true },
);

function toggleGroup(id: string) {
    expandedGroup.value = expandedGroup.value === id ? null : id;
}
</script>
<template>
    <div
        class="rw-shell"
        @keydown.esc="closeNavigation"
    >
        <button
            v-if="navigation.open"
            class="rw-nav-scrim"
            aria-label="Close menu"
            type="button"
            @click="closeNavigation"
        ></button>
        <aside
            id="rw-navigation"
            aria-label="Workspace navigation"
            class="rw-sidebar"
            :class="{ open: navigation.open }"
        >
            <div class="rw-sidebar-menu-heading">
                <span>Navigation</span>
                <button
                    type="button"
                    ref="closeMenuButton"
                    aria-label="Close navigation menu"
                    @click="closeNavigation"
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                        aria-hidden="true"
                    >
                        <path d="m6 6 12 12M6 18 18 6" />
                    </svg>
                </button>
            </div>
            <Link
                href="/"
                class="rw-brand"
                aria-label="RoseWatch home"
            >
                <img
                    src="/brand/rose-bakeshop-logo.png"
                    alt="Rose Bakeshop"
                    width="90"
                    height="65"
                />
                <div class="rw-brand-copy">
                    <strong>RoseWatch</strong>
                    <small>CCTV OPERATIONS</small>
                </div>
            </Link>
            <DeviceSearch @navigate="navigation.open = false" />
            <nav aria-label="Main navigation">
                <Link
                    href="/dashboard"
                    class="rw-nav-link"
                    :class="{ active: section === 'overview' }"
                    :aria-current="section === 'overview' ? 'page' : undefined"
                    @click="navigation.open = false"
                >
                    <span aria-hidden="true">&#x25A6;</span>
                    Overview
                </Link>
                <div
                    v-for="group in groups"
                    :key="group.id"
                    class="rw-nav-group"
                >
                    <button
                        type="button"
                        class="rw-nav-group-button"
                        :class="{ current: activeGroup === group.id }"
                        :aria-expanded="expandedGroup === group.id"
                        :aria-controls="`rw-menu-${group.id}`"
                        @click="toggleGroup(group.id)"
                    >
                        <span class="rw-nav-group-label">
                            <svg
                                class="rw-nav-group-icon"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.6"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <template v-if="group.id === 'monitoring'">
                                    <rect
                                        x="3"
                                        y="4"
                                        width="18"
                                        height="13"
                                        rx="2"
                                    />
                                    <path d="M8 21h8m-4-4v4m-5-10h3l2-4 2 7 2-3h2" />
                                </template>
                                <template v-else-if="group.id === 'cases'">
                                    <path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                    <rect
                                        x="3"
                                        y="7"
                                        width="18"
                                        height="14"
                                        rx="2"
                                    />
                                    <path d="M3 12a22 22 0 0 0 18 0m-9 0v4" />
                                </template>
                                <template v-else>
                                    <path d="M4 6h4m4 0h8M4 12h10m4 0h2M4 18h2m4 0h10" />
                                    <circle
                                        cx="10"
                                        cy="6"
                                        r="2"
                                    />
                                    <circle
                                        cx="16"
                                        cy="12"
                                        r="2"
                                    />
                                    <circle
                                        cx="8"
                                        cy="18"
                                        r="2"
                                    />
                                </template>
                            </svg>
                            <span>{{ group.label }}</span>
                        </span>
                        <svg
                            class="rw-nav-group-chevron"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            aria-hidden="true"
                        >
                            <path d="m9 5 7 7-7 7" />
                        </svg>
                    </button>
                    <div
                        v-show="expandedGroup === group.id"
                        :id="`rw-menu-${group.id}`"
                        class="rw-nav-submenu"
                    >
                        <Link
                            v-for="[id, label, path, icon] in group.items"
                            :key="id"
                            :href="'/' + path"
                            class="rw-nav-link"
                            :class="{ active: section === id }"
                            :aria-current="section === id ? 'page' : undefined"
                            @click="navigation.open = false"
                        >
                            <span aria-hidden="true">{{ icon }}</span>
                            {{ label }}
                        </Link>
                    </div>
                </div>
                <Link
                    href="/process"
                    class="rw-nav-link rw-nav-help"
                    :class="{ active: section === 'process' }"
                    :aria-current="section === 'process' ? 'page' : undefined"
                    @click="navigation.open = false"
                >
                    <span aria-hidden="true">&#x21E2;</span>
                    Process flow
                </Link>
            </nav>
            <div class="rw-sidebar-foot">
                <button
                    type="button"
                    class="rw-signout"
                    :aria-label="`Sign out ${user.user.name}`"
                    :title="user.user.name"
                    @click="router.post('/logout')"
                >
                    <span class="rw-signout-name">{{ user.user.name }}</span>
                    <span class="rw-signout-action">
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.6"
                            aria-hidden="true"
                        >
                            <path d="M9 5H5v14h4m5-14 7 7-7 7M8 12h13" />
                        </svg>
                        Sign out
                    </span>
                </button>
                <img
                    src="/brand/rose-waves.svg"
                    alt=""
                />
            </div>
        </aside>
        <button
            v-if="!navigation.open"
            type="button"
            class="rw-mobile-toggle"
            ref="menuButton"
            aria-label="Open menu"
            aria-controls="rw-navigation"
            :aria-expanded="navigation.open"
            @click="openNavigation"
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                aria-hidden="true"
            >
                <path d="M4 6h16M4 12h16M4 18h16" />
            </svg>
            Menu
        </button>
        <main class="rw-main">
            <div class="rw-content">
                <div
                    v-if="flash?.success"
                    class="rw-flash"
                    role="status"
                >
                    {{ flash.success }}
                </div>
                <div
                    v-if="flash?.error"
                    class="rw-errors"
                    role="alert"
                >
                    {{ flash.error }}
                </div>
                <slot />
            </div>
        </main>
    </div>
</template>
