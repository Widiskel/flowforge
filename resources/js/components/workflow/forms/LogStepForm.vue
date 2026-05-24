<script setup lang="ts">
import { computed } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import Button from '@/components/ui/Button.vue'
import type { BuilderStep } from './_shared'

const props = defineProps<{ step: BuilderStep }>()

const LEVELS = [
    { value: 'debug', label: 'debug' },
    { value: 'info', label: 'info' },
    { value: 'notice', label: 'notice' },
    { value: 'warning', label: 'warning' },
    { value: 'error', label: 'error' },
    { value: 'critical', label: 'critical' },
] as const

const level = computed({
    get: () => String(props.step.config.level ?? 'info'),
    set: (value: string) => {
        props.step.config.level = value
    },
})

const message = computed({
    get: () => String(props.step.config.message ?? ''),
    set: (value: string) => {
        if (value === '') {
            delete props.step.config.message
            return
        }
        props.step.config.message = value
    },
})

// `context` is an optional key/value array shown below the message. Stored as
// an object on disk; rendered as rows in the form so order is stable.
type Row = { key: string; value: string }

const rows = computed<Row[]>({
    get: () => {
        const raw = props.step.config.context
        if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return []
        return Object.entries(raw as Record<string, unknown>).map(([key, value]) => ({
            key,
            value: typeof value === 'string' ? value : JSON.stringify(value ?? ''),
        }))
    },
    set: (next: Row[]) => {
        const out: Record<string, string> = {}
        for (const row of next) {
            if (row.key.trim() === '') continue
            out[row.key] = row.value
        }
        if (Object.keys(out).length === 0) {
            delete props.step.config.context
        } else {
            props.step.config.context = out
        }
    },
})

function addRow() {
    rows.value = [...rows.value, { key: '', value: '' }]
}

function updateRow(index: number, key: 'key' | 'value', value: string) {
    const next = [...rows.value]
    if (!next[index]) return
    next[index] = { ...next[index], [key]: value }
    rows.value = next
}

function removeRow(index: number) {
    const next = [...rows.value]
    next.splice(index, 1)
    rows.value = next
}

function insertExample() {
    if (message.value.trim() !== '') return
    message.value = 'Workflow finished step {{ fetch_resource.json.id }} with status {{ fetch_resource.status }}'
}

const openTag = '{{'
const closeTag = '}}'
const placeholderHint = 'Use {{ stepId.path.to.field }} to interpolate upstream values.'
const rowValuePlaceholder = 'value (or {{ stepId.path }})'
</script>

<template>
    <div class="flex flex-col gap-md">
        <div class="rounded-DEFAULT bg-secondary/[0.05] border border-secondary/30 p-sm flex items-start gap-sm">
            <Icon name="description" :size="18" class="text-secondary shrink-0 mt-0.5" />
            <div>
                <p class="text-body-sm font-bold text-on-surface m-0">Structured log line</p>
                <p class="text-body-sm text-on-surface-variant m-0">
                    Writes a record into the application log channel using the configured level.
                    Resolved message + context are also returned as the step output for downstream
                    chaining.
                </p>
            </div>
        </div>

        <label class="flex flex-col gap-1">
            <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Level</span>
            <select v-model="level" class="input-dark rounded-DEFAULT px-sm py-1.5 text-body-md w-full">
                <option v-for="opt in LEVELS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
        </label>

        <label class="flex flex-col gap-1">
            <div class="flex items-center justify-between gap-sm">
                <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Message</span>
                <Button size="sm" variant="ghost" leading-icon="auto_awesome" @click="insertExample">Insert example</Button>
            </div>
            <textarea
                v-model="message"
                rows="3"
                class="input-dark rounded-DEFAULT px-sm py-1.5 text-body-md w-full resize-y"
                :placeholder="placeholderHint"
            />
        </label>

        <div class="flex flex-col gap-sm">
            <div class="flex items-start justify-between gap-sm">
                <div class="flex flex-col gap-0.5">
                    <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Extra context</span>
                    <p class="text-body-sm text-on-surface-variant m-0">
                        Optional key/value pairs attached to the log line. String values support the same
                        <code class="font-code-sm">{{ openTag }} stepId.path {{ closeTag }}</code> placeholders.
                    </p>
                </div>
                <Button size="sm" variant="ghost" leading-icon="add" @click="addRow">Add row</Button>
            </div>

            <div v-if="rows.length === 0" class="rounded-DEFAULT border border-dashed border-outline-variant/50 p-sm text-body-sm text-on-surface-variant text-center">
                No context fields — leave empty or click <em>Add row</em>.
            </div>

            <div
                v-for="(row, index) in rows"
                :key="index"
                class="grid grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)_auto] gap-sm items-center"
            >
                <input
                    :value="row.key"
                    placeholder="key"
                    class="input-dark rounded-DEFAULT px-sm py-1.5 text-code-sm font-code-md"
                    @input="updateRow(index, 'key', ($event.target as HTMLInputElement).value)"
                >
                <input
                    :value="row.value"
                    :placeholder="rowValuePlaceholder"
                    class="input-dark rounded-DEFAULT px-sm py-1.5 text-code-sm font-code-md"
                    @input="updateRow(index, 'value', ($event.target as HTMLInputElement).value)"
                >
                <button
                    type="button"
                    class="text-on-surface-variant hover:text-failed p-1 rounded-DEFAULT"
                    aria-label="Remove row"
                    @click="removeRow(index)"
                >
                    <Icon name="delete" :size="16" />
                </button>
            </div>
        </div>
    </div>
</template>
