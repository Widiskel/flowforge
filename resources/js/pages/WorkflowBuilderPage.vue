<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch, nextTick } from 'vue'
import { MarkerType, VueFlow, type Connection, type Edge, type Node } from '@vue-flow/core'
import { Background } from '@vue-flow/background'
import { Controls } from '@vue-flow/controls'
import { MiniMap } from '@vue-flow/minimap'
import '@vue-flow/controls/dist/style.css'
import '@vue-flow/minimap/dist/style.css'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import Button from '@/components/ui/Button.vue'
import Icon from '@/components/ui/Icon.vue'
import Alert from '@/components/ui/Alert.vue'
import Badge from '@/components/ui/Badge.vue'
import NodePalette, { type StepType } from '@/components/workflow/NodePalette.vue'
import StepInspector from '@/components/workflow/StepInspector.vue'
import TriggerSelector from '@/components/workflow/TriggerSelector.vue'
import TriggerInspector, { type TriggerDraft } from '@/components/workflow/TriggerInspector.vue'
import TestRunOverlay from '@/components/TestRunOverlay.vue'
import Modal from '@/components/ui/Modal.vue'
import {
    createWorkflow,
    createWorkflowTrigger,
    deleteWorkflowTrigger,
    getWorkflow,
    listWorkflowTriggers,
    runLogs,
    triggerWorkflow,
    updateWorkflow,
    workflowRun as fetchWorkflowRun,
} from '@/services/api/client'
import type { Workflow, WorkflowDefinition, WorkflowRun, WorkflowStepDefinition, WorkflowTrigger, WorkflowTriggerType } from '@/types/api'

interface BuilderStep {
    id: string
    name: string
    type: StepType
    dependsOn: string[]
    config: Record<string, unknown>
    timeoutMs?: number
    retry?: {
        maxAttempts?: number
        backoff?: 'exponential' | 'fixed' | string
        initialDelayMs?: number
        maxDelayMs?: number
    }
    notes?: string
    displayNoteInFlow?: boolean
    /**
     * Persisted canvas position. Set when the step is added or dragged so the
     * preview computed property is no longer responsible for layout —
     * positions are stable across re-renders, save, reload.
     */
    position?: { x: number; y: number }
}

const route = useRoute()
const router = useRouter()

const steps = ref<BuilderStep[]>([])
const selectedStepId = ref<string | null>(null)
const workflowName = ref('')
const workflowDescription = ref('')
const globalTimeoutMs = ref(60000)
const defaultMaxAttempts = ref(3)
const initialStatus = ref<'draft' | 'active' | 'archived'>('draft')
const saving = ref(false)
const testRunning = ref(false)
const testRunError = ref<string | null>(null)
const testRunModal = ref<WorkflowRun | null>(null)
const testRunPollHandle = ref<number | null>(null)
const discardDialogOpen = ref(false)
const isDirty = ref(false)
const loadingWorkflow = ref(false)
const saveError = ref<string | null>(null)
const editingWorkflow = ref<Workflow | null>(null)
const paletteOpen = ref(true)

// Trigger state — n8n-style: every workflow needs an entry trigger node.
const TRIGGER_NODE_ID = '__trigger__'
const triggerDraft = ref<TriggerDraft | null>(null)
const triggerPosition = ref<{ x: number; y: number }>({ x: 80, y: 80 })
const persistedTrigger = ref<WorkflowTrigger | null>(null)
const triggerInspectorOpen = ref(false)
const triggerSelectorOpen = ref(false)

const webhookUrl = computed(() => {
    const id = editingWorkflow.value?.id
    if (!id) return ''
    return `${window.location.origin}/api/webhooks/${id}`
})

const mode = computed<'create' | 'edit' | 'view'>(() => {
    if (route.query.mode === 'view') return 'view'
    if (route.query.workflowId || route.query.mode === 'edit') return 'edit'
    return 'create'
})
const isReadOnly = computed(() => mode.value === 'view')

const selectedStep = computed(() => steps.value.find((s) => s.id === selectedStepId.value) ?? null)
const inspectorOpen = computed(() => selectedStep.value !== null && !isReadOnly.value && selectedStepId.value !== TRIGGER_NODE_ID)
const availableDeps = computed(() => {
    if (!selectedStep.value) return []
    return steps.value.filter((s) => s.id !== selectedStep.value!.id)
})

/**
 * Compute a stable layout position for a step. We only fall back to the
 * depth/row formula when a step has no persisted `position`, so once the user
 * (or `addStep`) writes a position the canvas no longer reshuffles when other
 * nodes are added/removed.
 */
function computeFallbackPosition(targetId: string): { x: number; y: number } {
    const depthCache = new Map<string, number>()
    function depth(id: string): number {
        if (depthCache.has(id)) return depthCache.get(id)!
        const step = steps.value.find((s) => s.id === id)
        if (!step) return 0
        const value = step.dependsOn.length === 0
            ? 0
            : Math.max(...step.dependsOn.map((d) => depth(d) + 1))
        depthCache.set(id, value)
        return value
    }
    // Group siblings by column so a fallback layout looks reasonable for
    // freshly seeded workflows that don't have positions yet.
    const rowsByCol = new Map<number, number>()
    let result = { x: 360, y: 80 }
    for (const step of steps.value) {
        const col = depth(step.id) + 1
        const row = rowsByCol.get(col) ?? 0
        rowsByCol.set(col, row + 1)
        if (step.id === targetId) {
            result = { x: col * 280 + 80, y: row * 150 + 80 }
        }
    }
    return result
}

const previewNodes = computed<Node[]>(() => {
    const stepNodes: Node[] = steps.value.map((step) => {
        const position = step.position ?? computeFallbackPosition(step.id)
        return {
            id: step.id,
            type: 'default',
            position,
            data: {
                kind: 'step',
                label: step.name,
                stepId: step.id,
                stepType: step.type,
                dependsCount: step.dependsOn.length,
            },
            class: ['flow-node-host', selectedStepId.value === step.id ? 'is-selected' : ''].join(' '),
        }
    })
    if (triggerDraft.value) {
        stepNodes.unshift({
            id: TRIGGER_NODE_ID,
            type: 'default',
            position: { ...triggerPosition.value },
            data: {
                kind: 'trigger',
                triggerType: triggerDraft.value.type,
                enabled: triggerDraft.value.enabled,
            },
            class: ['flow-node-host', selectedStepId.value === TRIGGER_NODE_ID ? 'is-selected' : ''].join(' '),
        })
    }
    return stepNodes
})

const previewEdges = computed<Edge[]>(() => {
    const stepEdges: Edge[] = steps.value.flatMap((step) =>
        step.dependsOn.map((dep) => ({
            id: `${dep}->${step.id}`,
            source: dep,
            target: step.id,
            markerEnd: MarkerType.ArrowClosed,
            animated: true,
        })),
    )

    // Connect the trigger to root steps (steps with no dependencies).
    if (triggerDraft.value) {
        const roots = steps.value.filter((s) => s.dependsOn.length === 0)
        for (const root of roots) {
            stepEdges.push({
                id: `${TRIGGER_NODE_ID}->${root.id}`,
                source: TRIGGER_NODE_ID,
                target: root.id,
                markerEnd: MarkerType.ArrowClosed,
                animated: true,
                style: { strokeDasharray: '6 4' },
            })
        }
    }
    return stepEdges
})

const modeBadge = computed(() => {
    if (mode.value === 'view') return { label: 'VIEW MODE', tone: 'pending' as const }
    if (mode.value === 'edit') return { label: 'EDIT MODE', tone: 'warning' as const }
    return { label: 'NEW WORKFLOW', tone: 'info' as const }
})

const quickStarts: { type: StepType; label: string; icon: string }[] = [
    { type: 'HTTP', label: 'HTTP request', icon: 'language' },
    { type: 'SCRIPT', label: 'Script', icon: 'code' },
    { type: 'CONDITION', label: 'Condition', icon: 'fork_right' },
    { type: 'DELAY', label: 'Delay', icon: 'hourglass_top' },
]
void quickStarts // referenced when we re-enable the empty-state quickstart row

function stepIcon(type?: string): string {
    switch (type) {
        case 'HTTP': return 'language'
        case 'SCRIPT': return 'code'
        case 'DELAY': return 'hourglass_top'
        case 'CONDITION': return 'fork_right'
        default: return 'workspaces'
    }
}

function minimapNodeColor(node: Node): string {
    if (node.id === TRIGGER_NODE_ID) return '#a78bfa'
    const t = (node.data?.stepType ?? '').toUpperCase()
    if (t === 'HTTP') return '#7bd0ff'
    if (t === 'SCRIPT') return '#bcc7de'
    if (t === 'DELAY') return '#fbbf24'
    if (t === 'CONDITION') return '#38bdf8'
    return '#909097'
}

function minimapStrokeColor(node: Node): string {
    return node.id === selectedStepId.value ? '#38bdf8' : 'transparent'
}

function nextId(prefix: string): string {
    let n = 1
    let candidate = `${prefix}_${n}`
    while (steps.value.some((s) => s.id === candidate)) {
        n += 1
        candidate = `${prefix}_${n}`
    }
    return candidate
}

function defaultConfigFor(type: StepType): Record<string, unknown> {
    switch (type) {
        case 'HTTP':
            return { method: 'GET', url: '', headers: {}, body: null, timeoutMs: 10000 }
        case 'DELAY':
            return { durationMs: 1000 }
        case 'CONDITION':
            return { field: '', operator: 'equals', value: '' }
        case 'SCRIPT':
            return { operation: 'noop' }
        default:
            return {}
    }
}

function addStep(type: StepType) {
    if (isReadOnly.value) return
    const id = nextId(type.toLowerCase())
    // Place new nodes diagonally below-right of the last node so the layout
    // grows naturally without re-flowing existing positions.
    const last = steps.value[steps.value.length - 1]
    const seed = last?.position
        ?? (steps.value.length === 0
            ? { x: triggerPosition.value.x + 320, y: triggerPosition.value.y }
            : { x: 360, y: 80 })
    const position = {
        x: seed.x + (last ? 60 : 0),
        y: seed.y + (last ? 140 : 0),
    }
    steps.value.push({
        id,
        name: `${type[0]}${type.slice(1).toLowerCase()} Step`,
        type,
        dependsOn: last ? [last.id] : [],
        config: defaultConfigFor(type),
        retry: {
            maxAttempts: defaultMaxAttempts.value,
            backoff: 'exponential',
            initialDelayMs: 1000,
        },
        position,
    })
    // Don't auto-select; inspector should appear only when the user clicks a node.
}

function removeStep(id: string) {
    steps.value = steps.value.filter((s) => s.id !== id)
    steps.value.forEach((s) => {
        s.dependsOn = s.dependsOn.filter((d) => d !== id)
    })
    if (selectedStepId.value === id) selectedStepId.value = null
}

function toggleDependency(payload: { stepId: string; dependencyId: string }) {
    const step = steps.value.find((s) => s.id === payload.stepId)
    if (!step) return
    if (step.dependsOn.includes(payload.dependencyId)) {
        step.dependsOn = step.dependsOn.filter((d) => d !== payload.dependencyId)
    } else {
        step.dependsOn.push(payload.dependencyId)
    }
}

function onConnect(connection: Connection) {
    if (!connection.source || !connection.target || connection.source === connection.target) return
    const target = steps.value.find((s) => s.id === connection.target)
    if (!target) return
    if (!target.dependsOn.includes(connection.source)) {
        target.dependsOn.push(connection.source)
    }
}

/**
 * Persist the dragged position back into builder state so the layout survives
 * subsequent re-renders (adding/removing other nodes, save/reload, etc.).
 */
function onNodeDragStop({ node }: { node: Node }) {
    if (isReadOnly.value) return
    const next = { x: node.position.x, y: node.position.y }
    if (node.id === TRIGGER_NODE_ID) {
        triggerPosition.value = next
        return
    }
    const step = steps.value.find((s) => s.id === node.id)
    if (step) {
        step.position = next
    }
}

function onNodeClick({ node }: { node: Node }) {
    if (node.id === TRIGGER_NODE_ID) {
        selectedStepId.value = TRIGGER_NODE_ID
        triggerInspectorOpen.value = true
        return
    }
    selectedStepId.value = node.id
    triggerInspectorOpen.value = false
}

function onPaneClick() {
    selectedStepId.value = null
    triggerInspectorOpen.value = false
}

function selectTrigger(type: WorkflowTriggerType) {
    triggerSelectorOpen.value = false
    triggerDraft.value = {
        type,
        cronExpression: type === 'scheduled' ? '0 * * * *' : '',
        timezone: 'UTC',
        webhookSecret: '',
        enabled: true,
    }
    selectedStepId.value = TRIGGER_NODE_ID
    triggerInspectorOpen.value = true
}

function updateTriggerDraft(draft: TriggerDraft) {
    triggerDraft.value = draft
}

function removeTrigger() {
    triggerDraft.value = null
    persistedTrigger.value = null
    triggerInspectorOpen.value = false
    if (selectedStepId.value === TRIGGER_NODE_ID) selectedStepId.value = null
}

function changeTriggerType() {
    triggerInspectorOpen.value = false
    triggerSelectorOpen.value = true
}

async function load(): Promise<void> {
    const workflowId = typeof route.query.workflowId === 'string' ? route.query.workflowId : null

    // Reset all builder state so navigating between workflows (or from
    // edit → create) doesn't leak stale data — steps, triggers, error banners,
    // selection, all get cleared.
    steps.value = []
    selectedStepId.value = null
    triggerDraft.value = null
    persistedTrigger.value = null
    triggerInspectorOpen.value = false
    triggerSelectorOpen.value = false
    triggerPosition.value = { x: 80, y: 80 }
    editingWorkflow.value = null
    workflowName.value = ''
    workflowDescription.value = ''
    globalTimeoutMs.value = 60000
    defaultMaxAttempts.value = 3
    initialStatus.value = 'draft'
    saveError.value = null
    testRunError.value = null

    // Hydrate from wizard query when creating a new workflow.
    if (!workflowId) {
        const queryName = typeof route.query.name === 'string' ? route.query.name : ''
        const queryDescription = typeof route.query.description === 'string' ? route.query.description : ''
        const queryTimeout = typeof route.query.globalTimeoutMs === 'string' ? Number(route.query.globalTimeoutMs) : NaN
        const queryAttempts = typeof route.query.maxAttempts === 'string' ? Number(route.query.maxAttempts) : NaN
        const queryStatus = typeof route.query.initialStatus === 'string' ? route.query.initialStatus : null
        if (queryName) workflowName.value = queryName
        if (queryDescription) workflowDescription.value = queryDescription
        if (Number.isFinite(queryTimeout) && queryTimeout > 0) globalTimeoutMs.value = queryTimeout
        if (Number.isFinite(queryAttempts) && queryAttempts >= 1 && queryAttempts <= 5) defaultMaxAttempts.value = queryAttempts
        if (queryStatus === 'draft' || queryStatus === 'active' || queryStatus === 'archived') initialStatus.value = queryStatus
        await nextTick()
        isDirty.value = false
        return
    }

    loadingWorkflow.value = true
    try {
        const workflow = await getWorkflow(workflowId)
        editingWorkflow.value = workflow
        workflowName.value = workflow.name
        workflowDescription.value = workflow.description ?? ''
        globalTimeoutMs.value = workflow.currentVersion?.definition.globalTimeoutMs ?? 60000
        steps.value = (workflow.currentVersion?.definition.steps ?? []).map((s) => ({
            id: s.id,
            name: s.name,
            type: (s.type as StepType) ?? 'HTTP',
            dependsOn: s.dependsOn ?? [],
            config: (s.config ?? {}) as Record<string, unknown>,
            retry: s.retry,
            timeoutMs: s.timeoutMs,
            position: s.position ? { x: s.position.x, y: s.position.y } : undefined,
        }))

        const savedTrigger = workflow.currentVersion?.definition.ui?.triggerPosition
        if (savedTrigger && Number.isFinite(savedTrigger.x) && Number.isFinite(savedTrigger.y)) {
            triggerPosition.value = { x: savedTrigger.x, y: savedTrigger.y }
        }

        // Load existing triggers; show the first one (n8n-style: one trigger per workflow).
        // Surface failures instead of swallowing — silent failure here is what
        // made trigger nodes vanish on edit before.
        try {
            const triggers = await listWorkflowTriggers(workflow.id)
            if (triggers.length > 0) {
                const t = triggers[0]
                persistedTrigger.value = t
                triggerDraft.value = {
                    type: t.type,
                    cronExpression: t.cronExpression ?? '',
                    timezone: t.timezone ?? 'UTC',
                    webhookSecret: '', // server-side stored, masked on read
                    enabled: t.enabled,
                }
            }
        } catch (err) {
            saveError.value = `Could not load triggers: ${err instanceof Error ? err.message : 'unknown error'}`
        }
    } catch (err) {
        saveError.value = err instanceof Error ? err.message : 'Failed to load workflow.'
    } finally {
        loadingWorkflow.value = false
        // After hydrate completes, the deep watcher fires once because refs
        // were re-assigned. Wait for that flush, then reset dirty so we don't
        // immediately treat a freshly loaded workflow as having unsaved edits.
        await nextTick()
        isDirty.value = false
    }
}

async function save() {
    if (isReadOnly.value) return
    saveError.value = null
    if (!workflowName.value.trim()) {
        saveError.value = 'Workflow name is required.'
        return
    }
    if (steps.value.length === 0) {
        saveError.value = 'Add at least one step before saving.'
        return
    }
    if (!triggerDraft.value) {
        saveError.value = 'Pick a trigger before saving — every workflow needs an entry-point.'
        triggerSelectorOpen.value = true
        return
    }
    if (triggerDraft.value.type === 'scheduled' && !triggerDraft.value.cronExpression.trim()) {
        saveError.value = 'Scheduled trigger requires a cron expression.'
        return
    }
    if (triggerDraft.value.type === 'webhook' && !triggerDraft.value.webhookSecret.trim() && !persistedTrigger.value) {
        saveError.value = 'Webhook trigger requires a secret. Click Generate in the trigger inspector.'
        return
    }
    saving.value = true
    try {
        const definition = buildDefinition()
        let saved: Workflow
        if (mode.value === 'edit' && editingWorkflow.value) {
            saved = await updateWorkflow(editingWorkflow.value.id, {
                name: workflowName.value,
                description: workflowDescription.value || null,
                status: editingWorkflow.value.status,
                change_summary: 'Edited via builder UI',
                definition,
            })
        } else {
            saved = await createWorkflow({
                name: workflowName.value,
                description: workflowDescription.value || null,
                status: initialStatus.value,
                change_summary: 'Created via builder UI',
                definition,
            })
        }
        editingWorkflow.value = saved

        // Reconcile trigger: if no persisted row yet, create one. If type changed, replace it.
        await syncTrigger(saved.id)

        // Mark clean before navigating so the route guard / future work won't
        // re-prompt with "discard unsaved changes".
        isDirty.value = false
        await router.push({ name: 'workflows.list' })
    } catch (err) {
        saveError.value = err instanceof Error ? err.message : 'Failed to save workflow.'
    } finally {
        saving.value = false
    }
}

async function syncTrigger(workflowId: string): Promise<void> {
    if (!triggerDraft.value) return
    const draft = triggerDraft.value

    // If we have a persisted trigger of the same type and the user did not rotate the secret,
    // we leave it alone. Otherwise we drop and recreate.
    const sameType = persistedTrigger.value?.type === draft.type
    const secretRotated = draft.type === 'webhook' && draft.webhookSecret.trim() !== ''
    const cronChanged = draft.type === 'scheduled'
        && persistedTrigger.value?.cronExpression !== draft.cronExpression
    const enabledChanged = persistedTrigger.value?.enabled !== draft.enabled

    if (sameType && !secretRotated && !cronChanged && !enabledChanged) {
        return
    }

    if (persistedTrigger.value) {
        try {
            await deleteWorkflowTrigger(workflowId, persistedTrigger.value.id)
        } catch {
            // best-effort
        }
    }

    const created = await createWorkflowTrigger(workflowId, {
        type: draft.type,
        cron_expression: draft.type === 'scheduled' ? draft.cronExpression : null,
        timezone: draft.timezone,
        webhook_secret: draft.type === 'webhook' ? (draft.webhookSecret || undefined) : null,
        enabled: draft.enabled,
    })
    persistedTrigger.value = created
}

function discard() {
    // No edits to lose — leave silently. Otherwise prompt with a Modal so the
    // confirmation matches the rest of the design system instead of relying on
    // the browser's native confirm() dialog.
    if (!isDirty.value && steps.value.length === 0 && !workflowName.value) {
        router.push({ name: 'workflows.list' })
        return
    }
    if (!isDirty.value) {
        router.push({ name: 'workflows.list' })
        return
    }
    discardDialogOpen.value = true
}

function confirmDiscard() {
    discardDialogOpen.value = false
    isDirty.value = false
    router.push({ name: 'workflows.list' })
}

function buildDefinition(): WorkflowDefinition {
    return {
        schemaVersion: 1,
        name: workflowName.value,
        globalTimeoutMs: globalTimeoutMs.value,
        steps: steps.value.map<WorkflowStepDefinition>((s) => ({
            id: s.id,
            name: s.name,
            type: s.type,
            dependsOn: s.dependsOn,
            config: s.config,
            retry: s.retry,
            timeoutMs: s.timeoutMs,
            position: s.position,
        })),
        ui: {
            triggerPosition: { x: triggerPosition.value.x, y: triggerPosition.value.y },
        },
    }
}

async function testRun() {
    if (isReadOnly.value) return
    testRunError.value = null
    if (!workflowName.value.trim()) {
        testRunError.value = 'Workflow name is required.'
        return
    }
    if (steps.value.length === 0) {
        testRunError.value = 'Add at least one step before running.'
        return
    }
    if (!triggerDraft.value) {
        testRunError.value = 'Pick a trigger before running — every workflow needs an entry-point.'
        triggerSelectorOpen.value = true
        return
    }
    testRunning.value = true
    try {
        const definition = buildDefinition()
        let workflowId = editingWorkflow.value?.id ?? null

        // Save (or create) so the run executes against the latest definition.
        if (workflowId) {
            const updated = await updateWorkflow(workflowId, {
                name: workflowName.value,
                description: workflowDescription.value || null,
                status: editingWorkflow.value!.status,
                change_summary: 'Test run from builder',
                definition,
            })
            editingWorkflow.value = updated
            workflowId = updated.id
        } else {
            const created = await createWorkflow({
                name: workflowName.value,
                description: workflowDescription.value || null,
                status: initialStatus.value === 'archived' ? 'draft' : initialStatus.value,
                change_summary: 'Created via builder test run',
                definition,
            })
            editingWorkflow.value = created
            workflowId = created.id
            // After saving the new workflow, sync the trigger and switch the URL
            // so subsequent saves go through the update path. Without this the
            // builder is stuck in 'create' mode and a second save would create
            // a duplicate workflow.
            await syncTrigger(workflowId)
            await router.replace({ name: 'workflows.builder', query: { workflowId } })
        }

        const run = await triggerWorkflow(workflowId)
        // The persisted snapshot is the new clean baseline.
        isDirty.value = false
        // Open the inline result modal — never redirect away from the builder.
        testRunModal.value = run
        startTestRunPolling(run.id)
    } catch (err) {
        testRunError.value = err instanceof Error ? err.message : 'Test run failed.'
    } finally {
        testRunning.value = false
    }
}

/**
 * Poll the run + its logs until it reaches a terminal status. We use polling
 * (not SSE) here because the builder modal is short-lived and the executor is
 * synchronous — most test runs finish before the first tick.
 */
function startTestRunPolling(runId: string) {
    stopTestRunPolling()
    const tick = async () => {
        if (!testRunModal.value || testRunModal.value.id !== runId) return
        try {
            const [run, logs] = await Promise.all([
                fetchWorkflowRun(runId),
                runLogs(runId),
            ])
            const terminal = ['SUCCESS', 'FAILED', 'TIMEOUT', 'CANCELLED', 'SKIPPED'].includes(run.status)
            testRunModal.value = { ...run, logs: logs.data }
            if (!terminal) {
                testRunPollHandle.value = window.setTimeout(tick, 1000)
            } else {
                testRunPollHandle.value = null
            }
        } catch {
            // Single failure isn't fatal; retry once. Stop after that.
            testRunPollHandle.value = window.setTimeout(tick, 2000)
        }
    }
    testRunPollHandle.value = window.setTimeout(tick, 200)
}

function stopTestRunPolling() {
    if (testRunPollHandle.value !== null) {
        clearTimeout(testRunPollHandle.value)
        testRunPollHandle.value = null
    }
}

function closeTestRunModal() {
    stopTestRunPolling()
    testRunModal.value = null
}

watch(() => route.query, () => {
    load()
})

// Mark builder as dirty whenever any user-editable state changes. This drives
// the Cancel discard prompt — we only nag the user if there's something to
// lose. The watcher is deep so node renames, dependency edits, config tweaks,
// drag positions, and trigger draft changes all flip the flag.
watch(
    () => [
        steps.value,
        triggerDraft.value,
        triggerPosition.value,
        workflowName.value,
        workflowDescription.value,
        globalTimeoutMs.value,
        defaultMaxAttempts.value,
        initialStatus.value,
    ],
    () => {
        isDirty.value = true
    },
    { deep: true },
)

onMounted(load)

// Cleanup polling timers if the component unmounts mid-run.
onBeforeUnmount(stopTestRunPolling)
</script>

<template>
    <!-- Builder occupies the full bleed main area (router meta.fullBleed = true). -->
    <div class="builder-shell">
        <!-- Top bar (single row, no wrap) -->
        <header class="builder-topbar">
            <div class="builder-topbar__left">
                <RouterLink
                    :to="{ name: 'workflows.list' }"
                    class="builder-topbar__back"
                    aria-label="Back to workflows"
                >
                    <Icon name="arrow_back" :size="20" />
                </RouterLink>
                <div class="flex flex-col leading-tight min-w-0">
                    <span class="text-label-caps font-label-caps text-secondary uppercase tracking-wider">Orchestration Builder</span>
                    <span class="text-body-sm text-on-surface-variant">{{ steps.length }} step{{ steps.length === 1 ? '' : 's' }}<span v-if="editingWorkflow"> · v{{ editingWorkflow.currentVersion?.versionNumber ?? '1' }}</span></span>
                </div>
            </div>

            <div class="builder-topbar__center">
                <h1 class="builder-topbar__title" :title="workflowName || 'Untitled workflow'">{{ workflowName || 'Untitled workflow' }}</h1>
                <Badge :tone="modeBadge.tone">{{ modeBadge.label }}</Badge>
            </div>

            <div class="builder-topbar__right">
                <Button variant="ghost" leading-icon="close" @click="discard">Cancel</Button>
                <Button
                    v-if="!isReadOnly"
                    variant="secondary"
                    leading-icon="science"
                    :disabled="testRunning || saving"
                    @click="testRun"
                >{{ testRunning ? 'Running…' : 'Test Run' }}</Button>
                <Button
                    v-if="!isReadOnly"
                    leading-icon="save"
                    :disabled="saving || testRunning"
                    glow
                    @click="save"
                >{{ saving ? 'Saving…' : 'Save' }}</Button>
            </div>
        </header>

        <Alert v-if="saveError" tone="error" class="builder-error">{{ saveError }}</Alert>
        <Alert v-if="testRunError" tone="error" class="builder-error">{{ testRunError }}</Alert>

        <!-- Canvas always renders so the dotted background is visible. -->
        <div class="builder-canvas-wrapper">
            <VueFlow
                :nodes="previewNodes"
                :edges="previewEdges"
                fit-view-on-init
                :default-zoom="1"
                :nodes-draggable="!isReadOnly"
                :nodes-connectable="!isReadOnly"
                :elements-selectable="!isReadOnly"
                @node-click="onNodeClick"
                @node-drag-stop="onNodeDragStop"
                @pane-click="onPaneClick"
                @connect="onConnect"
            >
                <template #node-default="{ data, selected }">
                    <div
                        v-if="data.kind === 'trigger'"
                        class="flow-node-terminator"
                        :class="[selected ? 'is-selected' : '', !data.enabled ? 'is-disabled' : '']"
                    >
                        <span class="flow-node-terminator__icon material-symbols-outlined">
                            {{ data.triggerType === 'manual' ? 'play_circle' : data.triggerType === 'scheduled' ? 'schedule' : 'webhook' }}
                        </span>
                        <span class="flow-node-terminator__copy">
                            <span class="flow-node-terminator__eyebrow">START</span>
                            <span class="flow-node-terminator__title">
                                {{ data.triggerType === 'manual' ? 'Manual trigger' : data.triggerType === 'scheduled' ? 'Scheduled trigger' : 'Webhook trigger' }}
                            </span>
                        </span>
                        <span class="flow-node-terminator__pip" :class="data.enabled ? 'is-on' : 'is-off'" :title="data.enabled ? 'enabled' : 'disabled'"></span>
                    </div>
                    <div
                        v-else
                        class="flow-node"
                        :class="[
                            `tone-${(data.stepType || 'http').toLowerCase()}`,
                            selected ? 'is-selected' : '',
                        ]"
                    >
                        <div class="flow-node__header">
                            <span class="flow-node__icon material-symbols-outlined" :data-icon="stepIcon(data.stepType)">{{ stepIcon(data.stepType) }}</span>
                            <span class="flow-node__name" :title="data.label">{{ data.label }}</span>
                            <span class="flow-node__type">{{ data.stepType }}</span>
                        </div>
                        <div class="flow-node__body">
                            <span class="flow-node__id">{{ data.stepId }}</span>
                            <span v-if="data.dependsCount > 0" class="flow-node__meta">
                                <span class="material-symbols-outlined" style="font-size: 12px;">link</span>
                                {{ data.dependsCount }} dep{{ data.dependsCount === 1 ? '' : 's' }}
                            </span>
                        </div>
                    </div>
                </template>
                <Background :gap="22" :size="1.2" pattern-color="rgba(144,144,151,0.18)" />
                <MiniMap
                    pannable
                    zoomable
                    class="builder-minimap"
                    :node-color="minimapNodeColor"
                    :node-stroke-color="minimapStrokeColor"
                    :node-stroke-width="3"
                    :node-border-radius="6"
                    mask-color="rgba(5,20,36,0.78)"
                />
                <Controls class="builder-controls" />
            </VueFlow>

            <!-- Loading state (only when explicitly loading from API) -->
            <div v-if="loadingWorkflow" class="builder-overlay">
                <div class="builder-empty">
                    <div class="builder-empty__halo">
                        <Icon name="hourglass_empty" :size="28" class="animate-spin" />
                    </div>
                    <h3 class="builder-empty__title">Loading workflow…</h3>
                    <p class="builder-empty__copy">Fetching the latest version from the API.</p>
                </div>
            </div>

            <!-- Empty state: only shown when there's no trigger yet. After the
                 trigger is picked, the canvas already has a visible node + the
                 palette on the left becomes the action target — no need for an
                 empty CTA card. -->
            <div
                v-else-if="!triggerDraft"
                class="builder-overlay builder-overlay--hint"
            >
                <div class="builder-empty">
                    <div class="builder-empty__halo">
                        <Icon name="bolt" :size="28" />
                    </div>
                    <h3 class="builder-empty__title">Pick a trigger to get started</h3>
                    <p class="builder-empty__copy">
                        Every FlowForge workflow needs an entry-point. Choose a trigger type — you can add steps after.
                    </p>
                    <Button
                        leading-icon="add"
                        glow
                        @click="triggerSelectorOpen = true"
                    >Choose trigger</Button>
                </div>
            </div>

            <!-- Floating palette (left) -->
            <div
                v-if="!isReadOnly"
                class="builder-palette-rail"
                :class="paletteOpen ? '' : 'is-collapsed'"
            >
                <Transition name="slide-left">
                    <div v-show="paletteOpen" class="builder-palette-rail__panel">
                        <NodePalette @add="addStep" />
                    </div>
                </Transition>
                <button
                    type="button"
                    class="builder-palette-rail__toggle"
                    :aria-label="paletteOpen ? 'Hide palette' : 'Show palette'"
                    @click="paletteOpen = !paletteOpen"
                >
                    <Icon :name="paletteOpen ? 'chevron_left' : 'chevron_right'" :size="18" />
                </button>
            </div>

            <!-- Floating inspector (right) - only visible when a node is selected -->
            <Transition name="slide-right">
                <div
                    v-if="inspectorOpen && selectedStep"
                    class="builder-inspector-rail"
                >
                    <StepInspector
                        :step="selectedStep"
                        :available-steps="availableDeps"
                        @remove="removeStep"
                        @toggle-dependency="toggleDependency"
                    />
                </div>
            </Transition>

            <Transition name="slide-right">
                <div
                    v-if="triggerInspectorOpen && triggerDraft && !isReadOnly"
                    class="builder-inspector-rail"
                >
                    <TriggerInspector
                        :draft="triggerDraft"
                        :workflow-id="editingWorkflow?.id ?? null"
                        :webhook-url="webhookUrl"
                        :persisted-secret-masked="!!persistedTrigger && persistedTrigger.type === 'webhook' && !triggerDraft.webhookSecret"
                        @update="updateTriggerDraft"
                        @remove="removeTrigger"
                        @change-type="changeTriggerType"
                    />
                </div>
            </Transition>
        </div>

        <TriggerSelector
            :open="triggerSelectorOpen"
            :title="triggerDraft ? 'Change trigger type' : undefined"
            :subtitle="triggerDraft ? 'Switching the trigger replaces the current entry-point.' : undefined"
            @close="triggerSelectorOpen = false"
            @select="selectTrigger"
        />

        <!-- Test run result modal — opens after a successful Test Run, polls the
             run + logs until terminal status, then stays open so the user can
             review the path and logs without leaving the builder. -->
        <TestRunOverlay
            v-if="testRunModal"
            :run="testRunModal"
            :is-open="!!testRunModal"
            @close="closeTestRunModal"
        />

        <!-- Discard-unsaved-changes confirmation. Only mounts when triggered so
             ESC/backdrop don't accidentally interfere with the canvas. -->
        <Modal
            :open="discardDialogOpen"
            title="Discard unsaved changes?"
            subtitle="The workflow definition has unsaved edits. Closing now will lose them."
            width="md"
            @close="discardDialogOpen = false"
        >
            <p class="text-body-sm text-on-surface-variant px-lg py-md m-0">
                Save first if you want to keep the trigger, steps, and layout you've changed.
            </p>
            <template #footer>
                <Button variant="ghost" @click="discardDialogOpen = false">Keep editing</Button>
                <Button variant="secondary" leading-icon="delete_sweep" @click="confirmDiscard">Discard changes</Button>
            </template>
        </Modal>
    </div>
</template>

<style scoped>
.builder-shell {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 4rem); /* full main area, minus AppShell topbar */
    background: var(--color-surface);
    overflow: hidden;
}

.builder-topbar {
    position: relative;
    z-index: 30;
    display: grid;
    grid-template-columns: minmax(0, auto) minmax(0, 1fr) minmax(0, auto);
    align-items: center;
    gap: 16px;
    padding: 12px 16px;
    background: color-mix(in srgb, var(--color-surface) 92%, transparent);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid color-mix(in srgb, var(--color-outline-variant) 40%, transparent);
}

.builder-topbar__left {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.builder-topbar__back {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: var(--radius-lg);
    background: color-mix(in srgb, var(--color-surface-container-high) 60%, transparent);
    border: 1px solid color-mix(in srgb, var(--color-outline-variant) 40%, transparent);
    color: var(--color-on-surface-variant);
    text-decoration: none;
    transition: color 0.15s ease, border-color 0.15s ease, background 0.15s ease;
    flex-shrink: 0;
}

.builder-topbar__back:hover {
    color: var(--color-on-surface);
    background: color-mix(in srgb, var(--color-surface-variant) 60%, transparent);
    border-color: color-mix(in srgb, var(--color-secondary) 40%, transparent);
}

.builder-topbar__center {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.builder-topbar__name,
.builder-topbar__title {
    flex: 1;
    min-width: 0;
    background: transparent;
    border: 0;
    padding: 6px 4px;
    font-family: var(--font-headline-md);
    font-size: 18px;
    font-weight: 700;
    color: var(--color-on-surface);
    transition: border-color 0.15s ease;
    outline: none;
    margin: 0;
}

.builder-topbar__title {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.builder-topbar__name {
    border-bottom: 1px solid transparent;
}

.builder-topbar__name:hover:not(:disabled) {
    border-bottom-color: color-mix(in srgb, var(--color-outline-variant) 60%, transparent);
}

.builder-topbar__name:focus {
    border-bottom-color: var(--color-secondary);
}

.builder-topbar__name::placeholder {
    color: color-mix(in srgb, var(--color-on-surface-variant) 50%, transparent);
}

.builder-topbar__right {
    display: flex;
    align-items: center;
    gap: 8px;
    justify-self: end;
}

.builder-topbar__details {
    display: none;
}

.builder-error {
    margin: 8px 16px 0;
}

.builder-canvas-wrapper {
    position: relative;
    flex: 1;
    min-height: 0;
    overflow: hidden;
    background:
        radial-gradient(circle at 22% 18%, color-mix(in srgb, var(--color-secondary) 6%, transparent), transparent 65%),
        radial-gradient(circle at 80% 80%, color-mix(in srgb, var(--color-tertiary) 5%, transparent), transparent 65%),
        var(--color-surface);
}

.builder-canvas-wrapper :deep(.vue-flow) {
    width: 100%;
    height: 100%;
    background: transparent;
}

.builder-canvas-wrapper :deep(.vue-flow__background) {
    background: transparent;
}

.builder-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
    z-index: 5;
}

.builder-overlay__card {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 4px;
    padding: 24px;
    border-radius: var(--radius-xl);
    background: color-mix(in srgb, var(--color-surface-container) 80%, transparent);
    backdrop-filter: blur(12px);
    border: 1px solid color-mix(in srgb, var(--color-outline-variant) 50%, transparent);
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.25);
    max-width: 320px;
}

.builder-empty {
    pointer-events: auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 14px;
    padding: 28px 32px;
    border-radius: var(--radius-xl);
    background: color-mix(in srgb, var(--color-surface-container) 86%, transparent);
    backdrop-filter: blur(14px);
    border: 1px solid color-mix(in srgb, var(--color-outline-variant) 60%, transparent);
    box-shadow: 0 18px 40px rgba(0, 0, 0, 0.32);
    max-width: 380px;
}

.builder-empty__halo {
    position: relative;
    width: 56px;
    height: 56px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--color-secondary);
    background: color-mix(in srgb, var(--color-secondary) 14%, transparent);
    border: 1px solid color-mix(in srgb, var(--color-secondary) 40%, transparent);
    box-shadow: 0 0 0 6px color-mix(in srgb, var(--color-secondary) 6%, transparent);
}

.builder-empty__halo::after {
    content: '';
    position: absolute;
    inset: -10px;
    border-radius: 22px;
    border: 1px dashed color-mix(in srgb, var(--color-secondary) 25%, transparent);
    pointer-events: none;
    animation: empty-halo 6s linear infinite;
}

@keyframes empty-halo {
    to { transform: rotate(360deg); }
}

.builder-empty__title {
    font-family: var(--font-headline-md);
    font-size: 20px;
    font-weight: 700;
    color: var(--color-on-surface);
    margin: 0;
    letter-spacing: -0.01em;
}

.builder-empty__copy {
    margin: 0;
    color: var(--color-on-surface-variant);
    font-size: 13px;
    line-height: 1.55;
    max-width: 320px;
}

.builder-empty__chips {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 8px;
    padding-top: 10px;
    margin-top: 4px;
    border-top: 1px solid color-mix(in srgb, var(--color-outline-variant) 30%, transparent);
    width: 100%;
}

.builder-empty__chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 9999px;
    border: 1px solid color-mix(in srgb, var(--color-outline-variant) 60%, transparent);
    background: color-mix(in srgb, var(--color-surface-container-high) 60%, transparent);
    color: var(--color-on-surface);
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.02em;
    cursor: pointer;
    transition: border-color 0.15s ease, background 0.15s ease, color 0.15s ease, transform 0.15s ease;
}

.builder-empty__chip:hover {
    border-color: color-mix(in srgb, var(--color-secondary) 50%, transparent);
    background: color-mix(in srgb, var(--color-secondary) 10%, transparent);
    color: var(--color-secondary);
    transform: translateY(-1px);
}

.builder-palette-rail {
    position: absolute;
    left: 16px;
    top: 16px;
    bottom: 16px;
    z-index: 20;
    display: flex;
    align-items: stretch;
    pointer-events: none;
}

.builder-palette-rail__panel {
    width: 256px;
    max-width: calc(50vw - 80px);
    pointer-events: auto;
    overflow-y: auto;
    border-radius: var(--radius-xl);
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.25);
}

.builder-palette-rail__toggle {
    align-self: flex-start;
    margin-left: 4px;
    margin-top: 4px;
    width: 28px;
    height: 28px;
    border-radius: 9999px;
    background: var(--color-surface-container);
    border: 1px solid var(--color-outline-variant);
    color: var(--color-on-surface-variant);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    pointer-events: auto;
    transition: color 0.15s ease, border-color 0.15s ease;
}

.builder-palette-rail__toggle:hover {
    color: var(--color-on-surface);
    border-color: color-mix(in srgb, var(--color-secondary) 50%, transparent);
}

.builder-inspector-rail {
    position: absolute;
    right: 16px;
    top: 16px;
    bottom: 16px;
    z-index: 20;
    width: 320px;
    max-width: calc(60vw - 80px);
    overflow-y: auto;
    border-radius: var(--radius-xl);
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.25);
}

/* Vue Flow plugin chrome */
.builder-canvas-wrapper :deep(.builder-controls) {
    background: color-mix(in srgb, var(--color-surface-container) 90%, transparent);
    border: 1px solid color-mix(in srgb, var(--color-outline-variant) 50%, transparent);
    border-radius: var(--radius-lg);
    overflow: hidden;
    margin: 16px;
}

.builder-canvas-wrapper :deep(.builder-controls .vue-flow__controls-button) {
    background: transparent;
    color: var(--color-on-surface-variant);
    border-bottom: 1px solid color-mix(in srgb, var(--color-outline-variant) 30%, transparent);
}

.builder-canvas-wrapper :deep(.builder-controls .vue-flow__controls-button:hover) {
    background: color-mix(in srgb, var(--color-surface-variant) 60%, transparent);
    color: var(--color-on-surface);
}

.builder-canvas-wrapper :deep(.builder-controls .vue-flow__controls-button svg) {
    fill: currentColor;
}

.builder-canvas-wrapper :deep(.builder-minimap) {
    background: color-mix(in srgb, var(--color-surface-container) 80%, transparent);
    border: 1px solid color-mix(in srgb, var(--color-outline-variant) 50%, transparent);
    border-radius: var(--radius-lg);
    margin: 16px;
}

/* Transitions */
.slide-left-enter-active,
.slide-left-leave-active,
.slide-right-enter-active,
.slide-right-leave-active,
.slide-down-enter-active,
.slide-down-leave-active {
    transition: transform 0.18s ease, opacity 0.18s ease;
}

.slide-left-enter-from,
.slide-left-leave-to {
    transform: translateX(-12px);
    opacity: 0;
}

.slide-right-enter-from,
.slide-right-leave-to {
    transform: translateX(12px);
    opacity: 0;
}

.slide-down-enter-from,
.slide-down-leave-to {
    transform: translateY(-6px);
    opacity: 0;
    max-height: 0;
}

/* DAG node — patterned after Stitch builder cards */
.builder-canvas-wrapper :deep(.vue-flow__node) {
    background: transparent !important;
    border: 0 !important;
    padding: 0 !important;
    box-shadow: none !important;
    color: var(--color-on-surface);
}

.builder-canvas-wrapper :deep(.vue-flow__node-default) {
    background: transparent;
    border: 0;
    padding: 0;
    color: inherit;
}

.flow-node-host {
    background: transparent;
    border: 0;
    padding: 0;
    box-shadow: none;
    width: 240px;
}

.flow-node-host.selected,
.flow-node-host.is-selected {
    box-shadow: none;
}

.flow-node {
    width: 240px;
    border-radius: var(--radius-lg);
    background: var(--color-surface-container-high);
    border: 1px solid var(--color-outline-variant);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.32);
    overflow: hidden;
    text-align: left;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.flow-node.is-selected,
.flow-node-host.selected .flow-node,
.flow-node-host.is-selected .flow-node {
    border-color: color-mix(in srgb, var(--color-secondary) 60%, transparent);
    box-shadow:
        0 0 0 1px color-mix(in srgb, var(--color-secondary) 60%, transparent),
        0 12px 30px rgba(0, 0, 0, 0.4),
        0 0 18px color-mix(in srgb, var(--color-secondary) 25%, transparent);
}

.flow-node__header {
    display: grid;
    grid-template-columns: 24px minmax(0, 1fr) auto;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    background: color-mix(in srgb, var(--color-surface-container-high) 88%, var(--color-secondary) 0%);
    border-bottom: 1px solid color-mix(in srgb, var(--color-outline-variant) 60%, transparent);
}

.flow-node__icon {
    width: 24px;
    height: 24px;
    border-radius: var(--radius-DEFAULT);
    background: color-mix(in srgb, var(--color-secondary) 12%, transparent);
    color: var(--color-secondary);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 16px !important;
}

.flow-node__name {
    font-size: 13px;
    font-weight: 600;
    color: var(--color-on-surface);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    min-width: 0;
}

.flow-node__type {
    font-family: var(--font-code-md);
    font-size: 10px;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    font-weight: 700;
    color: var(--color-on-surface-variant);
    background: color-mix(in srgb, var(--color-surface-variant) 80%, transparent);
    border: 1px solid color-mix(in srgb, var(--color-outline-variant) 50%, transparent);
    padding: 2px 6px;
    border-radius: 9999px;
    white-space: nowrap;
}

.flow-node__body {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 8px 12px;
    background: var(--color-surface-container-lowest);
    color: var(--color-on-surface-variant);
    font-size: 11px;
    font-family: var(--font-code-md);
    letter-spacing: 0.02em;
}

.flow-node__id {
    color: var(--color-secondary);
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.flow-node__meta {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: var(--color-on-surface-variant);
}

/* Tone accents — left border per step type */
.flow-node.tone-http {
    border-left: 3px solid var(--color-secondary);
}
.flow-node.tone-http .flow-node__icon {
    background: color-mix(in srgb, var(--color-secondary) 14%, transparent);
    color: var(--color-secondary);
}

.flow-node.tone-script {
    border-left: 3px solid var(--color-tertiary);
}
.flow-node.tone-script .flow-node__icon {
    background: color-mix(in srgb, var(--color-tertiary) 14%, transparent);
    color: var(--color-tertiary);
}

.flow-node.tone-delay {
    border-left: 3px solid var(--color-warning);
}
.flow-node.tone-delay .flow-node__icon {
    background: color-mix(in srgb, var(--color-warning) 14%, transparent);
    color: var(--color-warning);
}

.flow-node.tone-condition {
    border-left: 3px solid var(--color-running);
}

/* Trigger node — flowchart "terminator" (stadium / pill).
 * Visually distinct from the rectangular process steps so the entry point
 * reads as the start of the flow at a glance. */
.flow-node-terminator {
    --terminator-tint: #a78bfa;
    width: 240px;
    min-height: 64px;
    padding: 10px 18px 10px 14px;
    display: grid;
    grid-template-columns: 36px minmax(0, 1fr) auto;
    align-items: center;
    gap: 12px;
    border-radius: 9999px;
    background: linear-gradient(135deg,
        color-mix(in srgb, var(--terminator-tint) 22%, var(--color-surface-container-high)) 0%,
        var(--color-surface-container-high) 65%
    );
    border: 1.5px solid color-mix(in srgb, var(--terminator-tint) 55%, var(--color-outline-variant));
    color: var(--color-on-surface);
    box-shadow:
        0 8px 24px rgba(0, 0, 0, 0.32),
        inset 0 0 0 1px color-mix(in srgb, var(--terminator-tint) 18%, transparent);
    text-align: left;
    transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}

.flow-node-terminator.is-selected {
    border-color: color-mix(in srgb, var(--terminator-tint) 90%, transparent);
    box-shadow:
        0 0 0 1px color-mix(in srgb, var(--terminator-tint) 80%, transparent),
        0 12px 32px rgba(0, 0, 0, 0.42),
        0 0 22px color-mix(in srgb, var(--terminator-tint) 35%, transparent);
}

.flow-node-terminator.is-disabled {
    opacity: 0.65;
    border-style: dashed;
}

.flow-node-terminator__icon {
    width: 36px;
    height: 36px;
    border-radius: 9999px;
    background: color-mix(in srgb, var(--terminator-tint) 24%, transparent);
    color: var(--terminator-tint);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 20px !important;
    border: 1px solid color-mix(in srgb, var(--terminator-tint) 50%, transparent);
}

.flow-node-terminator__copy {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.flow-node-terminator__eyebrow {
    font-family: var(--font-code-md);
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.18em;
    color: var(--terminator-tint);
    text-transform: uppercase;
    line-height: 1;
}

.flow-node-terminator__title {
    font-size: 13px;
    font-weight: 700;
    color: var(--color-on-surface);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.2;
}

.flow-node-terminator__pip {
    width: 10px;
    height: 10px;
    border-radius: 9999px;
    background: var(--color-success);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-success) 25%, transparent);
}

.flow-node-terminator__pip.is-off {
    background: var(--color-warning);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-warning) 25%, transparent);
}

.flow-node__meta.tone-on { color: var(--color-success); }
.flow-node__meta.tone-off { color: var(--color-warning); }
.flow-node.tone-condition .flow-node__icon {
    background: color-mix(in srgb, var(--color-running) 14%, transparent);
    color: var(--color-running);
}

/* Vue Flow handle re-style to match Stitch port pattern */
.builder-canvas-wrapper :deep(.vue-flow__handle) {
    width: 10px;
    height: 10px;
    border-radius: 9999px;
    background: var(--color-surface);
    border: 2px solid var(--color-outline-variant);
    box-shadow: 0 0 0 1px var(--color-surface);
}

.builder-canvas-wrapper :deep(.flow-node-host.selected .vue-flow__handle),
.builder-canvas-wrapper :deep(.flow-node-host.is-selected .vue-flow__handle) {
    border-color: var(--color-secondary);
}

.builder-canvas-wrapper :deep(.vue-flow__edge-path) {
    stroke: color-mix(in srgb, var(--color-outline-variant) 80%, transparent);
    stroke-width: 1.6;
}

.builder-canvas-wrapper :deep(.vue-flow__edge.animated .vue-flow__edge-path) {
    stroke: color-mix(in srgb, var(--color-secondary) 70%, transparent);
}
</style>
