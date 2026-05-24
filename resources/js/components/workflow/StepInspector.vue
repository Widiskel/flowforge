<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Tabs from '@/components/ui/Tabs.vue'
import Alert from '@/components/ui/Alert.vue'
import HttpStepForm from './forms/HttpStepForm.vue'
import DelayStepForm from './forms/DelayStepForm.vue'
import ConditionStepForm from './forms/ConditionStepForm.vue'
import ScriptStepForm from './forms/ScriptStepForm.vue'
import LogStepForm from './forms/LogStepForm.vue'
import type { BuilderStep } from './forms/_shared'
import { simulateStep, type SimulateStepResponse } from '@/services/api/client'

const props = defineProps<{
    step: BuilderStep | null
    availableSteps: BuilderStep[]
}>()

defineEmits<{
    (e: 'remove', id: string): void
    (e: 'toggle-dependency', payload: { stepId: string; dependencyId: string }): void
}>()

type TabKey = 'parameters' | 'input' | 'output' | 'settings'
const activeTab = ref<TabKey>('parameters')

watch(
    () => props.step?.id,
    () => {
        activeTab.value = 'parameters'
    },
)

const tabs = computed(() => [
    { value: 'parameters' as TabKey, label: 'Parameters' },
    { value: 'input' as TabKey, label: 'Input' },
    { value: 'output' as TabKey, label: 'Output' },
    { value: 'settings' as TabKey, label: 'Settings' },
])

const formComponent = computed(() => {
    if (!props.step) return null
    switch (props.step.type) {
        case 'HTTP': return HttpStepForm
        case 'DELAY': return DelayStepForm
        case 'CONDITION': return ConditionStepForm
        case 'SCRIPT': return ScriptStepForm
        case 'LOG': return LogStepForm
        default: return null
    }
})

const tone = computed(() => {
    if (!props.step) return 'info' as const
    switch (props.step.type) {
        case 'HTTP': return 'info' as const
        case 'SCRIPT': return 'pending' as const
        case 'DELAY': return 'warning' as const
        case 'CONDITION': return 'running' as const
        case 'LOG': return 'success' as const
    }
    return 'info' as const
})

const stepIcon = computed(() => {
    if (!props.step) return 'tune'
    switch (props.step.type) {
        case 'HTTP': return 'language'
        case 'SCRIPT': return 'code'
        case 'DELAY': return 'hourglass_top'
        case 'CONDITION': return 'fork_right'
        case 'LOG': return 'description'
    }
    return 'tune'
})

const maxAttempts = computed({
    get: () => Number(props.step?.retry?.maxAttempts ?? 1),
    set: (value: number) => {
        if (!props.step) return
        if (!props.step.retry) props.step.retry = {}
        const v = Number.isFinite(value) ? Math.max(1, Math.min(5, value)) : 1
        props.step.retry.maxAttempts = v
    },
})

const backoff = computed({
    get: () => String(props.step?.retry?.backoff ?? 'exponential'),
    set: (value: string) => {
        if (!props.step) return
        if (!props.step.retry) props.step.retry = {}
        props.step.retry.backoff = value === 'fixed' ? 'fixed' : 'exponential'
    },
})

const initialDelayMs = computed({
    get: () => Number(props.step?.retry?.initialDelayMs ?? 1000),
    set: (value: number) => {
        if (!props.step) return
        if (!props.step.retry) props.step.retry = {}
        const v = Number.isFinite(value) ? Math.max(100, Math.min(60000, value)) : 1000
        props.step.retry.initialDelayMs = v
    },
})

const maxDelayMs = computed({
    get: () => {
        const raw = props.step?.retry?.maxDelayMs
        return raw === undefined || raw === null ? '' : String(raw)
    },
    set: (value: string) => {
        if (!props.step) return
        if (!props.step.retry) props.step.retry = {}
        if (value === '') {
            delete props.step.retry.maxDelayMs
            return
        }
        const n = Number(value)
        if (Number.isFinite(n) && n > 0) {
            props.step.retry.maxDelayMs = Math.min(120000, Math.max(100, n))
        }
    },
})

const previewDelays = computed(() => {
    if (!props.step) return [] as number[]
    const attempts = maxAttempts.value
    const initial = initialDelayMs.value
    const cap = props.step.retry?.maxDelayMs
    const out: number[] = []
    for (let i = 0; i < attempts - 1; i++) {
        let d = backoff.value === 'exponential' ? initial * Math.pow(2, i) : initial
        if (typeof cap === 'number') d = Math.min(d, cap)
        out.push(d)
    }
    return out
})

function formatMs(ms: number): string {
    if (ms < 1000) return `${ms}ms`
    return `${(ms / 1000).toFixed(ms % 1000 === 0 ? 0 : 1)}s`
}

const notes = computed({
    get: () => props.step?.notes ?? '',
    set: (value: string) => {
        if (!props.step) return
        props.step.notes = value
    },
})

const displayNoteInFlow = computed({
    get: () => !!props.step?.displayNoteInFlow,
    set: (value: boolean) => {
        if (!props.step) return
        props.step.displayNoteInFlow = value
    },
})

// ---------------------------------------------------------------------------
// Inspector simulation state.
//
// Two distinct concerns split across the Input and Output tabs:
//
//   Input tab  — capture the upstream context. Primary action is
//                "Execute upstream", which simulates each dependency node in
//                topological order and shows the accumulated context per
//                upstream node. An "Edit manually" toggle reveals a raw JSON
//                editor for advanced users; otherwise the tab stays
//                read-only.
//
//   Output tab — run *this* node against whatever context was captured.
//                Shows the status badge, duration, error if any, and the
//                output payload.
//
// `upstreamContext` holds the accumulated upstream output (keyed by step id)
// — same shape that gets passed to the simulator's `input` field.
// `manualMode` flips the Input tab between the read-only render and the raw
// JSON editor; `inputDraft` is the editable buffer that backs that editor.
// ---------------------------------------------------------------------------

const upstreamContext = ref<Record<string, unknown> | null>(null)
const manualMode = ref(false)
const inputDraft = ref<string>('')
const outputDraft = ref<string>('')
const upstreamRunning = ref(false)
const stepRunning = ref(false)
const upstreamError = ref<string | null>(null)
const stepError = ref<string | null>(null)
const stepResult = ref<SimulateStepResponse | null>(null)

watch(
    () => props.step?.id,
    () => {
        // Each time the user picks a different node, clear the working state
        // so we don't accidentally show one node's results on another.
        upstreamContext.value = null
        manualMode.value = false
        inputDraft.value = ''
        outputDraft.value = ''
        upstreamError.value = null
        stepError.value = null
        stepResult.value = null
    },
)

const upstreamEntries = computed<Array<{ id: string; output: unknown }>>(() => {
    const ctx = upstreamContext.value
    if (!ctx) return []
    return Object.entries(ctx).map(([id, output]) => ({ id, output }))
})

const hasUpstreamContext = computed(() => upstreamContext.value !== null && Object.keys(upstreamContext.value).length > 0)

const stepResultTone = computed(() => {
    const s = stepResult.value?.status
    if (!s) return 'info' as const
    if (s === 'SUCCESS') return 'success' as const
    if (s === 'SKIPPED') return 'warning' as const
    return 'failed' as const
})

/**
 * Topologically order this step's transitive upstream graph, simulate each
 * one in turn, and accumulate their outputs into a context object keyed by
 * step id. Returns null when something failed so the caller can stop.
 */
async function runUpstreamChain(): Promise<Record<string, unknown> | null> {
    if (!props.step) return {}

    const byId = new Map(props.availableSteps.map((s) => [s.id, s]))
    const visited = new Set<string>()
    const order: BuilderStep[] = []
    const visit = (id: string) => {
        const node = byId.get(id)
        if (!node || visited.has(id)) return
        visited.add(id)
        for (const dep of node.dependsOn) visit(dep)
        order.push(node)
    }
    for (const dep of props.step.dependsOn) visit(dep)

    const accumulated: Record<string, unknown> = {}
    for (const upstream of order) {
        try {
            const stepInput: Record<string, unknown> = {}
            for (const dep of upstream.dependsOn) {
                if (Object.prototype.hasOwnProperty.call(accumulated, dep)) {
                    stepInput[dep] = accumulated[dep]
                }
            }
            const result = await simulateStep({
                type: upstream.type,
                config: upstream.config ?? {},
                input: stepInput,
            })
            accumulated[upstream.id] = result.output
            if (result.status === 'FAILED') {
                upstreamError.value = `Upstream ${upstream.id} failed: ${result.error ?? 'unknown error'}`
                return null
            }
        } catch (err) {
            upstreamError.value = `Upstream ${upstream.id} could not be executed: ${err instanceof Error ? err.message : 'unknown error'}`
            return null
        }
    }
    return accumulated
}

async function executeUpstream(): Promise<void> {
    if (!props.step) return
    upstreamError.value = null
    upstreamRunning.value = true
    upstreamContext.value = null
    try {
        const ctx = await runUpstreamChain()
        if (ctx === null) return
        upstreamContext.value = ctx
        inputDraft.value = JSON.stringify(ctx, null, 2)
    } finally {
        upstreamRunning.value = false
    }
}

function enterManualMode() {
    if (!manualMode.value) {
        inputDraft.value = upstreamContext.value
            ? JSON.stringify(upstreamContext.value, null, 2)
            : '{}'
    }
    manualMode.value = true
}

function exitManualMode() {
    manualMode.value = false
    upstreamError.value = null
    // Keep the manually edited blob persistable as upstreamContext when valid;
    // otherwise the Output tab will fall back to executing upstream itself.
    const draft = inputDraft.value.trim()
    if (draft === '') {
        upstreamContext.value = null
        return
    }
    try {
        const decoded = JSON.parse(draft)
        if (decoded && typeof decoded === 'object' && !Array.isArray(decoded)) {
            upstreamContext.value = decoded as Record<string, unknown>
        }
    } catch {
        // Leave upstreamContext untouched; the output tab will surface the
        // parse error when the user tries to execute.
    }
}

async function executeStep(): Promise<void> {
    if (!props.step) return
    stepError.value = null
    stepResult.value = null
    outputDraft.value = ''
    stepRunning.value = true

    let resolvedInput: Record<string, unknown> = {}

    if (manualMode.value) {
        const draft = inputDraft.value.trim()
        if (draft !== '') {
            try {
                const decoded = JSON.parse(draft)
                if (decoded && typeof decoded === 'object' && !Array.isArray(decoded)) {
                    resolvedInput = decoded as Record<string, unknown>
                } else {
                    throw new Error('Manual input must be a JSON object keyed by step id.')
                }
            } catch (err) {
                stepError.value = err instanceof Error ? err.message : 'Invalid JSON input.'
                stepRunning.value = false
                return
            }
        }
    } else if (props.step.dependsOn.length > 0) {
        // Auto-mode: re-run upstream every time the user clicks Execute so the
        // captured context matches the latest dependency configuration.
        if (!upstreamContext.value) {
            const ctx = await runUpstreamChain()
            if (ctx === null) {
                stepRunning.value = false
                return
            }
            upstreamContext.value = ctx
            inputDraft.value = JSON.stringify(ctx, null, 2)
        }
        resolvedInput = upstreamContext.value as Record<string, unknown>
    }

    try {
        const result = await simulateStep({
            type: props.step.type,
            config: props.step.config ?? {},
            input: resolvedInput,
        })
        stepResult.value = result
        outputDraft.value = JSON.stringify(result.output ?? {}, null, 2)
    } catch (err) {
        stepError.value = err instanceof Error ? err.message : 'Step execution failed.'
    } finally {
        stepRunning.value = false
    }
}
</script>

<template>
    <aside class="step-inspector">
        <header class="step-inspector__header">
            <template v-if="step">
                <span
                    :class="[
                        'inline-flex items-center justify-center w-8 h-8 rounded-DEFAULT shrink-0',
                        step.type === 'HTTP' ? 'bg-secondary/12 text-secondary' :
                        step.type === 'SCRIPT' ? 'bg-tertiary/12 text-tertiary' :
                        step.type === 'DELAY' ? 'bg-warning/12 text-warning' :
                        step.type === 'LOG' ? 'bg-success/12 text-success' :
                        'bg-running/12 text-running',
                    ]"
                >
                    <Icon :name="stepIcon" :size="16" />
                </span>
                <div class="flex flex-col leading-tight min-w-0 flex-1">
                    <span class="text-body-md font-bold text-on-surface truncate">{{ step.name || 'Untitled step' }}</span>
                    <code class="text-code-sm font-code-sm text-on-surface-variant truncate">{{ step.id }}</code>
                </div>
                <Button
                    variant="icon"
                    aria-label="Remove step"
                    class="!text-failed hover:!bg-failed/10 shrink-0"
                    @click="$emit('remove', step.id)"
                >
                    <Icon name="delete" :size="18" />
                </Button>
            </template>
            <h3 v-else class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Node Inspector</h3>
        </header>

        <Tabs v-if="step" v-model="activeTab" :items="tabs" class="step-inspector__tabs px-md pt-sm" />

        <div class="step-inspector__body">
            <EmptyState
                v-if="!step"
                icon="ads_click"
                title="Select a step"
                description="Click a node on the canvas to inspect and edit its configuration."
                compact
            />
            <div v-else-if="activeTab === 'parameters'" class="flex flex-col gap-md">
                <label class="flex flex-col gap-1">
                    <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Display name</span>
                    <input
                        v-model="step.name"
                        class="input-dark rounded-DEFAULT px-sm py-1.5 text-body-md w-full"
                        placeholder="Human-readable name"
                    >
                </label>

                <component
                    :is="formComponent"
                    v-if="formComponent && step"
                    :step="step"
                    :available-steps="availableSteps"
                />

                <section class="flex flex-col gap-sm">
                    <h4 class="text-label-caps font-label-caps text-secondary uppercase tracking-wider m-0">Dependencies</h4>
                    <p v-if="availableSteps.length === 0" class="text-body-sm text-on-surface-variant m-0">No earlier steps available — this is a root step.</p>
                    <div v-else class="flex flex-wrap gap-1.5">
                        <button
                            v-for="dep in availableSteps"
                            :key="dep.id"
                            type="button"
                            :class="[
                                'inline-flex items-center gap-1.5 px-sm py-1 rounded-full text-code-sm font-code-sm border transition-colors',
                                step.dependsOn.includes(dep.id)
                                    ? 'bg-secondary/12 text-secondary border-secondary/40'
                                    : 'bg-surface-variant/40 text-on-surface-variant border-outline-variant/40 hover:text-on-surface',
                            ]"
                            @click="$emit('toggle-dependency', { stepId: step.id, dependencyId: dep.id })"
                        >
                            <Icon
                                :name="step.dependsOn.includes(dep.id) ? 'check' : 'add'"
                                :size="12"
                            />
                            {{ dep.id }}
                        </button>
                    </div>
                </section>
            </div>

            <div v-else-if="activeTab === 'input'" class="flex flex-col gap-md">
                <header class="flex flex-col gap-1">
                    <h4 class="text-label-caps font-label-caps text-secondary uppercase tracking-wider m-0">Upstream input</h4>
                    <p class="text-body-sm text-on-surface-variant m-0">
                        Execute the dependency chain to capture the real context this step would receive at runtime.
                    </p>
                </header>

                <p
                    v-if="step.dependsOn.length === 0"
                    class="rounded-DEFAULT bg-surface-container-low border border-outline-variant/40 p-sm text-body-sm text-on-surface-variant m-0"
                >
                    This step has no dependencies — it runs as a root with an empty input.
                </p>

                <template v-else>
                    <div class="flex flex-wrap items-center gap-sm">
                        <Button
                            leading-icon="play_arrow"
                            :disabled="upstreamRunning"
                            @click="executeUpstream"
                        >{{ upstreamRunning ? 'Executing…' : (hasUpstreamContext ? 'Re-execute upstream' : 'Execute upstream') }}</Button>
                        <Button
                            v-if="!manualMode"
                            size="sm"
                            variant="ghost"
                            leading-icon="edit"
                            :disabled="upstreamRunning"
                            @click="enterManualMode"
                        >Edit manually</Button>
                        <Button
                            v-else
                            size="sm"
                            variant="ghost"
                            leading-icon="auto_fix_high"
                            :disabled="upstreamRunning"
                            @click="exitManualMode"
                        >Use captured context</Button>
                    </div>

                    <Alert v-if="upstreamError" tone="error" compact>{{ upstreamError }}</Alert>

                    <!-- Captured upstream output, grouped per node so the user
                         can see at a glance what each dependency produces. -->
                    <div
                        v-if="!manualMode && hasUpstreamContext"
                        class="flex flex-col gap-sm"
                    >
                        <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Captured per upstream node</p>
                        <div
                            v-for="entry in upstreamEntries"
                            :key="entry.id"
                            class="rounded-DEFAULT border border-outline-variant/40 bg-surface-container-low/50 overflow-hidden"
                        >
                            <header class="flex items-center justify-between gap-sm px-sm py-1.5 border-b border-outline-variant/30 bg-surface-container-low">
                                <code class="text-code-sm font-code-sm font-bold text-secondary">{{ entry.id }}</code>
                                <span class="text-body-sm text-on-surface-variant">output</span>
                            </header>
                            <pre class="m-0 p-sm text-code-sm font-code-sm text-on-surface overflow-auto max-h-[180px]">{{ JSON.stringify(entry.output, null, 2) }}</pre>
                        </div>
                    </div>

                    <p
                        v-else-if="!manualMode && !hasUpstreamContext"
                        class="rounded-DEFAULT bg-surface-container-low border border-outline-variant/40 p-sm text-body-sm text-on-surface-variant m-0 text-center"
                    >
                        Click <em>Execute upstream</em> to run each dependency and capture its output here.
                    </p>

                    <!-- Manual JSON editor for advanced users who want to
                         hand-craft the upstream context. -->
                    <div v-if="manualMode" class="flex flex-col gap-sm">
                        <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Manual JSON override</p>
                        <textarea
                            v-model="inputDraft"
                            rows="10"
                            class="input-dark rounded-DEFAULT px-sm py-1.5 text-code-sm font-code-sm w-full resize-y"
                            spellcheck="false"
                            placeholder='{ "fetch_user": { "status": 200, "json": { "id": 1 } } }'
                        />
                        <p class="text-body-sm text-on-surface-variant m-0">
                            Object keyed by dependency step id. Click <em>Use captured context</em> to commit this and switch back to the read-only view.
                        </p>
                    </div>
                </template>
            </div>

            <div v-else-if="activeTab === 'output'" class="flex flex-col gap-md">
                <header class="flex items-start justify-between gap-sm">
                    <div class="flex flex-col gap-1 min-w-0">
                        <h4 class="text-label-caps font-label-caps text-secondary uppercase tracking-wider m-0">Step output</h4>
                        <p class="text-body-sm text-on-surface-variant m-0">
                            Run this node against the upstream context captured in the Input tab. This is exactly what downstream nodes would see.
                        </p>
                    </div>
                    <div v-if="stepResult" class="flex items-center gap-sm shrink-0">
                        <Badge :tone="stepResultTone">{{ stepResult.status }}</Badge>
                        <span class="text-body-sm text-on-surface-variant">{{ stepResult.duration_ms }}ms</span>
                    </div>
                </header>

                <Button
                    leading-icon="bolt"
                    :disabled="stepRunning"
                    @click="executeStep"
                >{{ stepRunning ? 'Executing…' : (stepResult ? 'Re-execute this step' : 'Execute this step') }}</Button>

                <Alert v-if="stepError" tone="error" compact>{{ stepError }}</Alert>

                <div v-if="stepResult?.error" class="rounded-DEFAULT border border-failed/40 bg-failed/8 p-sm flex flex-col gap-1">
                    <p class="text-label-caps font-label-caps text-failed uppercase m-0">Error</p>
                    <p class="m-0 text-body-sm text-on-surface">{{ stepResult.error }}</p>
                </div>

                <div class="flex flex-col gap-1">
                    <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Output payload</p>
                    <pre class="m-0 p-sm rounded-DEFAULT bg-[#02080f] border border-outline-variant/30 text-code-sm font-code-sm text-on-surface overflow-auto max-h-[320px]">{{ outputDraft || '// Click "Execute this step" to see the output' }}</pre>
                </div>
            </div>

            <div v-else class="flex flex-col gap-md">
                <Badge :tone="tone" class="self-start">{{ step.type }}</Badge>

                <section class="flex flex-col gap-sm">
                    <h4 class="text-label-caps font-label-caps text-secondary uppercase tracking-wider m-0">Retry policy</h4>
                    <div class="grid grid-cols-2 gap-sm">
                        <label class="flex flex-col gap-1">
                            <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Max attempts</span>
                            <input
                                v-model.number="maxAttempts"
                                type="number"
                                min="1"
                                max="5"
                                class="input-dark rounded-DEFAULT px-sm py-1.5 text-body-md w-full tabular-nums"
                            >
                        </label>
                        <label class="flex flex-col gap-1">
                            <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Strategy</span>
                            <select
                                v-model="backoff"
                                class="input-dark rounded-DEFAULT px-sm py-1.5 text-body-md w-full"
                            >
                                <option value="exponential">Exponential</option>
                                <option value="fixed">Fixed</option>
                            </select>
                        </label>
                        <label class="flex flex-col gap-1">
                            <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Initial delay (ms)</span>
                            <input
                                v-model.number="initialDelayMs"
                                type="number"
                                min="100"
                                max="60000"
                                step="100"
                                class="input-dark rounded-DEFAULT px-sm py-1.5 text-body-md w-full tabular-nums"
                            >
                        </label>
                        <label class="flex flex-col gap-1">
                            <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Max delay cap</span>
                            <input
                                v-model="maxDelayMs"
                                type="number"
                                min="100"
                                max="120000"
                                step="100"
                                placeholder="Unlimited"
                                class="input-dark rounded-DEFAULT px-sm py-1.5 text-body-md w-full tabular-nums"
                            >
                        </label>
                    </div>
                    <div
                        v-if="previewDelays.length > 0"
                        class="rounded-DEFAULT bg-surface-container-low border border-outline-variant/40 p-sm flex flex-col gap-1"
                    >
                        <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Computed retry delays</p>
                        <div class="flex flex-wrap gap-1.5 items-center">
                            <span class="text-code-sm font-code-sm text-on-surface-variant">attempt 1 → instant</span>
                            <span
                                v-for="(d, idx) in previewDelays"
                                :key="idx"
                                class="px-sm py-0.5 rounded-full bg-secondary/10 text-secondary text-code-sm font-code-sm border border-secondary/30"
                            >wait {{ formatMs(d) }} → attempt {{ idx + 2 }}</span>
                        </div>
                    </div>
                    <p v-else class="text-body-sm text-on-surface-variant m-0">Single attempt — no retries.</p>
                </section>

                <section class="flex flex-col gap-sm">
                    <h4 class="text-label-caps font-label-caps text-secondary uppercase tracking-wider m-0">Notes</h4>
                    <textarea
                        v-model="notes"
                        rows="4"
                        class="input-dark rounded-DEFAULT px-sm py-1.5 text-body-sm w-full resize-y"
                        placeholder="Internal documentation for this step…"
                    />
                    <label class="flex items-center justify-between gap-sm cursor-pointer">
                        <span class="text-body-sm text-on-surface">Display note in flow</span>
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="displayNoteInFlow"
                            :class="['toggle', displayNoteInFlow ? 'is-on' : '']"
                            @click="displayNoteInFlow = !displayNoteInFlow"
                        >
                            <span class="toggle__dot" />
                        </button>
                    </label>
                </section>
            </div>
        </div>
    </aside>
</template>

<style scoped>
.step-inspector {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 0;
    background: var(--color-surface-container);
    border: 1px solid color-mix(in srgb, var(--color-outline-variant) 40%, transparent);
    border-radius: var(--radius-xl);
    overflow: hidden;
}

.step-inspector__header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid color-mix(in srgb, var(--color-outline-variant) 40%, transparent);
    background: var(--color-surface-container-high);
    flex-shrink: 0;
}

.step-inspector__tabs {
    flex-shrink: 0;
    background: var(--color-surface-container);
    border-bottom: 1px solid color-mix(in srgb, var(--color-outline-variant) 25%, transparent);
}

.step-inspector__body {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 16px;
    /* Prevent layout shift when long output appears in Output tab */
    overscroll-behavior: contain;
}

.toggle {
    width: 38px;
    height: 22px;
    border-radius: 999px;
    background: color-mix(in srgb, var(--color-outline-variant) 50%, transparent);
    border: 0;
    position: relative;
    cursor: pointer;
    padding: 0;
    transition: background 0.15s ease;
}

.toggle.is-on {
    background: var(--color-secondary);
}

.toggle__dot {
    position: absolute;
    top: 2px;
    left: 2px;
    width: 18px;
    height: 18px;
    border-radius: 999px;
    background: var(--color-on-secondary);
    transition: transform 0.15s ease;
}

.toggle.is-on .toggle__dot {
    transform: translateX(16px);
}
</style>
