<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { BuilderStep } from './_shared'

type Unit = 'ms' | 's'

const props = defineProps<{ step: BuilderStep }>()

const unit = ref<Unit>('s')
const amount = ref(1)

function pull() {
    const ms = Number(props.step.config.durationMs ?? 1000)
    if (ms < 1000 || ms % 1000 !== 0) {
        unit.value = 'ms'
        amount.value = ms
    } else {
        unit.value = 's'
        amount.value = ms / 1000
    }
}

watch(() => props.step.id, pull, { immediate: true })

const displayMs = computed(() => (unit.value === 's' ? amount.value * 1000 : amount.value))

watch([amount, unit], () => {
    let ms = displayMs.value
    if (!Number.isFinite(ms) || ms <= 0) ms = 1
    if (ms > 30000) ms = 30000
    props.step.config.durationMs = ms
})
</script>

<template>
    <div class="flex flex-col gap-md">
        <label class="flex flex-col gap-1">
            <span class="flex items-center justify-between">
                <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Duration</span>
                <span class="text-body-sm text-on-surface-variant">capped at 30 seconds</span>
            </span>
            <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-sm">
                <input
                    v-model.number="amount"
                    type="number"
                    :min="unit === 's' ? 1 : 100"
                    :max="unit === 's' ? 30 : 30000"
                    :step="unit === 's' ? 1 : 100"
                    class="input-dark rounded-DEFAULT px-sm py-1.5 text-body-md tabular-nums"
                >
                <div class="inline-flex items-center rounded-DEFAULT bg-surface-container-low border border-outline-variant/40 p-0.5 text-label-caps font-label-caps">
                    <button
                        type="button"
                        :class="['px-sm py-1 rounded-[3px] transition-colors', unit === 'ms' ? 'bg-secondary/15 text-secondary' : 'text-on-surface-variant']"
                        @click="unit = 'ms'"
                    >ms</button>
                    <button
                        type="button"
                        :class="['px-sm py-1 rounded-[3px] transition-colors', unit === 's' ? 'bg-secondary/15 text-secondary' : 'text-on-surface-variant']"
                        @click="unit = 's'"
                    >s</button>
                </div>
            </div>
            <p class="text-body-sm text-on-surface-variant m-0">Effective wait: <span class="font-code-md text-on-surface">{{ displayMs }} ms</span></p>
        </label>
    </div>
</template>
