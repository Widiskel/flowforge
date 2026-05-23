<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import GlassPanel from '@/components/ui/GlassPanel.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import Button from '@/components/ui/Button.vue'
import Alert from '@/components/ui/Alert.vue'
import Icon from '@/components/ui/Icon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Tabs from '@/components/ui/Tabs.vue'
import StatusBadge from '@/components/workflow/StatusBadge.vue'
import StepTimeline from '@/components/workflow/StepTimeline.vue'
import LogTerminal from '@/components/workflow/LogTerminal.vue'
import TestRunOverlay from '@/components/TestRunOverlay.vue'
import { workflows, allWorkflowRuns, runLogs, analyzeFailure } from '@/services/api/client'
import type {
    AiFailureAnalysis,
    ExecutionLog,
    Workflow,
    WorkflowRun,
} from '@/types/api'
import { formatDuration, formatTime, formatRelativeTime } from '@/utils/format'

const route = useRoute()
type RunTab = 'logs' | 'output' | 'analysis'
const loading = ref(true)
const error = ref<string | null>(null)
const workflowList = ref<Workflow[]>([])
const runs = ref<WorkflowRun[]>([])
const selectedRunId = ref<string | null>(null)
const selectedTab = ref<RunTab>('logs')
const logs = ref<ExecutionLog[]>([])
const logsLoading = ref(false)
const analysis = ref<AiFailureAnalysis | null>(null)
const analysisLoading = ref(false)
const analysisError = ref<string | null>(null)
const showOverlay = ref(false)

const selectedRun = computed(() => runs.value.find((r) => r.id === selectedRunId.value) ?? null)

const selectedWorkflowName = computed(() => {
    if (!selectedRun.value) return 'Unknown'
    const workflow = workflowList.value.find((w) => w.id === selectedRun.value!.workflowId)
    return workflow?.name ?? 'Unknown Workflow'
})

const runsWithNames = computed(() =>
    runs.value.map((run) => {
        const workflow = workflowList.value.find((w) => w.id === run.workflowId)
        return { run, workflowName: workflow?.name ?? 'Unknown' }
    }),
)

const tabItems = computed(() => [
    { value: 'logs' as RunTab, label: 'Execution Logs', badge: logs.value.length ? String(logs.value.length) : undefined },
    { value: 'output' as RunTab, label: 'Step Output' },
    { value: 'analysis' as RunTab, label: 'AI Failure Analysis' },
])

watch(selectedRunId, async (id) => {
    if (!id) return
    logsLoading.value = true
    analysis.value = null
    analysisError.value = null
    try {
        const response = await runLogs(id)
        logs.value = response.data
    } catch {
        logs.value = []
    } finally {
        logsLoading.value = false
    }
})

async function requestAnalysis(): Promise<void> {
    if (!selectedRunId.value) return
    analysisLoading.value = true
    analysisError.value = null
    try {
        analysis.value = await analyzeFailure(selectedRunId.value)
    } catch (err) {
        analysisError.value = err instanceof Error ? err.message : 'Analysis request failed'
    } finally {
        analysisLoading.value = false
    }
}

async function loadRuns(): Promise<void> {
    loading.value = true
    error.value = null
    try {
        const [workflowResponse, runResponse] = await Promise.all([workflows(), allWorkflowRuns({ perPage: 50 })])
        workflowList.value = workflowResponse.data
        runs.value = runResponse.data

        const queryRunId = typeof route.query.runId === 'string' ? route.query.runId : null
        if (queryRunId && runs.value.some((r) => r.id === queryRunId)) {
            selectedRunId.value = queryRunId
        } else if (runs.value.length > 0 && !selectedRunId.value) {
            selectedRunId.value = runs.value[0].id
        }
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load runs'
    } finally {
        loading.value = false
    }
}

function statusAccent(status: string): string {
    switch (status) {
        case 'RUNNING': return 'bg-running'
        case 'SUCCESS': return 'bg-success'
        case 'FAILED':
        case 'TIMEOUT':
        case 'CANCELLED':
            return 'bg-failed'
        case 'RETRYING': return 'bg-warning'
        default: return 'bg-outline-variant'
    }
}

const stepsWithOutput = computed(() => (selectedRun.value?.stepRuns ?? []).filter((s) => s.output))

onMounted(loadRuns)
</script>

<template>
    <div>
        <PageHeader
            eyebrow="Execution History"
            title="Runs"
            subtitle="Inspect tenant-scoped runs, drill into logs, and request AI failure analysis on failed runs."
        >
            <template #actions>
                <Button
                    v-if="selectedRun"
                    variant="secondary"
                    leading-icon="bolt"
                    @click="showOverlay = true"
                >Run Details</Button>
                <Button
                    variant="secondary"
                    leading-icon="refresh"
                    :disabled="loading"
                    @click="loadRuns"
                >Refresh</Button>
            </template>
        </PageHeader>

        <Alert v-if="error" tone="error" class="mb-md">{{ error }}</Alert>

        <div class="grid grid-cols-1 lg:grid-cols-[320px_minmax(0,1fr)] gap-md items-start">
            <!-- Run list -->
            <GlassPanel radius="xl" clamp class="flex flex-col">
                <header class="flex justify-between items-center px-md py-sm border-b border-outline-variant/30 bg-surface-container-low/40">
                    <h3 class="text-label-caps font-label-caps uppercase text-on-surface-variant m-0">Recent Executions</h3>
                    <span class="text-body-sm text-on-surface-variant">{{ runs.length }} runs</span>
                </header>
                <div class="flex flex-col gap-1 p-sm max-h-[640px] overflow-y-auto">
                    <EmptyState
                        v-if="!loading && runs.length === 0"
                        icon="play_arrow"
                        title="No runs yet"
                        description="Trigger a workflow to populate this list."
                        compact
                    />
                    <button
                        v-for="item in runsWithNames"
                        :key="item.run.id"
                        type="button"
                        :class="[
                            'flex gap-sm p-sm rounded-DEFAULT text-left transition-all border',
                            selectedRunId === item.run.id
                                ? 'border-secondary/40 bg-secondary/5 shadow-[inset_2px_0_0_var(--color-secondary)]'
                                : 'border-transparent hover:bg-surface-variant/30',
                        ]"
                        @click="selectedRunId = item.run.id"
                    >
                        <span :class="['w-1 rounded-full', statusAccent(item.run.status)]" />
                        <div class="flex-1 min-w-0 flex flex-col gap-1">
                            <div class="flex items-center justify-between gap-sm">
                                <span class="text-code-sm font-code-sm font-bold text-on-surface">#{{ item.run.id.slice(0, 8) }}</span>
                                <StatusBadge :status="item.run.status" />
                            </div>
                            <span class="text-body-sm text-on-surface-variant truncate">{{ item.workflowName }}</span>
                            <div class="flex items-center justify-between text-label-caps font-label-caps uppercase text-on-surface-variant">
                                <span>{{ formatRelativeTime(item.run.createdAt) }}</span>
                                <span>{{ formatDuration(item.run.durationMs ?? null) }}</span>
                            </div>
                        </div>
                    </button>
                </div>
            </GlassPanel>

            <!-- Detail -->
            <div class="flex flex-col gap-md min-w-0">
                <GlassPanel v-if="!selectedRun" radius="xl" padded>
                    <EmptyState
                        icon="touch_app"
                        title="Select a run"
                        description="Pick a run from the list to view its timeline, logs, and analysis."
                    />
                </GlassPanel>

                <template v-else>
                    <GlassPanel radius="xl" padded>
                        <div class="flex flex-col gap-sm">
                            <div class="flex items-center gap-md flex-wrap">
                                <h2 class="text-headline-md font-headline-md text-on-surface m-0">Run #{{ selectedRun.id.slice(0, 8) }}</h2>
                                <StatusBadge :status="selectedRun.status" dot />
                            </div>
                            <div class="flex flex-wrap gap-sm text-body-sm text-on-surface-variant">
                                <span class="flex items-center gap-1.5"><Icon name="account_tree" :size="16" /> {{ selectedWorkflowName }}</span>
                                <span class="text-outline-variant">·</span>
                                <span class="flex items-center gap-1.5"><Icon name="timer" :size="16" /> {{ formatDuration(selectedRun.durationMs ?? null) }}</span>
                                <span class="text-outline-variant">·</span>
                                <span class="flex items-center gap-1.5"><Icon name="event" :size="16" /> {{ formatTime(selectedRun.startedAt ?? null) }}</span>
                            </div>
                        </div>
                    </GlassPanel>

                    <GlassPanel
                        v-if="selectedRun.stepRuns && selectedRun.stepRuns.length > 0"
                        radius="xl"
                        padded
                    >
                        <h4 class="text-label-caps font-label-caps text-secondary uppercase tracking-wider m-0 mb-sm">Execution Path</h4>
                        <StepTimeline :steps="selectedRun.stepRuns" />
                    </GlassPanel>

                    <Tabs v-model="selectedTab" :items="tabItems" />

                    <GlassPanel radius="xl" clamp>
                        <div v-if="selectedTab === 'logs'" class="p-md">
                            <LogTerminal :logs="logs" :loading="logsLoading" />
                        </div>

                        <div v-else-if="selectedTab === 'output'" class="p-md">
                            <EmptyState
                                v-if="stepsWithOutput.length === 0"
                                icon="data_object"
                                title="No step output"
                                description="This run did not capture per-step outputs."
                                compact
                            />
                            <div v-else class="flex flex-col gap-md">
                                <div v-for="step in stepsWithOutput" :key="step.id">
                                    <p class="text-label-caps font-label-caps text-secondary uppercase tracking-wider mb-xs">{{ step.stepId }}</p>
                                    <pre class="m-0 p-md rounded-DEFAULT bg-[#02080f] border border-outline-variant/30 text-code-md font-code-md text-on-surface overflow-x-auto">{{ JSON.stringify(step.output, null, 2) }}</pre>
                                </div>
                            </div>
                        </div>

                        <div v-else class="p-md flex flex-col gap-md">
                            <Alert
                                v-if="selectedRun.status !== 'FAILED' && selectedRun.status !== 'TIMEOUT'"
                                tone="info"
                                title="Analysis available for failed runs"
                            >
                                AI failure analysis is exposed only for runs in <code class="font-code-sm">FAILED</code> or <code class="font-code-sm">TIMEOUT</code>. This run is currently <code class="font-code-sm">{{ selectedRun.status }}</code>.
                            </Alert>
                            <template v-else>
                                <div class="flex items-center gap-sm">
                                    <Button
                                        :disabled="analysisLoading"
                                        leading-icon="auto_awesome"
                                        glow
                                        @click="requestAnalysis"
                                    >{{ analysis ? 'Re-analyze' : 'Request AI Analysis' }}</Button>
                                    <span v-if="analysisLoading" class="text-body-sm text-on-surface-variant">Analyzing failure…</span>
                                </div>
                                <Alert v-if="analysisError" tone="error" compact>{{ analysisError }}</Alert>
                                <div v-if="analysis" class="grid grid-cols-1 md:grid-cols-2 gap-md">
                                    <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                                        <p class="text-label-caps font-label-caps text-secondary uppercase tracking-wider m-0 mb-xs">Root Cause</p>
                                        <p class="m-0 text-body-md text-on-surface leading-relaxed">{{ analysis.rootCause }}</p>
                                    </div>
                                    <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                                        <p class="text-label-caps font-label-caps text-secondary uppercase tracking-wider m-0 mb-xs">Suggested Fix</p>
                                        <p class="m-0 text-body-md text-on-surface leading-relaxed">{{ analysis.suggestedFix }}</p>
                                    </div>
                                    <div class="md:col-span-2 flex items-center gap-md flex-wrap">
                                        <StatusBadge :status="analysis.confidence" />
                                        <span class="text-body-sm text-on-surface-variant">Category: <code class="font-code-sm text-on-surface">{{ analysis.category }}</code></span>
                                    </div>
                                    <div v-if="analysis.evidence.length > 0" class="md:col-span-2 flex flex-col gap-sm">
                                        <p class="text-label-caps font-label-caps text-secondary uppercase tracking-wider m-0">Evidence</p>
                                        <div
                                            v-for="(ev, idx) in analysis.evidence"
                                            :key="idx"
                                            class="p-sm rounded-DEFAULT border-l-2 border-secondary bg-surface-container-low"
                                        >
                                            <p class="text-label-caps font-label-caps text-secondary uppercase m-0">{{ ev.source }}</p>
                                            <p class="text-body-md text-on-surface m-0 mt-1">{{ ev.observation }}</p>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </GlassPanel>
                </template>
            </div>
        </div>

        <TestRunOverlay
            v-if="selectedRun"
            :run="selectedRun"
            :is-open="showOverlay"
            @close="showOverlay = false"
        />
    </div>
</template>
