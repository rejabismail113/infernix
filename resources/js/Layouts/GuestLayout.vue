<script setup lang="ts">
/**
 * Sign-in screens (login, MFA, password reset, invitation, re-auth): Infernix Workspace look — orange waves
 * behind a frosted card with the full logo. `wide` gives the roomier card used by the login page.
 */
withDefaults(defineProps<{ wide?: boolean }>(), { wide: false });
</script>

<template>
    <main class="relative flex min-h-screen items-center justify-center overflow-hidden bg-[#f4f4f7] px-4 py-10">
        <!-- Decorative orange waves (pure SVG; no external assets) -->
        <svg
            class="pointer-events-none absolute inset-0 h-full w-full"
            viewBox="0 0 1280 960"
            preserveAspectRatio="xMidYMid slice"
            aria-hidden="true"
        >
            <defs>
                <linearGradient
                    id="wave-a"
                    x1="0"
                    y1="0"
                    x2="1"
                    y2="1"
                >
                    <stop
                        offset="0"
                        stop-color="#FF8A00"
                    />
                    <stop
                        offset="1"
                        stop-color="#FFB347"
                        stop-opacity="0.35"
                    />
                </linearGradient>
                <linearGradient
                    id="wave-b"
                    x1="1"
                    y1="1"
                    x2="0"
                    y2="0"
                >
                    <stop
                        offset="0"
                        stop-color="#FF7A00"
                    />
                    <stop
                        offset="1"
                        stop-color="#FFC27A"
                        stop-opacity="0.25"
                    />
                </linearGradient>
                <filter id="wave-blur">
                    <feGaussianBlur stdDeviation="6" />
                </filter>
            </defs>
            <path
                d="M-60 330 C 120 200, 160 40, 420 -40 L -60 -40 Z"
                fill="url(#wave-a)"
                opacity="0.9"
            />
            <path
                d="M-60 470 C 140 330, 260 120, 520 -40 L 470 -40 C 230 110, 110 290, -60 400 Z"
                fill="url(#wave-a)"
                opacity="0.45"
                filter="url(#wave-blur)"
            />
            <path
                d="M-60 560 C 200 420, 330 200, 640 -40"
                fill="none"
                stroke="#FFFFFF"
                stroke-width="2"
                opacity="0.6"
            />
            <path
                d="M1340 620 C 1140 700, 1020 840, 900 1000 L 1340 1000 Z"
                fill="url(#wave-b)"
                opacity="0.9"
            />
            <path
                d="M1340 480 C 1100 600, 920 800, 760 1000 L 860 1000 C 1000 820, 1160 660, 1340 560 Z"
                fill="url(#wave-b)"
                opacity="0.5"
                filter="url(#wave-blur)"
            />
            <path
                d="M200 1000 C 420 860, 620 900, 860 1000 Z"
                fill="url(#wave-b)"
                opacity="0.35"
            />
            <path
                d="M1340 400 C 1080 560, 900 760, 700 1000"
                fill="none"
                stroke="#FFFFFF"
                stroke-width="2"
                opacity="0.55"
            />
        </svg>

        <div
            class="relative w-full rounded-[2.5rem] border border-white/80 bg-white/70 px-6 py-10 shadow-[0_30px_80px_-20px_rgba(120,60,0,0.25)] backdrop-blur-xl"
            :class="wide ? 'max-w-[42rem] sm:px-16' : 'max-w-lg sm:px-12'"
        >
            <img
                src="/images/infernix-workspace-logo.png"
                alt="Infernix Workspace — Intelligence for Better Business"
                class="mx-auto w-auto"
                :class="wide ? 'h-36 sm:h-44' : 'h-28 sm:h-32'"
                width="450"
                height="214"
            >
            <div class="mt-6">
                <slot />
            </div>
        </div>
    </main>
</template>
