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
        simulationResult.value = null
        simulationError.value = null
        // Don't auto-clear inputDraft — user might want to keep their JSON
        // around when they click between nodes — but clear computed output.
        outputDraft.value = ''
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
// Simulation: lets the user feed a fake upstream context into a single step
// and see what it produces, without persisting anything. Backend honors the
// same handler stack the executor uses, so behavior matches a real run.
// ---------------------------------------------------------------------------

const inputDraft = ref<string>('')
const outputDraft = ref<string>('')
const simulating = ref(false)
const simulationError = ref<string | null>(null)
const simulationResult = ref<SimulateStepResponse | null>(null)

/**
 * Build a starter input shape for the inspector by inspecting upstream
 * dependencies. We can't know exactly what an upstream HTTP call returns, so
 * we sketch realistic placeholders the user can edit.
 */
function suggestInput(): Record<string, unknown> {
    if (!props.step) return {}
    const out: Record<string, unknown> = {}
    for (const depId of props.step.dependsOn) {
        const dep = props.availableSteps.find((s) => s.id === depId)
        if (!dep) continue
        switch (dep.type) {
            case 'HTTP':
                out[dep.id] = {
                    status: 200,
                    body: '{"ok":true}',
                }
                break
            case 'SCRIPT':
                out[dep.id] = { transformed: true }
                break
            case 'DELAY':
                out[dep.id] = { delayed_ms: 1000 }
                break
            case 'CONDITION':
                out[dep.id] = { evaluated: true }
                break
        }
    }
    return out
}

function fillSuggestedInput() {
    inputDraft.value = JSON.stringify(suggestInput(), null, 2)
}

async function runSimulation(): Promise<void> {
    if (!props.step) return
    simulationError.value = null
    simulationResult.value = null
    outputDraft.value = ''
    simulating.value = true

    let parsedInput: Record<string, unknown> = {}
    if (inputDraft.value.trim() !== '') {
        try {
            const decoded = JSON.parse(inputDraft.value)
            if (decoded && typeof decoded === 'object' && !Array.isArray(decoded)) {
                parsedInput = decoded as Record<string, unknown>
            } else {
                throw new Error('Input must be a JSON object keyed by step id.')
            }
        } catch (err) {
            simulationError.value = err instanceof Error ? err.message : 'Invalid JSON input.'
            simulating.value = false
            return
        }
    }

    try {
        const result = await simulateStep({
            type: props.step.type,
            config: props.step.config ?? {},
            input: parsedInput,
        })
        simulationResult.value = result
        outputDraft.value = JSON.stringify(result.output ?? {}, null, 2)
    } catch (err) {
        simulationError.value = err instanceof Error ? err.message : 'Simulation failed.'
    } finally {
        simulating.value = false
    }
}

const simulationStatusTone = computed(() => {
    const s = simulationResult.value?.status
    if (!s) return 'info' as const
    if (s === 'SUCCESS') return 'success' as const
    if (s === 'SKIPPED') return 'warning' as const
    return 'failed' as const
})
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

        <Tabs v-if="step" v-model="activeTab" :items="tabs" class="px-md pt-sm" />

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
                <div class="flex items-start justify-between gap-sm">
                    <div class="flex flex-col gap-1 min-w-0">
                        <h4 class="text-label-caps font-label-caps text-secondary uppercase tracking-wider m-0">Simulated input</h4>
                        <p class="text-body-sm text-on-surface-variant m-0">
                            Mock the context this step would receive from upstream nodes. Keys should match dependency step ids.
                        </p>
                    </div>
                    <Button
                        size="sm"
                        variant="ghost"
                        leading-icon="auto_fix_high"
                        :disabled="step.dependsOn.length === 0"
                        @click="fillSuggestedInput"
                    >Suggest</Button>
                </div>
                <textarea
                    v-model="inputDraft"
                    rows="10"
                    class="input-dark rounded-DEFAULT px-sm py-1.5 text-code-sm font-code-sm w-full resize-y"
                    spellcheck="false"
                    :placeholder="step.dependsOn.length > 0
                        ? '{\n  &quot;' + step.dependsOn[0] + '&quot;: { &quot;status&quot;: 200, &quot;body&quot;: &quot;{\\&quot;ok\\&quot;:true}&quot; }\n}'
                        : '{}'"
                />
                <p v-if="step.dependsOn.length === 0" class="text-body-sm text-on-surface-variant m-0">
                    This step has no dependencies — leave the input empty to simulate a root step.
                </p>
                <div class="flex items-center gap-sm">
                    <Button
                        leading-icon="play_arrow"
                        :disabled="simulating"
                        @click="runSimulation"
                    >{{ simulating ? 'Simulating…' : 'Simulate with this input' }}</Button>
                    <span v-if="simulationResult" class="text-body-sm text-on-surface-variant">
                        Last run · {{ simulationResult.duration_ms }}ms · status
                        <code class="font-code-sm text-on-surface">{{ simulationResult.status }}</code>
                    </span>
                </div>
                <Alert v-if="simulationError" tone="error" compact>{{ simulationError }}</Alert>
            </div>

            <div v-else-if="activeTab === 'output'" class="flex flex-col gap-md">
                <div class="flex items-start justify-between gap-sm">
                    <div class="flex flex-col gap-1 min-w-0">
                        <h4 class="text-label-caps font-label-caps text-secondary uppercase tracking-wider m-0">Simulated output</h4>
                        <p class="text-body-sm text-on-surface-variant m-0">
                            Run this step in isolation. The output below is exactly what downstream nodes would see in their context.
                        </p>
                    </div>
                    <Badge v-if="simulationResult" :tone="simulationStatusTone">{{ simulationResult.status }}</Badge>
                </div>

                <div class="flex items-center gap-sm flex-wrap">
                    <Button
                        leading-icon="play_arrow"
                        :disabled="simulating"
                        @click="runSimulation"
                    >{{ simulating ? 'Simulating…' : (simulationResult ? 'Re-run simulation' : 'Run simulation') }}</Button>
                    <span v-if="simulationResult" class="text-body-sm text-on-surface-variant">
                        {{ simulationResult.duration_ms }}ms
                    </span>
                </div>

                <Alert v-if="simulationError" tone="error" compact>{{ simulationError }}</Alert>

                <div v-if="simulationResult?.error" class="rounded-DEFAULT border border-failed/40 bg-failed/8 p-sm flex flex-col gap-1">
                    <p class="text-label-caps font-label-caps text-failed uppercase m-0">Error</p>
                    <p class="m-0 text-body-sm text-on-surface">{{ simulationResult.error }}</p>
                </div>

                <div class="flex flex-col gap-1">
                    <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Output payload</p>
                    <pre class="m-0 p-sm rounded-DEFAULT bg-[#02080f] border border-outline-variant/30 text-code-sm font-code-sm text-on-surface overflow-auto max-h-[320px]">{{ outputDraft || '// Click "Run simulation" to see the output' }}</pre>
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
}

.step-inspector__body {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
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
