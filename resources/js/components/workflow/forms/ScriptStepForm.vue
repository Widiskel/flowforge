<script setup lang="ts">
import { computed } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import type { BuilderStep } from './_shared'

const props = defineProps<{ step: BuilderStep }>()

const operations = [
    { value: 'noop', label: 'noop', description: 'No-op success step.' },
    { value: 'set_output', label: 'set_output', description: 'Persist a static value as the step output.' },
    { value: 'transform', label: 'transform', description: 'Mark a deterministic transform.' },
    { value: 'fail_demo', label: 'fail_demo', description: 'Force failure (useful for AI demo).' },
] as const

const operation = computed({
    get: () => String(props.step.config.operation ?? 'noop'),
    set: (value: string) => {
        props.step.config.operation = value
    },
})

const outputJson = computed({
    get: () => {
        const raw = props.step.config.output
        if (raw === undefined || raw === null) return ''
        if (typeof raw === 'string') return raw
        try {
            return JSON.stringify(raw, null, 2)
        } catch {
            return ''
        }
    },
    set: (value: string) => {
        const trimmed = value.trim()
        if (!trimmed) {
            delete props.step.config.output
            return
        }
        try {
            props.step.config.output = JSON.parse(trimmed)
        } catch {
            props.step.config.output = value
        }
    },
})

const showOutput = computed(() => operation.value === 'set_output')
const isDemoFail = computed(() => operation.value === 'fail_demo')
</script>

<template>
    <div class="flex flex-col gap-md">
        <div class="flex flex-col gap-1">
            <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Operation</span>
            <div class="grid grid-cols-1 gap-1">
                <button
                    v-for="op in operations"
                    :key="op.value"
                    type="button"
                    :class="[
                        'flex items-start gap-sm p-sm rounded-DEFAULT border text-left transition-colors',
                        operation === op.value
                            ? 'border-secondary/60 bg-secondary/[0.06]'
                            : 'border-outline-variant/40 bg-surface-container-low hover:border-secondary/30 hover:bg-secondary/[0.03]',
                    ]"
                    @click="operation = op.value"
                >
                    <span :class="['mt-0.5 w-3 h-3 rounded-full border-2 shrink-0', operation === op.value ? 'border-secondary bg-secondary' : 'border-outline-variant']" />
                    <span class="flex flex-col gap-0.5 min-w-0">
                        <span class="text-code-md font-code-md font-bold text-on-surface">{{ op.label }}</span>
                        <span class="text-body-sm text-on-surface-variant">{{ op.description }}</span>
                    </span>
                </button>
            </div>
        </div>

        <label v-if="showOutput" class="flex flex-col gap-1">
            <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Output value</span>
            <textarea
                v-model="outputJson"
                rows="4"
                class="input-dark rounded-DEFAULT px-sm py-1.5 text-code-sm font-code-md resize-y"
                placeholder='{ "ok": true } or plain text'
            />
        </label>

        <div v-if="isDemoFail" class="rounded-DEFAULT bg-failed/8 border border-failed/30 p-sm flex items-start gap-sm">
            <Icon name="report" :size="18" class="text-failed shrink-0 mt-0.5" />
            <p class="text-body-sm text-on-surface m-0">
                <span class="text-failed font-bold">fail_demo</span> always fails — useful to exercise the AI failure analysis flow.
            </p>
        </div>

        <div class="rounded-DEFAULT bg-surface-container-low border border-outline-variant/40 p-sm flex items-start gap-sm">
            <Icon name="security" :size="18" class="text-secondary shrink-0 mt-0.5" />
            <p class="text-body-sm text-on-surface-variant m-0">
                Script steps are restricted to allowlisted operations. Arbitrary shell or PHP eval is not exposed.
            </p>
        </div>
    </div>
</template>
