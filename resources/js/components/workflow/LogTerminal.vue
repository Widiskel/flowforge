<script setup lang="ts">
import { computed } from 'vue'
import type { ExecutionLog } from '@/types/api'

const props = defineProps<{
    logs: ExecutionLog[]
    loading?: boolean
}>()

function formatTime(value?: string | null): string {
    if (!value) return ''
    return new Date(value).toLocaleTimeString('en-US', { hour12: false })
}

function levelClass(level: string): string {
    switch (level) {
        case 'error': return 'text-failed'
        case 'warning': return 'text-warning'
        case 'success': return 'text-success'
        case 'debug': return 'text-on-surface-variant'
        case 'info':
        default:
            return 'text-secondary'
    }
}

const lines = computed(() => props.logs ?? [])
</script>

<template>
    <div
        class="bg-[#02080f] border border-outline-variant/30 rounded-DEFAULT font-code-md text-code-md text-on-surface overflow-y-auto max-h-[480px]"
    >
        <div v-if="loading" class="p-md text-on-surface-variant">Loading logs…</div>
        <div v-else-if="lines.length === 0" class="p-md text-on-surface-variant">No logs available for this run.</div>
        <div v-else class="p-md flex flex-col gap-1">
            <div
                v-for="log in lines"
                :key="log.id"
                class="grid grid-cols-[88px_72px_1fr] gap-md items-baseline"
            >
                <span class="text-on-surface-variant">{{ formatTime(log.createdAt ?? null) }}</span>
                <span :class="['font-bold uppercase tracking-wider', levelClass(log.level)]">{{ log.level }}</span>
                <span class="text-on-surface break-words">{{ log.message }}</span>
            </div>
        </div>
    </div>
</template>
