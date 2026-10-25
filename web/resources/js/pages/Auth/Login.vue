<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { nextTick, ref, watch } from 'vue';

const form = useForm({ email: '', password: '', remember: false });
const visible = ref(false);
const capsLock = ref(false);
const help = ref<HTMLDialogElement | null>(null);
const emailInput = ref<HTMLInputElement | null>(null);
const passwordInput = ref<HTMLInputElement | null>(null);
watch(visible, () => nextTick(() => passwordInput.value?.focus()));
function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
        onError: errors => nextTick(() => {
            if (errors.email) emailInput.value?.focus();
            else passwordInput.value?.focus();
        }),
    });
}
</script>

<template>
    <Head title="Sign in" />
    <main class="login-shell">
        <section class="welcome-panel" aria-label="RoseWatch CCTV operations">
            <div class="brand-lockup">
                <div class="logo-tile"><img src="/brand/rose-bakeshop-logo.png" alt="Rose Bakeshop — Since 1983" width="142" height="103"></div>
                <div><span class="brand-title">RoseWatch</span><span class="brand-caption">CCTV OPERATIONS</span></div>
            </div>
            <div class="welcome-copy">
                <div class="welcome-eyebrow"><span></span> CARE IN EVERY CORNER</div>
                <h1>A watchful eye.<br> A little peace of mind.</h1>
                <p>Looking after our branches, our people,<br class="desktop-break"> and the places that bring us together.</p>
                <div class="operations-card" aria-hidden="true">
                    <div class="camera-illustration"><svg viewBox="0 0 64 64" fill="none"><path d="M12 19h27a8 8 0 0 1 8 8v8a8 8 0 0 1-8 8H12V19Zm35 8 10-5v18l-10-5M24 43v11M16 54h17" stroke="currentColor" stroke-width="2.4" stroke-linejoin="round"/><circle cx="23" cy="29" r="3" fill="currentColor"/></svg></div>
                    <div><strong>Every branch. One workspace.</strong><span>Camera checks · Incident reports · Follow-ups</span></div>
                </div>
            </div>
            <div class="welcome-footer">A little care goes a long way.</div>
            <img class="welcome-waves" src="/brand/rose-waves.svg" alt="">
        </section>

        <section class="signin-panel" aria-labelledby="signin-title">
            <div class="signin-inner">
                <div class="signin-symbol" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/></svg></div>
                <div class="form-eyebrow">WELCOME TO ROSEWATCH</div>
                <h2 id="signin-title">Good to see you again.</h2>
                <p class="signin-description">Sign in to your CCTV operations workspace.</p>

                <form @submit.prevent="submit" novalidate class="signin-form" :aria-busy="form.processing">
                    <div class="input-group">
                        <label for="email">Work email</label>
                        <input id="email" ref="emailInput" v-model="form.email" type="email" autocomplete="username" inputmode="email" placeholder="you@company.com" required autofocus :disabled="form.processing" :aria-invalid="!!form.errors.email" :aria-describedby="form.errors.email ? 'email-error' : undefined">
                        <p v-if="form.errors.email" id="email-error" class="input-error" role="alert">{{ form.errors.email }}</p>
                    </div>
                    <div class="input-group">
                        <label for="password">Password</label>
                        <div class="password-wrap">
                            <input id="password" ref="passwordInput" v-model="form.password" :type="visible ? 'text' : 'password'" autocomplete="current-password" placeholder="Enter your password" required :disabled="form.processing" :aria-invalid="!!form.errors.password" :aria-describedby="form.errors.password ? 'password-error' : capsLock ? 'caps-note' : undefined" @keyup="capsLock = $event.getModifierState('CapsLock')" @blur="capsLock = false">
                            <button type="button" class="password-toggle" :aria-label="visible ? 'Hide password' : 'Show password'" :aria-pressed="visible" :disabled="form.processing" @click="visible = !visible">
                                <svg v-if="!visible" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m3 3 18 18M10 5.2a12 12 0 0 1 2-.2c6 0 10 7 10 7a20 20 0 0 1-3 3.6M6.3 6.3A23 23 0 0 0 2 12s4 7 10 7a12 12 0 0 0 5.2-1.2M10 10a3 3 0 0 0 4 4"/></svg>
                            </button>
                        </div>
                        <p v-if="form.errors.password" id="password-error" class="input-error" role="alert">{{ form.errors.password }}</p>
                        <p v-if="capsLock" id="caps-note" class="input-hint" role="status">Caps Lock is on.</p>
                    </div>
                    <label class="remember-choice"><input v-model="form.remember" type="checkbox" :disabled="form.processing"><span>Keep me signed in on this device</span></label>
                    <button class="signin-button" type="submit" :disabled="form.processing"><span v-if="form.processing" class="spinner" aria-hidden="true"></span>{{ form.processing ? 'Signing you in…' : 'Sign in' }}<span v-if="!form.processing" aria-hidden="true">→</span></button>
                    <div class="signin-help"><button type="button" @click="help?.showModal()">Need help signing in?</button></div>
                </form>
                <div class="access-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/></svg><p>For authorized Rose Bakeshop team members.<br>Use your account assigned by your administrator.</p></div>
            </div>
            <footer class="signin-footer">Rose Bakeshop <span aria-hidden="true">·</span> Thoughtfully connected.</footer>
        </section>
        <dialog ref="help" class="help-dialog" aria-labelledby="help-title">
            <h2 id="help-title">Let’s get you back in.</h2><p>Contact your RoseWatch administrator if you need an account or help resetting your password.</p><p>Have your work email and branch name ready so they can help you.</p><button class="signin-button" @click="help?.close()">Got it</button>
        </dialog>
    </main>
</template>
