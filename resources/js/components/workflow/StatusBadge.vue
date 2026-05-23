<script setup lang="ts">
import { computed } from 'vue'
import Badge from '@/components/ui/Badge.vue'
import type { RunStatus, WorkflowStatus } from '@/types/api'

type AnyStatus = RunStatus | WorkflowStatus | string

const props = defineProps<{
    status: AnyStatus
    dot?: boolean
}>()

type Tone = 'neutral' | 'running' | 'success' | 'failed' | 'warning' | 'info' | 'pending'

const tone = computed<Tone>(() => {
    const v = String(props.status).toUpperCase()
    if (v === 'SUCCESS' || v === 'ACTIVE') return 'success'
    if (v === 'RUNNING') return 'running'
    if (v === 'FAILED' || v === 'TIMEOUT' || v === 'CANCELLED' || v === 'ARCHIVED') return 'failed'
    if (v === 'RETRYING' || v === 'PAUSED') return 'warning'
    if (v === 'DRAFT') return 'info'
    if (v === 'PENDING' || v === 'SKIPPED') return 'pending'
    return 'neutral'
})

const label = computed(() => String(props.status).toUpperCase())
</script>

<template>
    <Badge :tone="tone" :dot="dot">{{ label }}</Badge>
</template>
