<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Alert from '@/components/ui/Alert.vue'
import type { BuilderStep } from './_shared'

type Unit = 'milliseconds' | 'seconds' | 'minutes'

const UNIT_TO_MS: Record<Unit, number> = {
    milliseconds: 1,
    seconds: 1000,
    minutes: 60_000,
}

const MAX_MS = 30_000

const props = defineProps<{ step: BuilderStep }>()

const amount = ref<number>(5)
const unit = ref<Unit>('seconds')

function pull() {
    const ms = Number(props.step.config.durationMs ?? 5000)
    if (ms % 60_000 === 0 && ms >= 60_000) {
        unit.value = 'minutes'
        amount.value = ms / 60_000
    } else if (ms % 1000 === 0 && ms >= 1000) {
        unit.value = 'seconds'
        amount.value = ms / 1000
    } else {
        unit.value = 'milliseconds'
        amount.value = ms
    }
}

watch(() => props.step.id, pull, { immediate: true })

const computedMs = computed(() => Math.max(0, Math.floor(amount.value * UNIT_TO_MS[unit.value])))
const cappedMs = computed(() => Math.min(MAX_MS, computedMs.value))
const exceedsCap = computed(() => computedMs.value > MAX_MS)

watch([amount, unit], () => {
    props.step.config.durationMs = cappedMs.value
})
</script>

<template>
    <div class="flex flex-col gap-md">
        <Alert tone="info" title="Wait After Time Interval" compact>
            FlowForge waits a fixed amount of time before continuing. Capped at 30 seconds for safety. <span class="text-on-surface-variant">Other resume modes (At Specified Time, Webhook, Form) are not in MVP.</span>
        </Alert>

        <div class="grid grid-cols-2 gap-sm">
            <label class="flex flex-col gap-1">
                <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Wait Amount</span>
                <input
                    v-model.number="amount"
                    type="number"
                    min="0"
                    step="1"
                    class="input-dark rounded-DEFAULT px-sm py-1.5 text-body-md tabular-nums"
                >
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Wait Unit</span>
                <select
                    v-model="unit"
                    class="input-dark rounded-DEFAULT px-sm py-1.5 text-body-md"
                >
                    <option value="milliseconds">Milliseconds</option>
                    <option value="seconds">Seconds</option>
                    <option value="minutes">Minutes</option>
                </select>
            </label>
        </div>

        <Alert v-if="exceedsCap" tone="warning" compact>
            Requested {{ computedMs }} ms exceeds the 30 s safety cap. The step will run for {{ cappedMs }} ms.
        </Alert>

        <p class="text-body-sm text-on-surface-variant m-0">Effective wait: <span class="font-code-md text-on-surface">{{ cappedMs }} ms</span></p>
    </div>
</template>
