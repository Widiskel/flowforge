<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
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
import { createWorkflow, getWorkflow, updateWorkflow, triggerWorkflow } from '@/services/api/client'
import type { Workflow, WorkflowDefinition, WorkflowStepDefinition } from '@/types/api'

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
const loadingWorkflow = ref(false)
const saveError = ref<string | null>(null)
const editingWorkflow = ref<Workflow | null>(null)
const paletteOpen = ref(true)

const mode = computed<'create' | 'edit' | 'view'>(() => {
    if (route.query.mode === 'view') return 'view'
    if (route.query.workflowId || route.query.mode === 'edit') return 'edit'
    return 'create'
})
const isReadOnly = computed(() => mode.value === 'view')

const selectedStep = computed(() => steps.value.find((s) => s.id === selectedStepId.value) ?? null)
const inspectorOpen = computed(() => selectedStep.value !== null && !isReadOnly.value)
const availableDeps = computed(() => {
    if (!selectedStep.value) return []
    return steps.value.filter((s) => s.id !== selectedStep.value!.id)
})

const previewNodes = computed<Node[]>(() => {
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
    const rowsByCol = new Map<number, number>()
    return steps.value.map((step) => {
        const col = depth(step.id)
        const row = rowsByCol.get(col) ?? 0
        rowsByCol.set(col, row + 1)
        return {
            id: step.id,
            type: 'default',
            position: { x: col * 280 + 80, y: row * 150 + 80 },
            data: {
                label: step.name,
                stepId: step.id,
                stepType: step.type,
                dependsCount: step.dependsOn.length,
            },
            class: ['flow-node-host', selectedStepId.value === step.id ? 'is-selected' : ''].join(' '),
        }
    })
})

const previewEdges = computed<Edge[]>(() =>
    steps.value.flatMap((step) =>
        step.dependsOn.map((dep) => ({
            id: `${dep}->${step.id}`,
            source: dep,
            target: step.id,
            markerEnd: MarkerType.ArrowClosed,
            animated: true,
        })),
    ),
)

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
    steps.value.push({
        id,
        name: `${type[0]}${type.slice(1).toLowerCase()} Step`,
        type,
        dependsOn: steps.value.length > 0 ? [steps.value[steps.value.length - 1].id] : [],
        config: defaultConfigFor(type),
        retry: {
            maxAttempts: defaultMaxAttempts.value,
            backoff: 'exponential',
            initialDelayMs: 1000,
        },
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

function onNodeClick({ node }: { node: Node }) {
    selectedStepId.value = node.id
}

function onPaneClick() {
    selectedStepId.value = null
}

async function load(): Promise<void> {
    const workflowId = typeof route.query.workflowId === 'string' ? route.query.workflowId : null

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
        }))
    } catch (err) {
        saveError.value = err instanceof Error ? err.message : 'Failed to load workflow.'
    } finally {
        loadingWorkflow.value = false
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
    saving.value = true
    try {
        const definition = buildDefinition()
        if (mode.value === 'edit' && editingWorkflow.value) {
            await updateWorkflow(editingWorkflow.value.id, {
                name: workflowName.value,
                description: workflowDescription.value || null,
                status: editingWorkflow.value.status,
                change_summary: 'Edited via builder UI',
                definition,
            })
        } else {
            await createWorkflow({
                name: workflowName.value,
                description: workflowDescription.value || null,
                status: initialStatus.value,
                change_summary: 'Created via builder UI',
                definition,
            })
        }
        await router.push({ name: 'workflows.list' })
    } catch (err) {
        saveError.value = err instanceof Error ? err.message : 'Failed to save workflow.'
    } finally {
        saving.value = false
    }
}

function discard() {
    if (steps.value.length === 0 && !workflowName.value) {
        router.push({ name: 'workflows.list' })
        return
    }
    if (confirm('Discard unsaved changes?')) {
        router.push({ name: 'workflows.list' })
    }
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
        })),
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
    testRunning.value = true
    try {
        const definition = buildDefinition()
        let workflowId = editingWorkflow.value?.id ?? null

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
        }

        const run = await triggerWorkflow(workflowId)
        await router.push({ name: 'runs', query: { runId: run.id } })
    } catch (err) {
        testRunError.value = err instanceof Error ? err.message : 'Test run failed.'
    } finally {
        testRunning.value = false
    }
}

watch(() => route.query, () => {
    load()
})

onMounted(load)
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
                >{{ saving ? 'Saving…' : mode === 'edit' ? 'Update' : 'Save' }}</Button>
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
                @pane-click="onPaneClick"
                @connect="onConnect"
            >
                <template #node-default="{ data, selected }">
                    <div
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

            <!-- Empty hint card sits over canvas without blocking interaction. -->
            <div
                v-else-if="steps.length === 0"
                class="builder-overlay builder-overlay--hint"
            >
                <div class="builder-empty">
                    <div class="builder-empty__halo">
                        <Icon name="auto_awesome_motion" :size="28" />
                    </div>
                    <h3 class="builder-empty__title">Start building your workflow</h3>
                    <p class="builder-empty__copy">
                        Pick a step type from the palette on the left. Each new step links to the previous one automatically.
                    </p>
                    <div class="builder-empty__chips">
                        <button
                            v-for="quick in quickStarts"
                            :key="quick.type"
                            type="button"
                            class="builder-empty__chip"
                            @click="addStep(quick.type)"
                        >
                            <Icon :name="quick.icon" :size="16" />
                            <span>{{ quick.label }}</span>
                        </button>
                    </div>
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
        </div>
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
