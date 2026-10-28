<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { nextTick, ref, watch } from 'vue';

defineProps<{ sso_enabled?: boolean; errors?: { sso?: string } }>();

const form = useForm({ email: '', password: '', remember: false });

const visible = ref(false);

const capsLock = ref(false);

const help = ref<HTMLDialogElement | null>(null);

const helpTopic = ref<'access' | 'privacy'>('access');

function showHelp(topic: 'access' | 'privacy' = 'access') {
    helpTopic.value = topic;
    help.value?.showModal();
}

const emailInput = ref<HTMLInputElement | null>(null);

const passwordInput = ref<HTMLInputElement | null>(null);

watch(visible, () => nextTick(() => passwordInput.value?.focus()));

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
        onError: (errors) =>
            nextTick(() => {
                if (errors.email) emailInput.value?.focus();
                else passwordInput.value?.focus();
            }),
    });
}
</script>

<template>
    <Head title="Sign in" />
    <main class="auth-page">
        <div class="auth-card">
            <section
                class="auth-form-panel"
                aria-labelledby="signin-title"
            >
                <header>
                    <Link
                        href="/"
                        class="auth-brand"
                        aria-label="RoseWatch home"
                    >
                        <img
                            src="/brand/rose-bakeshop-logo.png"
                            alt="Rose Bakeshop"
                            width="72"
                            height="55"
                        />
                        <div>
                            <span class="auth-brand-name">RoseWatch</span>
                            <span class="auth-brand-caption">CCTV OPERATIONS</span>
                        </div>
                    </Link>
                </header>

                <div class="auth-form-content">
                    <div class="auth-intro">
                        <span class="auth-eyebrow">A LITTLE CARE. EVERY DAY.</span>
                        <h1 id="signin-title">Welcome back.</h1>
                        <p>Sign in to your RoseWatch workspace.</p>
                    </div>

                    <form
                        @submit.prevent="submit"
                        novalidate
                        class="auth-form"
                        :aria-busy="form.processing"
                    >
                        <div class="auth-field">
                            <label for="email">Work email</label>
                            <input
                                id="email"
                                ref="emailInput"
                                v-model="form.email"
                                type="email"
                                autocomplete="username"
                                inputmode="email"
                                placeholder="you@company.com"
                                required
                                autofocus
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.email"
                                :aria-describedby="form.errors.email ? 'email-error' : undefined"
                            />
                            <p
                                v-if="form.errors.email"
                                id="email-error"
                                class="auth-error"
                                role="alert"
                            >
                                {{ form.errors.email }}
                            </p>
                        </div>

                        <div class="auth-field">
                            <label for="password">Password</label>
                            <div class="auth-password">
                                <input
                                    id="password"
                                    ref="passwordInput"
                                    v-model="form.password"
                                    :type="visible ? 'text' : 'password'"
                                    autocomplete="current-password"
                                    placeholder="Enter your password"
                                    required
                                    :disabled="form.processing"
                                    :aria-invalid="!!form.errors.password"
                                    :aria-describedby="
                                        form.errors.password
                                            ? 'password-error'
                                            : capsLock
                                              ? 'caps-note'
                                              : undefined
                                    "
                                    @keyup="capsLock = $event.getModifierState('CapsLock')"
                                    @blur="capsLock = false"
                                />
                                <button
                                    type="button"
                                    class="auth-password-toggle"
                                    :aria-label="visible ? 'Hide password' : 'Show password'"
                                    :aria-pressed="visible"
                                    :disabled="form.processing"
                                    @click="visible = !visible"
                                >
                                    <svg
                                        v-if="!visible"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.6"
                                        aria-hidden="true"
                                    >
                                        <path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z" />
                                        <circle
                                            cx="12"
                                            cy="12"
                                            r="3"
                                        />
                                    </svg>
                                    <svg
                                        v-else
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.6"
                                        aria-hidden="true"
                                    >
                                        <path
                                            d="m3 3 18 18M10 5.2a12 12 0 0 1 2-.2c6 0 10 7 10 7a20 20 0 0 1-3 3.6M6.3 6.3A23 23 0 0 0 2 12s4 7 10 7a12 12 0 0 0 5.2-1.2M10 10a3 3 0 0 0 4 4"
                                        />
                                    </svg>
                                </button>
                            </div>
                            <p
                                v-if="form.errors.password"
                                id="password-error"
                                class="auth-error"
                                role="alert"
                            >
                                {{ form.errors.password }}
                            </p>
                            <p
                                v-if="capsLock"
                                id="caps-note"
                                class="auth-hint"
                                role="status"
                            >
                                Caps Lock is on.
                            </p>
                        </div>

                        <div class="auth-form-options">
                            <label class="auth-remember">
                                <input
                                    v-model="form.remember"
                                    type="checkbox"
                                    :disabled="form.processing"
                                />
                                <span>Remember me</span>
                            </label>
                            <button
                                type="button"
                                class="auth-text-button"
                                @click="showHelp()"
                            >
                                Forgot password?
                            </button>
                        </div>

                        <button
                            class="auth-submit"
                            type="submit"
                            :disabled="form.processing"
                        >
                            <span
                                v-if="form.processing"
                                class="auth-spinner"
                                aria-hidden="true"
                            ></span>
                            {{ form.processing ? 'Signing you in...' : 'Sign in' }}
                            <svg
                                v-if="!form.processing"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                aria-hidden="true"
                            >
                                <path d="M5 12h14m-5-5 5 5-5 5" />
                            </svg>
                        </button>
                    </form>

                    <template v-if="sso_enabled">
                        <div class="auth-divider"><span>or continue with</span></div>
                        <a
                            class="auth-rose-sso"
                            href="/auth/keycloak/redirect"
                        >
                            <img
                                src="/brand/rose-bakeshop-logo.png"
                                alt=""
                                width="34"
                                height="26"
                            />
                            <span>Sign in with Rose Account</span>
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <path d="M4 12h16m-7-7 7 7-7 7" />
                            </svg>
                        </a>
                    </template>
                    <p
                        v-if="errors?.sso"
                        class="auth-error"
                        role="alert"
                    >
                        {{ errors.sso }}
                    </p>
                </div>

                <footer class="auth-footer">
                    <button
                        type="button"
                        class="auth-text-button"
                        @click="showHelp()"
                    >
                        Need help signing in?
                    </button>
                    <button
                        type="button"
                        class="auth-text-button"
                        @click="showHelp('privacy')"
                    >
                        Privacy &amp; access
                    </button>
                </footer>
            </section>

            <section
                class="auth-visual"
                aria-label="Looking after our branches"
            >
                <img
                    class="auth-visual-image"
                    src="/brand/bakery-login.png"
                    alt="A sunlit bakery with fresh bread, burgundy counters and a discreet security camera."
                    width="1122"
                    height="1402"
                    fetchpriority="high"
                />
                <div class="auth-visual-top">
                    <span class="auth-visual-label">
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.5"
                            aria-hidden="true"
                        >
                            <path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z" />
                            <path d="m8 12 3 3 5-6" />
                        </svg>
                        A little peace of mind.
                    </span>
                    <span class="auth-since">SINCE 1983</span>
                </div>
                <div class="auth-visual-copy">
                    <span class="auth-visual-eyebrow">OUR PEOPLE. OUR PLACES.</span>
                    <h2>Care in <br />every corner.</h2>
                    <p>Looking after the places <br />that bring us together.</p>
                    <div class="auth-visual-rule"></div>
                    <span class="auth-visual-caption">Rose Bakeshop &middot; CCTV Operations</span>
                </div>
                <img
                    class="auth-waves"
                    src="/brand/rose-waves.svg"
                    alt=""
                />
            </section>
        </div>

        <dialog
            ref="help"
            class="auth-help-dialog"
            aria-labelledby="help-title"
            @click="$event.target === help && help?.close()"
        >
            <template v-if="helpTopic === 'access'">
                <span class="auth-eyebrow">HERE TO HELP</span>
                <h2 id="help-title">Let's get you back in.</h2>
                <p>
                    Contact your RoseWatch administrator if you need an account or help resetting
                    your password.
                </p>
                <p>Have your work email and branch name ready so they can help you.</p>
            </template>
            <template v-else>
                <span class="auth-eyebrow">PRIVACY &amp; ACCESS</span>
                <h2 id="help-title">A workspace for our team.</h2>
                <p>
                    RoseWatch is for authorized Rose Bakeshop team members. Your administrator
                    manages your account and branch access.
                </p>
                <p>
                    Camera records and evidence are available only to authorized users. Sign out
                    when using a shared device, and contact your administrator for privacy
                    questions.
                </p>
            </template>
            <button
                class="auth-submit"
                type="button"
                @click="help?.close()"
            >
                Got it
            </button>
        </dialog>
    </main>
</template>
