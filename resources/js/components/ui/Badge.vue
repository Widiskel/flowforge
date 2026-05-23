<script setup lang="ts">
import { computed } from 'vue'

type Tone = 'neutral' | 'running' | 'success' | 'failed' | 'warning' | 'info' | 'pending'

const props = withDefaults(
    defineProps<{
        tone?: Tone
        size?: 'sm' | 'md'
        outline?: boolean
        dot?: boolean
        uppercase?: boolean
    }>(),
    {
        tone: 'neutral',
        size: 'sm',
        outline: false,
        dot: false,
        uppercase: true,
    },
)

const toneClasses = computed<string>(() => {
    const map: Record<Tone, string> = {
        neutral: 'bg-surface-variant/60 text-on-surface-variant border-outline-variant/40',
        running: 'bg-running/12 text-running border-running/40',
        success: 'bg-success/12 text-success border-success/40',
        failed: 'bg-failed/12 text-failed border-failed/40',
        warning: 'bg-warning/12 text-warning border-warning/40',
        info: 'bg-secondary/10 text-secondary border-secondary/40',
        pending: 'bg-surface-variant/40 text-on-surface-variant border-outline-variant/40',
    }
    return map[props.tone]
})

const dotClass = computed<string>(() => {
    const map: Record<Tone, string> = {
        neutral: 'bg-on-surface-variant',
        running: 'bg-running',
        success: 'bg-success',
        failed: 'bg-failed',
        warning: 'bg-warning',
        info: 'bg-secondary',
        pending: 'bg-on-surface-variant',
    }
    return map[props.tone]
})

const sizeClass = computed(() =>
    props.size === 'sm'
        ? 'text-[10px] leading-4 px-2 py-0.5'
        : 'text-label-caps font-label-caps px-sm py-1',
)
</script>

<template>
    <span
        :class="[
            'inline-flex items-center gap-1.5 rounded-full font-bold border tracking-wider',
            uppercase ? 'uppercase' : '',
            outline ? 'bg-transparent' : '',
            toneClasses,
            sizeClass,
        ]"
    >
        <span v-if="dot" :class="['w-1.5 h-1.5 rounded-full', dotClass]" />
        <slot />
    </span>
</template>
