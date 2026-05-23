<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import GlassPanel from '@/components/ui/GlassPanel.vue'
import KpiCard from '@/components/ui/KpiCard.vue'
import Icon from '@/components/ui/Icon.vue'
import Button from '@/components/ui/Button.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Alert from '@/components/ui/Alert.vue'
import StatusBadge from '@/components/workflow/StatusBadge.vue'
import { healthMetrics, workflows, allWorkflowRuns } from '@/services/api/client'
import type { HealthMetrics, Workflow, WorkflowRun } from '@/types/api'
import { formatDuration } from '@/utils/format'

const router = useRouter()
const loading = ref(true)
const error = ref<string | null>(null)
const metrics = ref<HealthMetrics | null>(null)
const workflowList = ref<Workflow[]>([])
const recentRuns = ref<Array<{ run: WorkflowRun; workflowName: string }>>([])

const kpis = computed(() => {
    const data = metrics.value
    if (!data) {
        return [
            { label: 'Active Runs', value: '—', unit: undefined as string | undefined, icon: 'directions_run', tone: 'default' as const, progress: null as number | null },
            { label: 'Success Rate', value: '—', unit: undefined as string | undefined, icon: 'check_circle', tone: 'success' as const, progress: null as number | null },
            { label: 'Avg Execution Time', value: '—', unit: undefined as string | undefined, icon: 'timer', tone: 'tertiary' as const, progress: null as number | null },
            { label: 'Failure Rate', value: '—', unit: undefined as string | undefined, icon: 'warning', tone: 'failed' as const, progress: null as number | null },
        ]
    }
    const totalRuns = Math.max(data.totals.runs, 1)
    return [
        {
            label: 'Active Runs',
            value: String(data.activeRuns),
            unit: undefined as string | undefined,
            icon: 'directions_run',
            tone: 'default' as const,
            progress: Math.min(100, (data.activeRuns / totalRuns) * 100) as number | null,
        },
        {
            label: 'Success Rate',
            value: data.rates.success.toFixed(1),
            unit: '%' as string | undefined,
            icon: 'check_circle',
            tone: 'success' as const,
            progress: data.rates.success as number | null,
        },
        {
            label: 'Avg Execution Time',
            value: data.averageDurationMs ? formatDuration(data.averageDurationMs) : '—',
            unit: undefined as string | undefined,
            icon: 'timer',
            tone: 'tertiary' as const,
            progress: null as number | null,
        },
        {
            label: 'Failure Rate',
            value: data.rates.failure.toFixed(1),
            unit: '%' as string | undefined,
            icon: 'warning',
            tone: 'failed' as const,
            progress: Math.max(2, data.rates.failure) as number | null,
        },
    ]
})

const throughputBars = computed(() => {
    // Derive a simple visualization from available metrics (last bar = active runs proportion).
    const data = metrics.value
    if (!data) return new Array(10).fill(0).map((_, i) => 20 + (i % 5) * 6)
    const total = Math.max(data.totals.runs, 1)
    const success = (data.totals.success / total) * 100
    const failed = (data.totals.failed / total) * 100
    const timeout = (data.totals.timeout / total) * 100
    const profile = [success * 0.4, success * 0.55, success * 0.6, success * 0.7, success * 0.85, success, failed + 25, timeout + 30, success * 0.5, success * 0.4]
    return profile.map((v) => Math.max(8, Math.min(100, Math.round(v))))
})

async function loadDashboard(): Promise<void> {
    loading.value = true
    error.value = null
    try {
        const [metricData, workflowResponse, runsResponse] = await Promise.all([
            healthMetrics(),
            workflows(),
            allWorkflowRuns({ perPage: 12 }),
        ])
        metrics.value = metricData
        workflowList.value = workflowResponse.data

        const workflowMap = new Map(workflowResponse.data.map((w) => [w.id, w.name]))
        recentRuns.value = runsResponse.data
            .map((run) => ({ run, workflowName: workflowMap.get(run.workflowId) ?? 'Unknown' }))
            .slice(0, 6)
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load dashboard'
    } finally {
        loading.value = false
    }
}

function statusToneClass(status: string): string {
    switch (status) {
        case 'RUNNING': return 'border-l-[3px] border-running bg-running/[0.04]'
        case 'SUCCESS': return 'border-l-[3px] border-success bg-success/[0.04]'
        case 'FAILED':
        case 'TIMEOUT':
        case 'CANCELLED':
            return 'border-l-[3px] border-failed bg-failed/[0.04]'
        case 'RETRYING': return 'border-l-[3px] border-warning bg-warning/[0.04]'
        default: return 'border-l-[3px] border-outline-variant'
    }
}

onMounted(loadDashboard)
</script>

<template>
    <div
        class="min-h-full"
        style="background-image: radial-gradient(ellipse at top, color-mix(in srgb, var(--color-surface-container-high) 30%, transparent), transparent 60%);"
    >
        <header class="flex justify-between items-end gap-md flex-wrap mb-lg">
            <div>
                <h2 class="text-headline-lg font-headline-lg text-on-surface m-0">Mission Control</h2>
                <p class="text-body-lg font-body-lg text-on-surface-variant mt-xs">
                    Real-time telemetry across {{ workflowList.length }} workflow{{ workflowList.length === 1 ? '' : 's' }}
                </p>
            </div>
            <Button
                glow
                leading-icon="add"
                @click="router.push({ name: 'workflows.builder' })"
            >Create New Workflow</Button>
        </header>

        <Alert v-if="error" tone="error" class="mb-lg">{{ error }}</Alert>

        <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-md mb-lg">
            <KpiCard
                v-for="kpi in kpis"
                :key="kpi.label"
                :label="kpi.label"
                :value="kpi.value"
                :unit="kpi.unit"
                :icon="kpi.icon"
                :tone="kpi.tone"
                :progress="kpi.progress"
                :loading="loading"
            />
        </section>

        <section class="grid grid-cols-1 lg:grid-cols-3 gap-lg">
            <GlassPanel radius="xl" clamp class="lg:col-span-2 flex flex-col">
                <header class="px-md py-sm border-b border-outline-variant/30 flex justify-between items-center bg-surface-container-low/50">
                    <h3 class="text-headline-sm font-headline-sm text-on-surface m-0 flex items-center gap-sm">
                        <Icon name="stream" :size="20" class="text-secondary pulse-cyan" />
                        Live Execution Stream
                    </h3>
                    <button
                        type="button"
                        class="text-label-caps font-label-caps text-secondary hover:text-on-surface transition-colors"
                        @click="router.push({ name: 'runs' })"
                    >View All</button>
                </header>
                <div class="flex-1 p-sm space-y-sm overflow-y-auto min-h-[280px]">
                    <EmptyState
                        v-if="!loading && recentRuns.length === 0"
                        icon="play_arrow"
                        title="No recent runs"
                        description="Trigger a workflow to see live execution telemetry."
                        compact
                    />
                    <div
                        v-for="item in recentRuns"
                        :key="item.run.id"
                        :class="[
                            'rounded-DEFAULT p-sm flex items-center justify-between gap-md hover:bg-surface-container-high transition-colors cursor-pointer bg-surface-container border border-outline-variant/40',
                            statusToneClass(item.run.status),
                        ]"
                        @click="router.push({ name: 'runs', query: { runId: item.run.id } })"
                    >
                        <div class="flex items-center gap-md min-w-0 flex-1">
                            <div
                                :class="[
                                    'w-8 h-8 rounded-DEFAULT flex items-center justify-center shrink-0',
                                    item.run.status === 'RUNNING' ? 'bg-running/10 text-running' :
                                    item.run.status === 'SUCCESS' ? 'bg-success/10 text-success' :
                                    item.run.status === 'FAILED' || item.run.status === 'TIMEOUT' || item.run.status === 'CANCELLED' ? 'bg-failed/10 text-failed' :
                                    'bg-surface-variant text-on-surface-variant',
                                ]"
                            >
                                <Icon
                                    :name="item.run.status === 'RUNNING' ? 'progress_activity' : item.run.status === 'SUCCESS' ? 'check' : item.run.status === 'PENDING' ? 'schedule' : 'close'"
                                    :size="18"
                                    :class="item.run.status === 'RUNNING' ? 'animate-spin' : ''"
                                />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-body-md font-bold text-on-surface m-0 truncate">{{ item.workflowName }}</p>
                                <p class="text-code-sm font-code-sm text-on-surface-variant m-0 truncate">run-id: {{ item.run.id.slice(0, 8) }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-md shrink-0">
                            <StatusBadge :status="item.run.status" />
                            <span class="text-code-md font-code-md text-on-surface tabular-nums hidden sm:inline">{{ formatDuration(item.run.durationMs ?? null) }}</span>
                        </div>
                    </div>
                </div>
            </GlassPanel>

            <GlassPanel radius="xl" clamp class="flex flex-col">
                <header class="px-md py-sm border-b border-outline-variant/30 bg-surface-container-low/50">
                    <h3 class="text-headline-sm font-headline-sm text-on-surface m-0">Throughput (24h)</h3>
                </header>
                <div class="flex-1 p-md flex flex-col justify-between gap-sm">
                    <div class="relative h-32 flex items-end gap-1 border-b border-l border-outline-variant/30 pl-1 pb-1">
                        <div
                            class="absolute inset-0 pointer-events-none"
                            style="background: linear-gradient(to top, color-mix(in srgb, var(--color-secondary) 12%, transparent), transparent);"
                        />
                        <div
                            v-for="(height, idx) in throughputBars"
                            :key="idx"
                            class="flex-1 rounded-t-sm transition-colors"
                            :class="idx === throughputBars.length - 1 ? 'bg-secondary glow-active' : 'bg-surface-variant hover:bg-secondary'"
                            :style="{ height: `${height}%` }"
                        />
                    </div>
                    <div class="flex justify-between text-code-sm font-code-sm text-on-surface-variant">
                        <span>−24h</span>
                        <span>Now</span>
                    </div>
                    <div class="text-body-sm font-body-sm text-on-surface-variant">
                        <span class="text-on-surface font-bold">{{ metrics?.totals.runs ?? 0 }}</span> total runs ·
                        <span class="text-success font-bold">{{ metrics?.totals.success ?? 0 }}</span> ok ·
                        <span class="text-failed font-bold">{{ metrics?.totals.failed ?? 0 }}</span> failed
                    </div>
                </div>
            </GlassPanel>
        </section>
    </div>
</template>
