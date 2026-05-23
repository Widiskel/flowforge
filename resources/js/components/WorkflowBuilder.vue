<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { MarkerType, VueFlow, type Edge, type Node } from '@vue-flow/core'
import type { WorkflowDefinition, WorkflowStepDefinition } from '@/types/api'

const props = defineProps<{
    modelValue?: WorkflowDefinition | null
    name?: string
    description?: string | null
}>()

const emit = defineEmits<{
    (event: 'update:modelValue', value: WorkflowDefinition): void
    (event: 'update:name', value: string): void
    (event: 'update:description', value: string): void
}>()

const stepTypes = ['HTTP', 'SCRIPT', 'DELAY', 'CONDITION'] as const
type StepType = typeof stepTypes[number]

interface BuilderStep {
    id: string
    name: string
    type: StepType
    dependsOn: string[]
    config: Record<string, unknown>
}

const localName = ref<string>(props.name ?? '')
const localDescription = ref<string>(props.description ?? '')
const globalTimeoutMs = ref<number>(props.modelValue?.globalTimeoutMs ?? 60000)
const steps = ref<BuilderStep[]>(
    (props.modelValue?.steps ?? []).map((step) => normaliseStep(step)),
)

watch(
    () => props.modelValue,
    (next) => {
        globalTimeoutMs.value = next?.globalTimeoutMs ?? 60000
        steps.value = (next?.steps ?? []).map((step) => normaliseStep(step))
    },
)

watch(
    () => props.name ?? '',
    (next) => {
        localName.value = next
    },
)

watch(
    () => props.description ?? '',
    (next) => {
        localDescription.value = next
    },
)

watch(localName, (next) => emit('update:name', next))
watch(localDescription, (next) => emit('update:description', next))

const definition = computed<WorkflowDefinition>(() => ({
    schemaVersion: 1,
    name: localName.value,
    globalTimeoutMs: globalTimeoutMs.value,
    steps: steps.value.map((step) => ({
        id: step.id,
        name: step.name,
        type: step.type,
        dependsOn: [...step.dependsOn],
        config: { ...step.config },
    })),
}))

watch(definition, (next) => emit('update:modelValue', next), { deep: true })

function normaliseStep(step: WorkflowStepDefinition): BuilderStep {
    return {
        id: step.id,
        name: step.name,
        type: (step.type as StepType) ?? 'SCRIPT',
        dependsOn: step.dependsOn ? [...step.dependsOn] : [],
        config: step.config ? { ...step.config } : {},
    }
}

function addStep(): void {
    const id = `step_${steps.value.length + 1}`
    steps.value.push({
        id,
        name: `Step ${steps.value.length + 1}`,
        type: 'SCRIPT',
        dependsOn: [],
        config: { operation: 'noop' },
    })
}

function removeStep(index: number): void {
    const removed = steps.value[index]
    steps.value.splice(index, 1)
    steps.value.forEach((step) => {
        step.dependsOn = step.dependsOn.filter((depId) => depId !== removed.id)
    })
}

function moveStep(index: number, direction: -1 | 1): void {
    const target = index + direction
    if (target < 0 || target >= steps.value.length) return
    const moved = steps.value[index]
    steps.value[index] = steps.value[target]
    steps.value[target] = moved
}

function setStepType(step: BuilderStep, type: StepType): void {
    step.type = type
    if (type === 'SCRIPT') {
        step.config = { operation: 'noop' }
    } else if (type === 'HTTP') {
        step.config = { method: 'GET', url: '' }
    } else if (type === 'DELAY') {
        step.config = { ms: 1000 }
    } else if (type === 'CONDITION') {
        step.config = { expression: 'true' }
    }
}

function toggleDependency(step: BuilderStep, depId: string): void {
    if (step.dependsOn.includes(depId)) {
        step.dependsOn = step.dependsOn.filter((value) => value !== depId)
    } else {
        step.dependsOn = [...step.dependsOn, depId]
    }
}

function configString(step: BuilderStep, key: string, fallback = ''): string {
    const value = step.config[key]
    return typeof value === 'string' ? value : fallback
}

function configNumber(step: BuilderStep, key: string, fallback = 0): number {
    const value = step.config[key]
    return typeof value === 'number' ? value : fallback
}

function setConfigString(step: BuilderStep, key: string, event: Event): void {
    const target = event.target as HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement
    step.config = { ...step.config, [key]: target.value }
}

function setConfigNumber(step: BuilderStep, key: string, event: Event): void {
    const target = event.target as HTMLInputElement
    const parsed = Number(target.value)
    step.config = { ...step.config, [key]: Number.isFinite(parsed) ? parsed : 0 }
}

const previewNodes = computed<Node[]>(() => {
    const depthCache = new Map<string, number>()

    function resolveDepth(stepId: string): number {
        if (depthCache.has(stepId)) {
            return depthCache.get(stepId) ?? 0
        }
        const step = steps.value.find((item) => item.id === stepId)
        if (!step) return 0
        const value = step.dependsOn.length === 0
            ? 0
            : Math.max(...step.dependsOn.map((dependency) => resolveDepth(dependency) + 1))
        depthCache.set(stepId, value)
        return value
    }

    const rowsByDepth = new Map<number, number>()
    return steps.value.map((step) => {
        const column = resolveDepth(step.id)
        const row = rowsByDepth.get(column) ?? 0
        rowsByDepth.set(column, row + 1)
        return {
            id: step.id,
            type: 'default',
            position: { x: column * 200, y: row * 110 },
            data: { label: `${step.name}\n${step.type}` },
            class: `flow-node flow-node-${step.type.toLowerCase()}`,
        }
    })
})

const previewEdges = computed<Edge[]>(() =>
    steps.value.flatMap((step) =>
        step.dependsOn.map((dependency) => ({
            id: `${dependency}-${step.id}`,
            source: dependency,
            target: step.id,
            markerEnd: MarkerType.ArrowClosed,
        })),
    ),
)

const validationErrors = computed<string[]>(() => {
    const errors: string[] = []
    const ids = new Set<string>()
    if (!localName.value.trim()) {
        errors.push('Workflow name belum diisi.')
    }
    if (steps.value.length === 0) {
        errors.push('Workflow harus memiliki minimal satu step.')
    }
    steps.value.forEach((step) => {
        if (!step.id.trim()) {
            errors.push('Setiap step harus punya id.')
        }
        if (ids.has(step.id)) {
            errors.push(`Step id duplikat: ${step.id}.`)
        }
        ids.add(step.id)
        if (!step.name.trim()) {
            errors.push(`Step ${step.id || '(tanpa id)'} belum punya nama.`)
        }
        if (step.dependsOn.includes(step.id)) {
            errors.push(`Step ${step.id} tidak boleh depend ke dirinya sendiri.`)
        }
    })
    return errors
})

defineExpose({ validationErrors, definition })
</script>

<template>
    <section class="builder-form">
        <header class="builder-form__head">
            <div>
                <p class="eyebrow">Workflow builder</p>
                <h2>{{ localName || 'Workflow baru' }}</h2>
                <p class="muted">Tambah step, atur dependency, lalu simpan untuk mempublikasikan workflow ke tenant ini.</p>
            </div>
            <ul v-if="validationErrors.length" class="builder-form__errors">
                <li v-for="message in validationErrors" :key="message">{{ message }}</li>
            </ul>
        </header>

        <div class="builder-form__body">
            <div class="builder-form__fields">
                <label>
                    <span>Nama workflow</span>
                    <input v-model="localName" type="text" placeholder="contoh: Incident Notifier" />
                </label>
                <label>
                    <span>Deskripsi</span>
                    <textarea v-model="localDescription" rows="2" placeholder="Penjelasan singkat fungsi workflow"></textarea>
                </label>
                <label>
                    <span>Global timeout (ms)</span>
                    <input v-model.number="globalTimeoutMs" type="number" min="1000" max="600000" />
                </label>
            </div>

            <div class="builder-form__steps">
                <div class="builder-form__steps-head">
                    <h3>Steps ({{ steps.length }})</h3>
                    <button type="button" class="ghost-button compact" @click="addStep">+ Add step</button>
                </div>

                <p v-if="steps.length === 0" class="empty-state compact">
                    Belum ada step. Tekan "Add step" untuk mulai membangun DAG workflow.
                </p>

                <div v-for="(step, index) in steps" :key="`${step.id}-${index}`" class="step-row">
                    <div class="step-row__head">
                        <span class="step-row__index">{{ index + 1 }}</span>
                        <input v-model="step.id" type="text" placeholder="id" class="step-row__id" />
                        <input v-model="step.name" type="text" placeholder="Step name" class="step-row__name" />
                        <select :value="step.type" @change="(event) => setStepType(step, (event.target as HTMLSelectElement).value as StepType)">
                            <option v-for="type in stepTypes" :key="type" :value="type">{{ type }}</option>
                        </select>
                        <div class="step-row__order">
                            <button type="button" :disabled="index === 0" @click="moveStep(index, -1)">↑</button>
                            <button type="button" :disabled="index === steps.length - 1" @click="moveStep(index, 1)">↓</button>
                        </div>
                        <button type="button" class="step-row__remove" @click="removeStep(index)" title="Hapus step">✕</button>
                    </div>

                    <div class="step-row__body">
                        <div class="step-row__deps">
                            <p class="eyebrow">Depends on</p>
                            <div v-if="steps.length <= 1" class="muted small">Tambahkan step lain dulu untuk mengatur dependency.</div>
                            <ul v-else class="step-row__dep-list">
                                <li v-for="other in steps.filter((candidate) => candidate.id !== step.id)" :key="other.id">
                                    <label>
                                        <input
                                            type="checkbox"
                                            :checked="step.dependsOn.includes(other.id)"
                                            @change="toggleDependency(step, other.id)"
                                        />
                                        <span>{{ other.id }} <small class="muted">({{ other.name }})</small></span>
                                    </label>
                                </li>
                            </ul>
                        </div>

                        <div v-if="step.type === 'SCRIPT'" class="step-row__config">
                            <label>
                                <span>Operation</span>
                                <select :value="configString(step, 'operation', 'noop')" @change="(event) => setConfigString(step, 'operation', event)">
                                    <option value="noop">noop (tidak melakukan apa-apa)</option>
                                    <option value="set_output">set_output</option>
                                    <option value="transform">transform</option>
                                    <option value="fail_demo">fail_demo (simulasi gagal)</option>
                                </select>
                            </label>
                        </div>

                        <div v-else-if="step.type === 'HTTP'" class="step-row__config">
                            <label>
                                <span>Method</span>
                                <select :value="configString(step, 'method', 'GET')" @change="(event) => setConfigString(step, 'method', event)">
                                    <option>GET</option>
                                    <option>POST</option>
                                    <option>PUT</option>
                                    <option>DELETE</option>
                                </select>
                            </label>
                            <label>
                                <span>URL (opsional, kosongkan untuk demo noop)</span>
                                <input
                                    type="url"
                                    :value="configString(step, 'url')"
                                    placeholder="https://api.example.com/endpoint"
                                    @input="(event) => setConfigString(step, 'url', event)"
                                />
                            </label>
                        </div>

                        <div v-else-if="step.type === 'DELAY'" class="step-row__config">
                            <label>
                                <span>Delay (ms)</span>
                                <input
                                    type="number"
                                    min="0"
                                    :value="configNumber(step, 'ms', 1000)"
                                    @input="(event) => setConfigNumber(step, 'ms', event)"
                                />
                            </label>
                        </div>

                        <div v-else-if="step.type === 'CONDITION'" class="step-row__config">
                            <label>
                                <span>Expression</span>
                                <input
                                    type="text"
                                    :value="configString(step, 'expression', 'true')"
                                    placeholder="true"
                                    @input="(event) => setConfigString(step, 'expression', event)"
                                />
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="builder-form__preview">
                <div class="builder-form__preview-head">
                    <p class="eyebrow">DAG preview</p>
                    <small class="muted">Posisi otomatis berdasarkan dependency.</small>
                </div>
                <div class="builder-form__canvas">
                    <VueFlow
                        v-if="steps.length"
                        class="builder-canvas"
                        :nodes="previewNodes"
                        :edges="previewEdges"
                        fit-view-on-init
                    />
                    <p v-else class="empty-state compact">Belum ada step untuk dipreview.</p>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.builder-form {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    padding: 1rem 1.5rem;
    background: rgba(15, 23, 42, 0.92);
    color: var(--text-primary, #f8fafc);
    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, 0.08);
}

.builder-form__head {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.builder-form__head h2 {
    margin: 0.25rem 0;
}

.builder-form__head .muted {
    color: rgba(255, 255, 255, 0.55);
    font-size: 0.85rem;
}

.builder-form__errors {
    list-style: disc inside;
    margin: 0;
    padding: 0.5rem 0.75rem;
    background: rgba(220, 38, 38, 0.18);
    border-radius: 12px;
    color: #fecaca;
    font-size: 0.8rem;
}

.builder-form__body {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.25rem;
}

.builder-form__fields {
    grid-column: 1 / span 2;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 0.75rem;
}

.builder-form__fields label {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    font-size: 0.85rem;
}

.builder-form__fields input,
.builder-form__fields textarea,
select,
input[type='text'],
input[type='url'],
input[type='number'],
textarea {
    background: rgba(15, 23, 42, 0.6);
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: inherit;
    padding: 0.4rem 0.6rem;
    border-radius: 8px;
    font-size: 0.85rem;
}

.builder-form__steps {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.builder-form__steps-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.step-row {
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    padding: 0.6rem 0.75rem;
    background: rgba(15, 23, 42, 0.45);
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
}

.step-row__head {
    display: grid;
    grid-template-columns: auto 6rem 1fr 7rem auto auto;
    gap: 0.5rem;
    align-items: center;
}

.step-row__index {
    font-weight: 700;
    color: rgba(255, 255, 255, 0.65);
}

.step-row__order button,
.step-row__remove {
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: inherit;
    padding: 0.2rem 0.45rem;
    border-radius: 6px;
    cursor: pointer;
}

.step-row__order button:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.step-row__remove {
    background: rgba(220, 38, 38, 0.32);
}

.step-row__body {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}

.step-row__dep-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 0.25rem;
    max-height: 110px;
    overflow-y: auto;
}

.step-row__dep-list label {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.75rem;
}

.step-row__config {
    display: grid;
    gap: 0.4rem;
}

.builder-form__preview {
    grid-column: 1 / span 2;
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.builder-form__preview-head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
}

.builder-form__canvas {
    height: 220px;
    border-radius: 12px;
    border: 1px dashed rgba(255, 255, 255, 0.15);
    background: rgba(15, 23, 42, 0.55);
    overflow: hidden;
}

.empty-state.compact {
    padding: 0.75rem;
    color: rgba(255, 255, 255, 0.55);
    font-size: 0.85rem;
}

.muted {
    color: rgba(255, 255, 255, 0.55);
}

.muted.small {
    font-size: 0.75rem;
}
</style>
