<script setup lang="ts">
/**
 * Sign-in page (G-01). Frosted card over orange waves, per the Infernix Workspace design. Behaviour is unchanged:
 * email + password, remember me, forgot password; MFA follows on the next screen for privileged roles.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Flash from '@/Components/Feedback/FlashBanner.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const form = useForm({ email: '', password: '', remember: false }); // unticked by default: shared office computers
const showPassword = ref(false);

function submit() {
    form.post(route('login'), { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head title="Sign in" />
    <GuestLayout wide>
        <h1 class="text-center text-3xl font-extrabold tracking-tight text-surface-900 sm:text-4xl">
            Welcome back
        </h1>
        <p class="mt-2 text-center text-sm text-surface-500 sm:text-base">
            Sign in to Infernix Workspace to access your business platform
        </p>

        <div class="mt-6">
            <Flash />
        </div>

        <form
            class="mt-2 space-y-4"
            novalidate
            @submit.prevent="submit"
        >
            <div>
                <label
                    for="email"
                    class="sr-only"
                >Email address</label>
                <div class="relative">
                    <svg
                        class="pointer-events-none absolute left-4 top-1/2 z-10 h-5 w-5 -translate-y-1/2 text-surface-500"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.6"
                        aria-hidden="true"
                    ><rect
                        x="3"
                        y="5"
                        width="18"
                        height="14"
                        rx="2"
                    /><path d="m4 7 8 6 8-6" /></svg>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="username"
                        required
                        autofocus
                        placeholder="Email address"
                        class="w-full rounded-xl border border-surface-200 bg-white/90 py-3.5 pl-12 pr-4 text-surface-900 placeholder:text-surface-500 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-400/40"
                        :aria-invalid="form.errors.email ? 'true' : undefined"
                        :aria-describedby="form.errors.email ? 'email-error' : undefined"
                    >
                </div>
                <p
                    v-if="form.errors.email"
                    id="email-error"
                    class="mt-1 text-sm text-red-700"
                    role="alert"
                >
                    {{ form.errors.email }}
                </p>
            </div>

            <div>
                <label
                    for="password"
                    class="sr-only"
                >Password</label>
                <div class="relative">
                    <svg
                        class="pointer-events-none absolute left-4 top-1/2 z-10 h-5 w-5 -translate-y-1/2 text-surface-500"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.6"
                        aria-hidden="true"
                    ><rect
                        x="5"
                        y="10"
                        width="14"
                        height="11"
                        rx="2"
                    /><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3" /></svg>
                    <input
                        id="password"
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="current-password"
                        required
                        placeholder="Password"
                        class="w-full rounded-xl border border-surface-200 bg-white/90 py-3.5 pl-12 pr-12 text-surface-900 placeholder:text-surface-500 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-400/40"
                        :aria-invalid="form.errors.password ? 'true' : undefined"
                    >
                    <button
                        type="button"
                        class="absolute right-3 top-1/2 -translate-y-1/2 rounded-md p-1 text-surface-500 hover:text-surface-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
                        :aria-label="showPassword ? 'Hide password' : 'Show password'"
                        :aria-pressed="showPassword"
                        @click="showPassword = !showPassword"
                    >
                        <svg
                            class="h-5 w-5"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.6"
                            aria-hidden="true"
                        >
                            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" />
                            <circle
                                cx="12"
                                cy="12"
                                r="3"
                            />
                            <path
                                v-if="showPassword"
                                d="M4 4l16 16"
                            />
                        </svg>
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-between text-sm">
                <label class="flex cursor-pointer items-center gap-2 text-surface-900">
                    <input
                        v-model="form.remember"
                        type="checkbox"
                        class="h-5 w-5 rounded border-surface-300 accent-brand-500"
                    >
                    Remember me
                </label>
                <Link
                    :href="route('password.request')"
                    class="font-medium text-brand-700 hover:underline"
                >
                    Forgot password?
                </Link>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-[#D45F00] to-brand-600 px-4 py-3.5 text-xl font-semibold text-white shadow-[0_10px_25px_-8px_rgba(184,74,0,0.6)] transition hover:to-brand-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-400/50 disabled:opacity-60"
            >
                {{ form.processing ? 'Signing in…' : 'Sign In' }}
                <svg
                    class="h-5 w-5"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                ><path d="M5 12h14M13 6l6 6-6 6" /></svg>
            </button>
        </form>
    </GuestLayout>
</template>
