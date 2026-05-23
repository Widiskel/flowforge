<script setup lang="ts">
import { computed } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import type { BuilderStep } from './_shared'

const props = defineProps<{ step: BuilderStep; availableSteps: BuilderStep[] }>()

const operators = [
    { value: 'equals', label: 'equals' },
    { value: 'not_equals', label: 'not equals' },
    { value: 'contains', label: 'contains' },
    { value: 'greater_than', label: 'greater than' },
    { value: 'less_than', label: 'less than' },
] as const

const field = computed({
    get: () => String(props.step.config.field ?? ''),
    set: (value: string) => {
        props.step.config.field = value
    },
})

const operator = computed({
    get: () => String(props.step.config.operator ?? 'equals'),
    set: (value: string) => {
        props.step.config.operator = value
    },
})

const value = computed({
    get: () => {
        const raw = props.step.config.value
        if (raw === null || raw === undefined) return ''
        return typeof raw === 'object' ? JSON.stringify(raw) : String(raw)
    },
    set: (incoming: string) => {
        props.step.config.value = incoming
    },
})

const upstreamHints = computed(() => props.availableSteps.filter((s) => s.type === 'HTTP' || s.type === 'SCRIPT'))
</script>

<template>
    <div class="flex flex-col gap-md">
        <label class="flex flex-col gap-1">
            <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Field path</span>
            <input
                v-model="field"
                class="input-dark rounded-DEFAULT px-sm py-1.5 text-code-md font-code-md"
                placeholder="steps.fetch_status.status"
            >
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Operator</span>
            <select
                v-model="operator"
                class="input-dark rounded-DEFAULT px-sm py-1.5 text-body-md"
            >
                <option v-for="op in operators" :key="op.value" :value="op.value">{{ op.label }}</option>
            </select>
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Compare value</span>
            <input
                v-model="value"
                class="input-dark rounded-DEFAULT px-sm py-1.5 text-body-md"
                placeholder="200"
            >
        </label>

        <div v-if="upstreamHints.length > 0" class="rounded-DEFAULT bg-surface-container-low border border-outline-variant/40 p-sm flex flex-col gap-1">
            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Available outputs</p>
            <div class="flex flex-wrap gap-1.5">
                <button
                    v-for="dep in upstreamHints"
                    :key="dep.id"
                    type="button"
                    class="px-sm py-1 rounded-full bg-secondary/10 text-secondary text-code-sm font-code-sm border border-secondary/30 hover:bg-secondary/20 transition-colors"
                    @click="field = `steps.${dep.id}.${dep.type === 'HTTP' ? 'status' : 'output'}`"
                >steps.{{ dep.id }}.{{ dep.type === 'HTTP' ? 'status' : 'output' }}</button>
            </div>
        </div>

        <div class="rounded-DEFAULT bg-surface-container-low border border-outline-variant/40 p-sm flex items-start gap-sm">
            <Icon name="info" :size="18" class="text-secondary shrink-0 mt-0.5" />
            <p class="text-body-sm text-on-surface-variant m-0">
                When the condition fails, dependent steps are <code class="font-code-sm">SKIPPED</code>. Use <code class="font-code-sm">steps.&lt;id&gt;.status</code> to branch on HTTP responses.
            </p>
        </div>
    </div>
</template>
