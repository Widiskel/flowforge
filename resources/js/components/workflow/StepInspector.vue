<script setup lang="ts">
import { computed } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import HttpStepForm from './forms/HttpStepForm.vue'
import DelayStepForm from './forms/DelayStepForm.vue'
import ConditionStepForm from './forms/ConditionStepForm.vue'
import ScriptStepForm from './forms/ScriptStepForm.vue'
import type { BuilderStep } from './forms/_shared'

const props = defineProps<{
    step: BuilderStep | null
    availableSteps: BuilderStep[]
}>()

defineEmits<{
    (e: 'remove', id: string): void
    (e: 'toggle-dependency', payload: { stepId: string; dependencyId: string }): void
}>()

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
</script>

<template>
    <aside class="step-inspector">
        <header class="step-inspector__header">
            <h3 class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Node Inspector</h3>
        </header>
        <div class="step-inspector__body">
            <EmptyState
                v-if="!step"
                icon="ads_click"
                title="Select a step"
                description="Click a node on the canvas to inspect and edit its configuration."
                compact
            />
            <div v-else class="flex flex-col gap-md">
                <div class="flex items-center justify-between gap-sm">
                    <div class="flex items-center gap-sm min-w-0">
                        <span
                            :class="[
                                'inline-flex items-center justify-center w-9 h-9 rounded-DEFAULT shrink-0',
                                step.type === 'HTTP' ? 'bg-secondary/12 text-secondary' :
                                step.type === 'SCRIPT' ? 'bg-tertiary/12 text-tertiary' :
                                step.type === 'DELAY' ? 'bg-warning/12 text-warning' :
                                'bg-running/12 text-running',
                            ]"
                        >
                            <Icon :name="stepIcon" :size="18" />
                        </span>
                        <div class="flex flex-col gap-0.5 min-w-0">
                            <Badge :tone="tone">{{ step.type }}</Badge>
                            <code class="text-code-sm font-code-sm text-on-surface-variant truncate">{{ step.id }}</code>
                        </div>
                    </div>
                    <Button
                        variant="icon"
                        aria-label="Remove step"
                        class="!text-failed hover:!bg-failed/10 shrink-0"
                        @click="$emit('remove', step.id)"
                    >
                        <Icon name="delete" :size="18" />
                    </Button>
                </div>

                <label class="flex flex-col gap-1">
                    <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Display name</span>
                    <input
                        v-model="step.name"
                        class="input-dark rounded-DEFAULT px-sm py-1.5 text-body-md w-full"
                        placeholder="Human-readable name"
                    >
                </label>

                <section class="flex flex-col gap-sm">
                    <h4 class="text-label-caps font-label-caps text-secondary uppercase tracking-wider m-0">Configuration</h4>
                    <component
                        :is="formComponent"
                        v-if="formComponent && step"
                        :step="step"
                        :available-steps="availableSteps"
                    />
                </section>

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
    padding: 12px 16px;
    border-bottom: 1px solid color-mix(in srgb, var(--color-outline-variant) 40%, transparent);
    background: var(--color-surface-container-high);
}

.step-inspector__body {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
}
</style>
