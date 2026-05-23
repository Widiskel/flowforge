<script setup lang="ts">
import { computed } from 'vue'
import Icon from './Icon.vue'

type Tone = 'info' | 'warning' | 'error' | 'success'

const props = withDefaults(
    defineProps<{
        tone?: Tone
        title?: string
        compact?: boolean
    }>(),
    { tone: 'info', compact: false },
)

const config = computed(() => {
    const map: Record<Tone, { classes: string; icon: string }> = {
        info: { classes: 'border-secondary/30 bg-secondary/5 text-on-surface', icon: 'info' },
        success: { classes: 'border-success/30 bg-success/5 text-success', icon: 'check_circle' },
        warning: { classes: 'border-warning/30 bg-warning/8 text-warning', icon: 'warning' },
        error: { classes: 'border-failed/30 bg-failed/8 text-failed', icon: 'error' },
    }
    return map[props.tone]
})
</script>

<template>
    <div
        :class="[
            'flex items-start gap-sm rounded-DEFAULT border',
            compact ? 'p-sm text-body-sm' : 'p-md text-body-md',
            config.classes,
        ]"
        role="alert"
    >
        <Icon :name="config.icon" :size="20" class="mt-0.5 shrink-0" />
        <div class="min-w-0 flex-1">
            <p
                v-if="title"
                class="text-body-md font-bold m-0 mb-xs"
            >{{ title }}</p>
            <div class="m-0 leading-relaxed text-on-surface">
                <slot />
            </div>
        </div>
        <slot name="actions" />
    </div>
</template>
