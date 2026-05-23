<script setup lang="ts">
withDefaults(
    defineProps<{
        label?: string
        tone?: 'running' | 'success' | 'warning' | 'failed'
    }>(),
    { tone: 'running', label: 'System Status' },
)
</script>

<template>
    <div
        class="hidden md:inline-flex items-center gap-sm px-sm py-1 rounded-full border border-outline-variant/50 bg-surface-container-low/60 text-body-sm font-body-sm text-on-surface-variant"
    >
        <span
            class="w-2 h-2 rounded-full pulse-cyan"
            :class="{
                'bg-secondary': tone === 'running',
                'bg-success': tone === 'success',
                'bg-warning': tone === 'warning',
                'bg-failed': tone === 'failed',
            }"
        />
        <span>{{ label }}</span>
    </div>
</template>
