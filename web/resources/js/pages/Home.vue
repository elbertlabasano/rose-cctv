<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { nextTick, computed, ref } from 'vue';

const props = defineProps<{ can_open_workspace: boolean; year: number }>();
const entry = computed(() => (props.can_open_workspace ? '/dashboard' : '/login'));
const entryLabel = computed(() => (props.can_open_workspace ? 'Open workspace' : 'Sign in'));
const capabilities = [
    {
        id: 'monitoring',
        label: 'Daily camera checks',
        summary: 'A clear view, branch by branch.',
        title: 'Start with a simple daily check.',
        text: 'See your cameras by branch and cluster, then record what you checked today. The monthly register keeps previous observations in view.',
        steps: [
            'Find your branch or cluster.',
            'Check the camera and mark Online or Offline.',
            'Review the monthly status history.',
        ],
        note: 'Past dates stay read-only, so the history stays reliable.',
    },
    {
        id: 'evidence',
        label: 'Screenshots & notes',
        summary: 'Keep the context close.',
        title: 'Give the next person the full picture.',
        text: 'Keep multiple screenshots and observations together with the device and date, instead of looking for them in separate conversations.',
        steps: [
            'Open today’s camera record.',
            'Capture a screenshot or upload images.',
            'Add a note and save the selected actions together.',
        ],
        note: 'Image indicators make attached evidence easy to find in the register.',
    },
    {
        id: 'incidents',
        label: 'Incident reports',
        summary: 'A clear record of what happened.',
        title: 'Follow the concern through to resolution.',
        text: 'Record the incident, keep an action history, and attach the link to the signed report. Everyone can pick up where the last person left off.',
        steps: [
            'Record the incident against the device.',
            'Log the actions taken and supporting evidence.',
            'Link the signed report and record the resolution.',
        ],
        note: 'Resolving an incident is separate from marking a camera Online.',
    },
    {
        id: 'tickets',
        label: 'Support tickets',
        summary: 'Bring the right support into the loop.',
        title: 'Connect the camera record to support.',
        text: 'Request a ticket in IT Support Hub from a camera record, then view the linked ticket alongside the device’s history.',
        steps: [
            'Describe the concern and select a support board.',
            'Send the ticket request to IT Support Hub.',
            'View the confirmed ticket from the camera record.',
        ],
        note: 'Tickets are created and managed in IT Support Hub. Incident reports remain separate.',
    },
];
const selectedFeature = ref(0);
const tabButtons = ref<HTMLButtonElement[]>([]);

async function moveFeature(event: KeyboardEvent, index: number) {
    let target = index;
    if (event.key === 'ArrowDown' || event.key === 'ArrowRight')
        target = (index + 1) % capabilities.length;
    else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft')
        target = (index - 1 + capabilities.length) % capabilities.length;
    else if (event.key === 'Home') target = 0;
    else if (event.key === 'End') target = capabilities.length - 1;
    else return;
    event.preventDefault();
    selectedFeature.value = target;
    await nextTick();
    tabButtons.value[target]?.focus();
}

const routine = [
    { title: 'Check.', text: 'Review the camera and record today’s Online or Offline status.' },
    {
        title: 'Record.',
        text: 'Save screenshots and notes with the device, ready for the next person.',
    },
    {
        title: 'Follow up.',
        text: 'Log an incident or request a support ticket when something needs attention.',
    },
];
</script>

<template>
    <Head title="Care for every branch">
        <meta
            name="description"
            content="RoseWatch brings Rose Bakeshop camera checks, screenshots, incident reports and support follow-ups into one workspace."
        />
    </Head>
    <div class="home-page">
        <a
            class="home-skip"
            href="#home-main"
            >Skip to content</a
        >
        <header class="home-header home-container">
            <Link
                href="/"
                class="home-brand"
            >
                <img
                    src="/brand/rose-bakeshop-mark.webp"
                    alt="Rose Bakeshop"
                    width="62"
                    height="46"
                />
                <span><strong>RoseWatch</strong><small>CCTV OPERATIONS</small></span>
            </Link>
            <nav
                class="home-navigation"
                aria-label="Main navigation"
            >
                <a href="#workspace">The workspace</a>
                <a href="#routine">Your daily routine</a>
            </nav>
            <Link
                :href="entry"
                class="home-button home-button-outline"
                >{{ entryLabel }}<span aria-hidden="true">↗</span></Link
            >
        </header>

        <main id="home-main">
            <section
                class="home-hero home-container"
                aria-labelledby="home-title"
            >
                <div class="home-hero-copy">
                    <span class="home-eyebrow"
                        ><span
                            class="home-eyebrow-line"
                            aria-hidden="true"
                        ></span
                        >OUR PEOPLE. OUR PLACES.</span
                    >
                    <h1 id="home-title">A little care.<br />For every<br /><em>branch.</em></h1>
                    <p>
                        One place for daily camera checks, visual evidence, and the follow-ups that
                        keep our branches cared for.
                    </p>
                    <div class="home-hero-actions">
                        <Link
                            :href="entry"
                            class="home-button home-button-primary"
                            >{{ entryLabel }}<span aria-hidden="true">→</span></Link
                        >
                        <a
                            class="home-text-link"
                            href="#routine"
                            >See the daily routine <span aria-hidden="true">↓</span></a
                        >
                    </div>
                    <div class="home-hero-note">
                        <span aria-hidden="true">↳</span
                        ><span>Made for the people<br />who look after Rose.</span>
                    </div>
                </div>
                <figure class="home-hero-visual">
                    <img
                        class="home-hero-image"
                        src="/brand/homepage-bakery.webp"
                        srcset="
                            /brand/homepage-bakery-640.webp  640w,
                            /brand/homepage-bakery-960.webp  960w,
                            /brand/homepage-bakery.webp     1536w
                        "
                        sizes="(max-width: 359px) calc(100vw - 28px), (max-width: 767px) calc(100vw - 40px), (max-width: 1100px) calc((100vw - 48px) * 0.515), (max-width: 1400px) calc((100vw - 80px) * 0.515), 680px"
                        alt="An inviting bakery with fresh bread, burgundy counters and a discreet ceiling camera."
                        width="1536"
                        height="1024"
                        fetchpriority="high"
                    />
                    <span class="home-photo-label"
                        >ROSE BAKESHOP <span aria-hidden="true">/</span> SINCE 1983</span
                    >
                    <figcaption>
                        <span>MORE THAN A DAILY CHECK.</span
                        ><strong>A little peace<br />of mind.</strong>
                    </figcaption>
                    <img
                        class="home-waves"
                        src="/brand/rose-waves.svg"
                        alt=""
                        width="560"
                        height="28"
                    />
                </figure>
            </section>

            <div
                class="home-principles home-container"
                aria-label="Workspace benefits"
            >
                <div>
                    <span aria-hidden="true">01</span><strong>Know today’s status.</strong>
                    <p>Daily checks, clearly recorded.</p>
                </div>
                <div>
                    <span aria-hidden="true">02</span><strong>Keep the whole picture.</strong>
                    <p>Evidence connected to each device.</p>
                </div>
                <div>
                    <span aria-hidden="true">03</span><strong>Make the next step clear.</strong>
                    <p>Concerns with a follow-up history.</p>
                </div>
            </div>

            <section
                id="workspace"
                class="home-workspace home-container"
                aria-labelledby="workspace-title"
            >
                <div class="home-section-intro">
                    <div>
                        <span class="home-eyebrow">INSIDE ROSEWATCH</span>
                        <h2 id="workspace-title">Less searching.<br /><em>More clarity.</em></h2>
                    </div>
                    <p>
                        From the first camera check to the last follow-up, keep the important
                        details together. Explore what you can do in the workspace.
                    </p>
                </div>
                <div class="home-feature-guide">
                    <div
                        class="home-feature-tabs"
                        role="tablist"
                        aria-label="Explore workspace features"
                        aria-orientation="vertical"
                    >
                        <button
                            v-for="(item, index) in capabilities"
                            :id="`feature-tab-${item.id}`"
                            :key="item.id"
                            ref="tabButtons"
                            class="home-capability"
                            type="button"
                            role="tab"
                            :aria-selected="selectedFeature === index"
                            :aria-controls="`feature-panel-${item.id}`"
                            :tabindex="selectedFeature === index ? 0 : -1"
                            @click="selectedFeature = index"
                            @keydown="moveFeature($event, index)"
                        >
                            <span
                                class="home-feature-number"
                                aria-hidden="true"
                                >0{{ index + 1 }}</span
                            >
                            <span class="home-feature-label"
                                ><strong>{{ item.label }}</strong
                                ><small>{{ item.summary }}</small></span
                            >
                            <span
                                class="home-feature-arrow"
                                aria-hidden="true"
                                >↗</span
                            >
                        </button>
                    </div>
                    <div
                        v-for="(item, index) in capabilities"
                        :id="`feature-panel-${item.id}`"
                        :key="item.id"
                        class="home-feature-panel"
                        role="tabpanel"
                        :aria-labelledby="`feature-tab-${item.id}`"
                        :hidden="selectedFeature !== index"
                        tabindex="0"
                    >
                        <div class="home-panel-heading">
                            <span class="home-eyebrow">HOW IT WORKS</span
                            ><span aria-hidden="true">0{{ index + 1 }} / 04</span>
                        </div>
                        <h3>{{ item.title }}</h3>
                        <p>{{ item.text }}</p>
                        <ol>
                            <li
                                v-for="step in item.steps"
                                :key="step"
                            >
                                {{ step }}
                            </li>
                        </ol>
                        <p class="home-feature-note">{{ item.note }}</p>
                    </div>
                </div>
            </section>

            <section
                id="routine"
                class="home-routine home-container"
                aria-labelledby="routine-title"
            >
                <div class="home-routine-heading">
                    <div>
                        <span class="home-eyebrow">A SMALL ROUTINE. A SHARED RESPONSIBILITY.</span>
                        <h2 id="routine-title">
                            Start the day<br /><em>with a clearer picture.</em>
                        </h2>
                    </div>
                    <a
                        href="#workspace"
                        class="home-routine-back"
                        >Explore the workspace <span aria-hidden="true">↗</span></a
                    >
                </div>
                <ol class="home-routine-steps">
                    <li
                        v-for="(step, index) in routine"
                        :key="step.title"
                    >
                        <span aria-hidden="true">0{{ index + 1 }}</span>
                        <h3>{{ step.title }}</h3>
                        <p>{{ step.text }}</p>
                    </li>
                </ol>
                <div class="home-routine-entry">
                    <p>Ready for today’s checks?</p>
                    <Link
                        :href="can_open_workspace ? '/checklist' : entry"
                        class="home-button home-button-light"
                        >{{ can_open_workspace ? 'Open checklist' : 'Sign in to RoseWatch'
                        }}<span aria-hidden="true">→</span></Link
                    >
                </div>
                <img
                    class="home-waves"
                    src="/brand/rose-waves.svg"
                    alt=""
                    width="560"
                    height="28"
                />
            </section>

            <aside
                class="home-access home-container"
                aria-label="Workspace access"
            >
                <strong>A workspace for our team.</strong>
                <p>
                    RoseWatch is for authorized Rose Bakeshop team members. Need access? Contact
                    your RoseWatch administrator.
                </p>
            </aside>
        </main>

        <footer class="home-footer home-container">
            <Link
                href="/"
                class="home-footer-brand"
                >RoseWatch<span>CCTV operations, with care.</span></Link
            ><a
                href="#home-main"
                class="home-back-top"
                >Back to top <span aria-hidden="true">↑</span></a
            ><small>© {{ year }} Rose Bakeshop</small>
        </footer>
    </div>
</template>

<style scoped>
.home-page {
    --home-ink: #5b211e;
    --home-muted: #775c50;
    --home-line: #e6d7c9;
    min-height: 100vh;
    background: #fffaf4;
    color: var(--home-ink);
}
.home-container {
    width: min(1320px, calc(100% - 80px));
    margin-inline: auto;
}
.home-header {
    min-height: 100px;
    display: flex;
    align-items: center;
    gap: 40px;
}
.home-brand {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
    color: inherit;
    flex: none;
}
.home-brand img {
    object-fit: contain;
}
.home-brand strong {
    display: block;
    font-size: 26px;
    font-weight: 650;
    letter-spacing: -1px;
}
.home-brand small {
    display: block;
    margin-top: 3px;
    font-size: 9px;
    letter-spacing: 2px;
    color: var(--home-muted);
}
.home-navigation {
    display: flex;
    gap: 32px;
    margin-left: auto;
}
.home-navigation a {
    text-decoration: none;
    color: var(--home-muted);
    padding-block: 12px;
}
.home-navigation a:hover,
.home-text-link:hover,
.home-back-top:hover,
.home-routine-back:hover {
    text-decoration: underline;
    text-underline-offset: 5px;
}
.home-button {
    min-height: 48px;
    padding: 12px 24px;
    border: 1px solid transparent;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 24px;
    text-decoration: none;
    font-weight: 600;
    white-space: nowrap;
    transition:
        background 0.18s,
        transform 0.18s;
}
.home-button span {
    font-size: 21px;
    line-height: 1;
}
.home-button-primary {
    background: #d82d21;
    color: #fffaf4;
}
.home-button-primary:hover {
    background: #b92017;
}
.home-button-outline {
    border-color: #d5bfb0;
    color: var(--home-ink);
}
.home-button-outline:hover {
    background: #f5e7d9;
}
.home-button:active {
    transform: translateY(1px);
}
.home-hero {
    display: grid;
    grid-template-columns: 1fr 1.06fr;
    border: 1px solid var(--home-line);
    border-radius: 28px;
    background: #f7ecdf;
    overflow: hidden;
}
.home-hero-copy {
    padding: clamp(30px, 4vw, 58px);
    align-self: center;
}
.home-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    font-size: 10px;
    font-weight: 600;
    line-height: 1.6;
    letter-spacing: 1.8px;
    color: #8a493a;
}
.home-eyebrow-line {
    width: 30px;
    height: 2px;
    background: #cf3c28;
}
.home-hero h1 {
    font-size: clamp(50px, 5.2vw, 75px);
    line-height: 1.04;
    font-weight: 550;
    letter-spacing: -3px;
    margin: 30px 0 24px;
}
.home-page em {
    font-family: Courgette, cursive;
    font-weight: 400;
    font-style: normal;
    letter-spacing: -2px;
}
.home-hero h1 em {
    display: inline-block;
    font-size: 1.06em;
    margin-top: 7px;
}
.home-hero-copy > p {
    max-width: 340px;
    line-height: 1.85;
    color: var(--home-muted);
}
.home-hero-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 20px;
    margin-top: 28px;
}
.home-text-link {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    color: var(--home-ink);
    text-decoration: none;
    padding-block: 12px;
}
.home-hero-note {
    display: flex;
    align-items: center;
    gap: 13px;
    margin-top: 34px;
    color: var(--home-muted);
    font-size: 11px;
    line-height: 1.5;
}
.home-hero-note > span:first-child {
    font-size: 30px;
    color: #a56d4f;
}
.home-hero-visual {
    position: relative;
    min-height: 600px;
    margin: 0;
    background: #784d2d;
}
.home-hero-image {
    position: absolute;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: 61% center;
    display: block;
}
.home-hero-visual::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, #29160e66, transparent 24%, transparent 45%, #34140fe6);
    pointer-events: none;
}
.home-photo-label {
    position: absolute;
    inset: 28px 30px auto;
    display: flex;
    gap: 12px;
    align-items: center;
    font-size: 9px;
    letter-spacing: 1.7px;
    color: #fff5e6;
    z-index: 1;
}
.home-photo-label > span {
    color: #f3cb88;
}
.home-hero-visual figcaption {
    position: absolute;
    left: 38px;
    bottom: 42px;
    z-index: 1;
    color: #fff8ed;
}
.home-hero-visual figcaption > span {
    font-size: 9px;
    letter-spacing: 1.8px;
}
.home-hero-visual figcaption strong {
    display: block;
    margin-top: 14px;
    font-size: clamp(34px, 3.5vw, 48px);
    line-height: 1.12;
    font-weight: 450;
    letter-spacing: -1.7px;
}
.home-waves {
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    height: 12px;
    object-fit: fill;
    z-index: 1;
}
.home-principles {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    padding-block: 32px 36px;
    border-bottom: 1px solid var(--home-line);
}
.home-principles > div {
    display: grid;
    grid-template-columns: 28px 1fr;
    column-gap: 14px;
    padding-inline: 28px;
    border-left: 1px solid var(--home-line);
}
.home-principles > div:first-child {
    border-left: 0;
    padding-left: 0;
}
.home-principles > div > span {
    grid-row: span 2;
    font-size: 11px;
    color: #815b43;
    padding-top: 2px;
}
.home-principles strong {
    font-size: 15px;
    font-weight: 550;
}
.home-principles p {
    margin: 5px 0 0;
    color: var(--home-muted);
    line-height: 1.6;
}
.home-workspace {
    padding-block: 88px;
}
.home-section-intro {
    display: grid;
    grid-template-columns: 1fr 1.06fr;
    align-items: end;
    gap: 64px;
    margin-bottom: 38px;
}
.home-page h2 {
    font-size: clamp(34px, 3.3vw, 46px);
    font-weight: 500;
    line-height: 1.16;
    letter-spacing: -1.6px;
    margin: 18px 0 0;
}
.home-page h2 em {
    display: inline-block;
    font-size: 0.89em;
    margin-top: 8px;
    letter-spacing: -1.4px;
}
.home-section-intro > p {
    max-width: 370px;
    margin: 0 0 5px;
    color: var(--home-muted);
    line-height: 1.9;
}
.home-feature-guide {
    display: grid;
    grid-template-columns: 0.8fr 1.2fr;
    gap: 32px;
}
.home-feature-tabs {
    display: flex;
    flex-direction: column;
    align-self: start;
}
.home-capability {
    display: flex;
    align-items: center;
    width: 100%;
    gap: 18px;
    padding: 24px 18px;
    color: var(--home-ink);
    text-align: left;
    background: transparent;
    border: 0;
    border-bottom: 1px solid var(--home-line);
    border-radius: 0;
    transition: background 0.18s;
}
.home-capability:first-child {
    border-top: 1px solid var(--home-line);
}
.home-capability:hover {
    background: #f9efe4;
}
.home-capability[aria-selected='true'] {
    background: #f4e6d5;
    box-shadow: inset 3px 0 #d82d21;
}
.home-feature-number {
    align-self: start;
    margin-top: 3px;
    font-size: 11px;
    color: #805237;
}
.home-feature-label {
    flex: 1;
}
.home-feature-label strong {
    display: block;
    font-size: 17px;
    font-weight: 550;
    letter-spacing: -0.3px;
}
.home-feature-label small {
    display: block;
    margin-top: 6px;
    color: var(--home-muted);
    font-size: 12px;
    line-height: 1.5;
}
.home-feature-arrow {
    color: #986c55;
    font-size: 22px;
}
.home-capability[aria-selected='true'] .home-feature-arrow {
    color: #bd2c21;
}
.home-feature-panel {
    padding: 34px 40px;
    min-height: 405px;
    border: 1px solid var(--home-line);
    border-radius: 20px;
    background: #fffdf9;
}
.home-feature-panel[hidden] {
    display: none;
}
.home-panel-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
}
.home-panel-heading > span:last-child {
    color: #8c6855;
    font-size: 11px;
}
.home-feature-panel h3 {
    max-width: 410px;
    margin: 24px 0 12px;
    font-size: 29px;
    font-weight: 500;
    letter-spacing: -0.8px;
    line-height: 1.2;
}
.home-feature-panel > p {
    color: var(--home-muted);
    line-height: 1.85;
}
.home-feature-panel ol {
    list-style: none;
    counter-reset: feature;
    padding: 0;
    margin: 26px 0;
}
.home-feature-panel li {
    counter-increment: feature;
    display: flex;
    gap: 12px;
    align-items: baseline;
    margin-block: 13px;
    font-size: 13px;
    line-height: 1.6;
}
.home-feature-panel li::before {
    content: counter(feature);
    color: #a6674b;
    font-size: 11px;
    flex: 0 0 16px;
}
.home-feature-panel .home-feature-note {
    border-top: 1px solid var(--home-line);
    padding-top: 18px;
    margin: 0;
}
.home-routine {
    position: relative;
    padding: 48px 52px 44px;
    border-radius: 26px;
    overflow: hidden;
    background: #5b211e;
    color: #fff5e8;
}
.home-routine-heading {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 32px;
}
.home-routine .home-eyebrow {
    color: #ebc59e;
    font-size: 9px;
}
.home-routine h2 {
    margin-top: 20px;
}
.home-routine-back {
    display: inline-flex;
    gap: 14px;
    align-items: center;
    text-decoration: none;
    color: #f2dbc4;
    padding-block: 12px;
}
.home-routine-steps {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 38px;
    list-style: none;
    padding: 0;
    margin: 44px 0 34px;
}
.home-routine-steps li {
    padding-top: 20px;
    border-top: 1px solid #a26d5c;
}
.home-routine-steps li > span {
    font-size: 11px;
    color: #e9b880;
}
.home-routine-steps h3 {
    margin: 22px 0 10px;
    font-size: 26px;
    font-weight: 450;
    letter-spacing: -0.6px;
}
.home-routine-steps p {
    color: #f0d5bf;
    line-height: 1.85;
    max-width: 285px;
    margin: 0;
}
.home-routine-entry {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    padding-top: 26px;
    border-top: 1px solid #a26d5c;
}
.home-routine-entry p {
    color: #f4d9c2;
    margin: 0;
}
.home-button-light {
    background: #ffedd7;
    color: #5b211e;
}
.home-button-light:hover {
    background: #ffe0b5;
}
.home-access {
    padding-block: 36px 44px;
    text-align: center;
}
.home-access strong {
    font-size: 14px;
    font-weight: 550;
}
.home-access p {
    color: var(--home-muted);
    line-height: 1.85;
    margin: 8px auto 0;
    max-width: 530px;
}
.home-footer {
    border-top: 1px solid var(--home-line);
    display: flex;
    align-items: center;
    gap: 36px;
    padding-block: 28px;
}
.home-footer-brand {
    text-decoration: none;
    font-weight: 650;
    color: var(--home-ink);
    letter-spacing: -0.3px;
}
.home-footer-brand span {
    display: block;
    font-size: 11px;
    font-weight: 400;
    letter-spacing: 0;
    margin-top: 4px;
    color: var(--home-muted);
}
.home-back-top {
    margin-left: auto;
    color: var(--home-muted);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 14px;
    padding-block: 12px;
}
.home-footer small {
    color: var(--home-muted);
    font-size: 11px;
}
.home-skip {
    position: fixed;
    left: 16px;
    top: -70px;
    padding: 12px 20px;
    border-radius: 8px;
    background: #5b211e;
    color: white;
    z-index: 3;
}
.home-skip:focus {
    top: 12px;
}
.home-page :focus-visible {
    outline: 3px solid #b4782a;
    outline-offset: 5px;
}
.home-routine :focus-visible {
    outline-color: #ffd29b;
}
#workspace,
#routine,
#home-main {
    scroll-margin-top: 24px;
}
@media (max-width: 1100px) {
    .home-container {
        width: calc(100% - 48px);
    }
    .home-header {
        gap: 24px;
        min-height: 90px;
    }
    .home-navigation {
        gap: 22px;
    }
    .home-hero-copy {
        padding: 34px;
    }
    .home-hero h1 {
        font-size: 58px;
    }
    .home-hero-visual {
        min-height: 585px;
    }
    .home-principles > div {
        padding-inline: 18px;
        column-gap: 8px;
    }
    .home-feature-guide {
        gap: 22px;
    }
    .home-feature-panel {
        padding: 30px;
    }
    .home-section-intro {
        gap: 40px;
    }
    .home-routine {
        padding: 38px 36px 42px;
    }
}
@media (max-width: 767px) {
    .home-container {
        width: calc(100% - 40px);
    }
    .home-header {
        min-height: 82px;
        gap: 12px;
        justify-content: space-between;
    }
    .home-brand {
        gap: 8px;
    }
    .home-brand img {
        width: 45px;
        height: 35px;
    }
    .home-brand strong {
        font-size: 22px;
    }
    .home-brand small {
        font-size: 8px;
        letter-spacing: 1.4px;
    }
    .home-navigation {
        display: none;
    }
    .home-header .home-button {
        padding-inline: 16px;
        min-height: 42px;
        gap: 10px;
    }
    .home-hero {
        grid-template-columns: 1fr;
        border-radius: 22px;
    }
    .home-hero-copy {
        padding: 34px 28px;
    }
    .home-eyebrow {
        font-size: 9px;
        letter-spacing: 1.3px;
        gap: 9px;
    }
    .home-eyebrow-line {
        width: 20px;
    }
    .home-hero h1 {
        font-size: clamp(47px, 10.7vw, 68px);
        letter-spacing: -2px;
        margin-block: 24px;
    }
    .home-hero-actions {
        gap: 6px 20px;
        margin-top: 24px;
    }
    .home-hero-note {
        display: none;
    }
    .home-hero-visual {
        min-height: 340px;
    }
    .home-photo-label {
        inset: 22px 24px auto;
        font-size: 8px;
    }
    .home-hero-visual figcaption {
        left: 26px;
        bottom: 32px;
    }
    .home-hero-visual figcaption strong {
        font-size: 36px;
    }
    .home-principles {
        grid-template-columns: 1fr;
        padding-block: 12px 24px;
    }
    .home-principles > div,
    .home-principles > div:first-child {
        padding: 18px 0;
        border-left: 0;
        border-bottom: 1px solid var(--home-line);
        grid-template-columns: 28px 1fr;
        column-gap: 12px;
    }
    .home-principles > div:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }
    .home-workspace {
        padding-block: 48px;
    }
    .home-section-intro {
        grid-template-columns: 1fr;
        gap: 24px;
        margin-bottom: 28px;
    }
    .home-feature-guide {
        grid-template-columns: 1fr;
        gap: 24px;
    }
    .home-capability {
        padding: 19px 15px;
        gap: 14px;
    }
    .home-feature-label strong {
        font-size: 16px;
    }
    .home-feature-label small {
        font-size: 11px;
    }
    .home-feature-panel {
        padding: 28px 24px;
        min-height: 440px;
    }
    .home-feature-panel h3 {
        font-size: 27px;
    }
    .home-routine {
        padding: 34px 26px 40px;
        border-radius: 22px;
    }
    .home-routine-heading {
        align-items: start;
        flex-direction: column;
        gap: 14px;
    }
    .home-routine .home-eyebrow {
        max-width: 250px;
        font-size: 8px;
        letter-spacing: 1.4px;
    }
    .home-routine h2 {
        font-size: 34px;
    }
    .home-routine-steps {
        grid-template-columns: 1fr;
        gap: 24px;
        margin-block: 24px 30px;
    }
    .home-routine-steps li {
        display: grid;
        grid-template-columns: 26px 1fr;
        gap: 10px;
        padding-top: 20px;
    }
    .home-routine-steps li > span {
        grid-row: span 2;
        padding-top: 6px;
    }
    .home-routine-steps h3 {
        margin: 0;
        font-size: 24px;
    }
    .home-routine-steps p {
        margin: 0;
    }
    .home-routine-entry {
        flex-direction: column;
        align-items: start;
        gap: 18px;
    }
    .home-access {
        padding-block: 30px;
    }
    .home-footer {
        flex-wrap: wrap;
        gap: 20px;
        padding-block: 24px;
    }
    .home-footer small {
        flex-basis: 100%;
    }
}
@media (max-width: 359px) {
    .home-container {
        width: calc(100% - 28px);
    }
    .home-header .home-button {
        padding-inline: 12px;
        gap: 6px;
    }
    .home-header .home-button span {
        display: none;
    }
    .home-brand strong {
        font-size: 20px;
    }
    .home-hero-copy {
        padding-inline: 24px;
    }
    .home-feature-panel {
        padding-inline: 20px;
    }
    .home-routine {
        padding-inline: 22px;
    }
    .home-routine h2 {
        font-size: 29px;
    }
}
@media (prefers-reduced-motion: reduce) {
    .home-button,
    .home-capability {
        transition: none;
    }
}
</style>
