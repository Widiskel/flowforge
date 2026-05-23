<script setup lang="ts">
import { MarkerType, VueFlow, type Edge, type Node } from '@vue-flow/core'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ApiError, connectRunStream as apiConnectRunStream, healthMetrics as fetchHealthMetrics, runLogs, workflowRun, workflowRuns, workflows as fetchWorkflows } from '../services/api/client'
import { useAuthStore } from '../stores/auth'
import type { ExecutionLog, HealthMetrics, Workflow, WorkflowRun, WorkflowStepDefinition } from '../types/api'

const auth = useAuthStore()

const workflows = ref<Workflow[]>([])
const selectedWorkflow = ref<Workflow | null>(null)
const runs = ref<WorkflowRun[]>([])
const selectedRun = ref<WorkflowRun | null>(null)
const logs = ref<ExecutionLog[]>([])
const metrics = ref<HealthMetrics | null>(null)
const metricsUpdatedAt = ref<string | null>(null)
const loading = ref(true)
const triggering = ref(false)
const error = ref<string | null>(null)
const streamState = ref<'idle' | 'connecting' | 'live' | 'closed' | 'error'>('idle')
let eventSource: EventSource | null = null

const activeSteps = computed(() => selectedWorkflow.value?.currentVersion?.definition.steps ?? [])
const successRuns = computed(() => runs.value.filter((run) => run.status === 'SUCCESS').length)
const failedRuns = computed(() => runs.value.filter((run) => ['FAILED', 'TIMEOUT', 'CANCELLED'].includes(run.status)).length)

const graphNodes = computed<Node[]>(() => layoutSteps(activeSteps.value).map(({ step, x, y }) => ({
    id: step.id,
    type: 'default',
    position: { x, y },
    data: {
        label: `${step.name}\n${step.type}`,
    },
    class: `flow-node flow-node-${step.type.toLowerCase()}`,
})))

const graphEdges = computed<Edge[]>(() => activeSteps.value.flatMap((step) => (step.dependsOn ?? []).map((dependency) => ({
    id: `${dependency}-${step.id}`,
    source: dependency,
    target: step.id,
    animated: selectedRun.value?.status === 'RUNNING' || selectedRun.value?.status === 'PENDING',
    markerEnd: MarkerType.ArrowClosed,
}))))

onMounted(async () => {
    await Promise.all([
        loadWorkflows(),
        loadMetrics(),
    ])
})

onBeforeUnmount(() => {
    closeStream()
})

watch(selectedRun, async (run) => {
    if (!selectedWorkflow.value || !run) {
        logs.value = []
        return
    }

    await loadRunLogs(run.id)
})

async function loadWorkflows(): Promise<void> {
    loading.value = true
    error.value = null

    try {
        const response = await fetchWorkflows()
        workflows.value = response.data
        selectedWorkflow.value = response.data[0] ?? null

        if (selectedWorkflow.value) {
            await selectWorkflow(selectedWorkflow.value)
        }
    } catch (exception) {
        error.value = exception instanceof Error ? exception.message : 'Failed to load workflows.'
    } finally {
        loading.value = false
    }
}

async function loadMetrics(): Promise<void> {
    try {
        metrics.value = await fetchHealthMetrics()
        metricsUpdatedAt.value = new Date().toLocaleTimeString()
    } catch (exception) {
        // Metrics endpoint may not be available yet; ignore silently
    }
}

async function selectWorkflow(workflow: Workflow): Promise<void> {
    selectedWorkflow.value = workflow
    selectedRun.value = null
    logs.value = []
    closeStream()

    const response = await workflowRuns(workflow.id)
    runs.value = response.data
    selectedRun.value = runs.value[0] ?? null
}

async function triggerSelectedWorkflow(): Promise<void> {
    if (!selectedWorkflow.value) return

    triggering.value = true
    error.value = null

    let optimisticRunId: string | null = null

    try {
        const optimisticRun: WorkflowRun = {
            id: `optimistic-${Date.now()}`,
            workflowId: selectedWorkflow.value.id,
            workflowVersionId: selectedWorkflow.value.currentVersion?.id ?? 'pending-version',
            status: 'PENDING',
            createdAt: new Date().toISOString(),
            stepRuns: activeSteps.value.map((step) => ({
                id: `optimistic-${step.id}`,
                stepId: step.id,
                stepType: step.type,
                status: 'PENDING' as const,
                attemptCount: 0,
                maxAttempts: 1,
            })),
        }

        runs.value = [optimisticRun, ...runs.value]
        optimisticRunId = optimisticRun.id
        selectedRun.value = optimisticRun

        // Trigger via workflow-runs POST (manual trigger)
        const response = await fetch(`/api/workflows/${selectedWorkflow.value.id}/trigger`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${auth.accessToken}`,
            },
            body: JSON.stringify({}),
        })

        if (!response.ok) {
            const payload = await response.json().catch(() => null)
            throw new Error(payload?.message ?? 'Failed to trigger workflow.')
        }

        const run = await response.json()
        const fullRun = await workflowRun(run.data.id)
        runs.value = [fullRun, ...runs.value.filter((item) => item.id !== optimisticRun.id)]
        selectedRun.value = fullRun
        await loadMetrics()
        connectRunStream(fullRun.id, (snapshot) => {
            selectedRun.value = snapshot
            runs.value = runs.value.map((run) => run.id === snapshot.id ? { ...run, status: snapshot.status } : run)

            if (['SUCCESS', 'FAILED', 'TIMEOUT', 'CANCELLED'].includes(snapshot.status)) {
                streamState.value = 'closed'
                closeStream(false)
            }
        }, (status) => {
            // Run completed
        })
    } catch (exception) {
        if (optimisticRunId !== null) {
            runs.value = runs.value.filter((item) => item.id !== optimisticRunId)
            selectedRun.value = runs.value[0] ?? null
        }

        error.value = exception instanceof Error ? exception.message : 'Unexpected error.'
    } finally {
        triggering.value = false
    }
}

function connectRunStream(runId: string, onSnapshot: (run: WorkflowRun) => void, onComplete: (status: string) => void): void {
    closeStream()
    streamState.value = 'connecting'

    eventSource = apiConnectRunStream(runId, (snapshot) => {
        onSnapshot(snapshot)
        streamState.value = 'live'
    }, (status) => {
        onComplete(status)
        streamState.value = 'closed'
        closeStream(false)
    })
}

function closeStream(markIdle = true): void {
    eventSource?.close()
    eventSource = null

    if (markIdle) {
        streamState.value = 'idle'
    }
}

async function loadRunLogs(runId: string): Promise<void> {
    try {
        const response = await runLogs(runId)
        logs.value = response.data
    } catch (exception) {
        if (!(exception instanceof ApiError && exception.status === 404)) {
            error.value = exception instanceof Error ? exception.message : 'Failed to load logs.'
        }
    }
}

function layoutSteps(steps: WorkflowStepDefinition[] | undefined): Array<{ step: WorkflowStepDefinition; x: number; y: number }> {
    if (!steps) return []

    const depth = new Map<string, number>()

    function stepDepth(step: WorkflowStepDefinition): number {
        if (depth.has(step.id)) return depth.get(step.id) ?? 0

        const dependencies = step.dependsOn ?? []
        const value = dependencies.length === 0
            ? 0
            : Math.max(...dependencies.map((dependency: string) => {
                const parent = (steps ?? []).find((item) => item.id === dependency)
                return parent ? stepDepth(parent) + 1 : 0
            }))

        depth.set(step.id, value)
        return value
    }

    const rowsByDepth = new Map<number, number>()

    return steps.map((step) => {
        const column = stepDepth(step)
        const row = rowsByDepth.get(column) ?? 0
        rowsByDepth.set(column, row + 1)

        return { step, x: column * 220, y: row * 150 }
    })
}

function formatPercent(value?: number): string {
    return `${Math.round((value ?? 0) * 100)}%`
}

function formatDuration(value?: number | null): string {
    if (value === null || value === undefined) {
        return '—'
    }

    if (value < 1000) {
        return `${value}ms`
    }

    return `${(value / 1000).toFixed(1)}s`
}

function statusClass(status?: string): string {
    return `status-${(status ?? 'pending').toLowerCase()}`
}
</script>

<template>
    <section class="builder-shell">
        <aside class="builder-sidebar">
            <div class="sidebar-head">
                <div>
                    <p class="eyebrow">Workflow library</p>
                    <h2>Flows</h2>
                </div>
                <button class="ghost-button compact" type="button" @click="loadWorkflows">
                    Refresh
                </button>
            </div>

            <p v-if="error" class="error-banner">
                {{ error }}
            </p>

            <div v-if="loading" class="loading-copy">Loading workflows…</div>

            <div v-else class="workflow-list">
                <button
                    v-for="workflow in workflows"
                    :key="workflow.id"
                    type="button"
                    class="workflow-row"
                    :class="selectedWorkflow?.id === workflow.id ? 'is-selected' : ''"
                    @click="selectWorkflow(workflow)"
                >
                    <span class="workflow-row__icon">⌁</span>
                    <span class="workflow-row__body">
                        <strong>{{ workflow.name }}</strong>
                        <small>v{{ workflow.currentVersion?.versionNumber ?? '—' }} · {{ workflow.currentVersion?.definition.steps.length ?? 0 }} nodes</small>
                    </span>
                    <span class="status-dot">{{ workflow.status }}</span>
                </button>
            </div>

            <div class="scenario-panel">
                <p class="eyebrow">Demo scenarios</p>
                <div class="scenario-stack">
                    <div class="scenario-item"><strong>{{ workflows.length }}</strong><span>active workflows</span></div>
                    <div class="scenario-item"><strong>{{ activeSteps.length }}</strong><span>nodes in selected flow</span></div>
                    <div class="scenario-item"><strong>Admin / Editor</strong><span>can trigger runs</span></div>
                    <div class="scenario-item"><strong>Viewer</strong><span>read-only RBAC check</span></div>
                </div>
            </div>
        </aside>

        <section class="builder-workspace">
            <div class="workspace-toolbar">
                <div class="workspace-title">
                    <p class="eyebrow">Workflow builder</p>
                    <h2>{{ selectedWorkflow?.name ?? 'No workflow selected' }}</h2>
                    <p>{{ selectedWorkflow?.description ?? 'Pilih workflow untuk melihat DAG eksekusi.' }}</p>
                </div>
                <div class="workspace-actions">
                    <span class="stream-chip" :class="streamState === 'error' ? 'is-error' : ''"><span />SSE {{ streamState }}</span>
                    <button
                        type="button"
                        :disabled="!selectedWorkflow || !auth.canTrigger || triggering"
                        class="primary-button"
                        @click="triggerSelectedWorkflow"
                    >
                        {{ triggering ? 'Creating run…' : 'Trigger run' }}
                    </button>
                </div>
            </div>

            <div class="canvas-stage">
                <div class="canvas-ruler top-ruler">
                    <span>Input</span><span>Decision</span><span>Action</span>
                </div>
                <VueFlow
                    v-if="selectedWorkflow"
                    class="builder-canvas"
                    :nodes="graphNodes"
                    :edges="graphEdges"
                    fit-view-on-init
                />
                <div v-else class="empty-state">No active workflow found.</div>
            </div>

            <div class="telemetry-dock">
                <div><strong>{{ metrics?.activeRuns ?? '—' }}</strong><span>active runs</span></div>
                <div><strong>{{ formatPercent(metrics?.rates.success) }}</strong><span>success rate</span></div>
                <div><strong>{{ formatPercent(metrics?.rates.failure) }}</strong><span>failure rate</span></div>
                <div><strong>{{ formatDuration(metrics?.averageDurationMs) }}</strong><span>avg duration</span></div>
                <button class="ghost-button compact" type="button" @click="loadMetrics">Refresh metrics</button>
            </div>
        </section>

        <aside class="builder-inspector">
            <div class="inspector-card summary-card">
                <p class="eyebrow">Run state</p>
                <div class="summary-grid">
                    <div><strong>{{ runs.length }}</strong><span>runs</span></div>
                    <div><strong>{{ successRuns }}</strong><span>success</span></div>
                    <div><strong>{{ failedRuns }}</strong><span>failed</span></div>
                </div>
                <div class="sse-card" :class="streamState === 'error' ? 'is-error' : ''">
                    <span class="sse-card__pulse" />
                    <p>Stream</p>
                    <strong>{{ streamState }}</strong>
                    <small>Live run snapshots stream here while the workflow executes.</small>
                </div>
            </div>

            <div class="inspector-card">
                <div class="inspector-head">
                    <div>
                        <p class="eyebrow">Selected run</p>
                        <h3>Inspector</h3>
                    </div>
                    <span v-if="selectedRun" class="status-pill" :class="statusClass(selectedRun.status)">{{ selectedRun.status }}</span>
                </div>

                <div v-if="selectedRun" class="inspector-content">
                    <div class="step-stack">
                        <div
                            v-for="stepRun in selectedRun.stepRuns ?? []"
                            :key="stepRun.id"
                            class="step-card"
                        >
                            <div>
                                <strong>{{ stepRun.stepId }}</strong>
                                <small>{{ stepRun.stepType }} · attempts {{ stepRun.attemptCount }}/{{ stepRun.maxAttempts }}</small>
                            </div>
                            <span class="status-pill" :class="statusClass(stepRun.status)">{{ stepRun.status }}</span>
                        </div>
                    </div>

                    <div class="log-stack compact-logs">
                        <div v-for="log in logs" :key="log.id" class="log-card">
                            <div>
                                <strong>{{ log.event }}</strong>
                                <span>{{ log.level }}</span>
                            </div>
                            <p>{{ log.message }}</p>
                        </div>
                        <p v-if="logs.length === 0" class="empty-copy">No logs for selected run yet.</p>
                    </div>
                </div>

                <p v-else class="empty-state compact">Trigger atau pilih run untuk melihat run inspector.</p>
            </div>
        </aside>

        <section class="run-timeline">
            <div class="timeline-head">
                <div>
                    <p class="eyebrow">Run timeline</p>
                    <h3>Recent executions</h3>
                </div>
                <small>Latest run first · tenant scoped</small>
            </div>
            <div class="timeline-list">
                <button
                    v-for="run in runs"
                    :key="run.id"
                    type="button"
                    class="timeline-item"
                    :class="selectedRun?.id === run.id ? 'is-selected' : ''"
                    @click="selectedRun = run"
                >
                    <span class="status-pill" :class="statusClass(run.status)">{{ run.status }}</span>
                    <strong>{{ run.id }}</strong>
                    <small>{{ run.createdAt ? new Date(run.createdAt).toLocaleString() : 'created just now' }}</small>
                </button>
            </div>
        </section>
    </section>
</template>
