<script setup lang="ts">
import { computed } from 'vue'
import GlassPanel from './GlassPanel.vue'
import Icon from './Icon.vue'

type Tone = 'default' | 'success' | 'failed' | 'tertiary'

const props = withDefaults(
    defineProps<{
        label: string
        value: string
        unit?: string
        icon?: string
        tone?: Tone
        progress?: number | null
        delta?: string
        deltaTrend?: 'up' | 'down' | 'flat'
        loading?: boolean
    }>(),
    { tone: 'default', progress: null, loading: false },
)

const colorMap: Record<Tone, { fg: string; bg: string; ring: string }> = {
    default: { fg: 'text-secondary', bg: 'bg-secondary', ring: 'border-outline-variant/40' },
    success: { fg: 'text-success', bg: 'bg-success', ring: 'border-success/30' },
    failed: { fg: 'text-failed', bg: 'bg-failed', ring: 'border-failed/30' },
    tertiary: { fg: 'text-tertiary', bg: 'bg-tertiary', ring: 'border-outline-variant/40' },
}

const colors = computed(() => colorMap[props.tone])

const deltaClass = computed(() => {
    if (props.deltaTrend === 'down') return 'text-failed'
    if (props.deltaTrend === 'up') return 'text-success'
    return 'text-on-surface-variant'
})

const trendIcon = computed(() => {
    if (props.deltaTrend === 'down') return 'trending_down'
    if (props.deltaTrend === 'up') return 'trending_up'
    return 'trending_flat'
})

const progressWidth = computed(() => {
    if (props.progress === null || props.progress === undefined) return null
    const clamped = Math.max(0, Math.min(100, props.progress))
    return `${clamped}%`
})
</script>

<template>
    <GlassPanel
        radius="lg"
        padded
        clamp
        :class="['relative group transition-colors', tone === 'failed' ? 'border-failed/30 bg-failed/5' : '']"
    >
        <div
            v-if="icon"
            :class="['absolute top-0 right-0 p-sm opacity-15 group-hover:opacity-35 transition-opacity', colors.fg]"
        >
            <Icon :name="icon" :size="48" />
        </div>
        <p class="text-label-caps font-label-caps text-on-surface-variant uppercase mb-xs">{{ label }}</p>
        <div class="flex items-baseline gap-sm">
            <h3
                :class="['text-headline-lg font-headline-lg m-0', tone === 'failed' ? 'text-failed' : 'text-on-surface']"
            >
                <template v-if="loading">—</template>
                <template v-else>
                    {{ value }}<span v-if="unit" class="text-headline-sm text-on-surface-variant ml-0.5">{{ unit }}</span>
                </template>
            </h3>
            <span
                v-if="delta"
                :class="['flex items-center text-body-sm font-body-sm gap-1', deltaClass]"
            >
                <Icon :name="trendIcon" :size="14" />
                {{ delta }}
            </span>
        </div>
        <div
            v-if="progressWidth !== null"
            class="w-full h-1 bg-surface-variant mt-sm rounded-full overflow-hidden"
        >
            <div
                :class="['h-full rounded-full transition-[width] duration-500 ease-out', colors.bg]"
                :style="{ width: progressWidth }"
            />
        </div>
        <slot />
    </GlassPanel>
</template>
