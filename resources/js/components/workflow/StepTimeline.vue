<script setup lang="ts">
import { computed } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import { formatDuration } from '@/utils/format'
import type { StepRun } from '@/types/api'

const props = defineProps<{
    steps: StepRun[]
}>()

const visibleSteps = computed(() => props.steps ?? [])

function statusIcon(status: string): { name: string; tone: string; filled: boolean } {
    switch (status) {
        case 'SUCCESS':
            return { name: 'check_circle', tone: 'text-success', filled: true }
        case 'RUNNING':
            return { name: 'progress_activity', tone: 'text-running', filled: false }
        case 'FAILED':
        case 'TIMEOUT':
            return { name: 'cancel', tone: 'text-failed', filled: true }
        case 'RETRYING':
            return { name: 'sync', tone: 'text-warning', filled: false }
        case 'SKIPPED':
            return { name: 'remove_circle', tone: 'text-on-surface-variant', filled: false }
        default:
            return { name: 'radio_button_unchecked', tone: 'text-on-surface-variant', filled: false }
    }
}
</script>

<template>
    <div class="flex flex-col gap-sm">
        <div
            v-for="(step, index) in visibleSteps"
            :key="step.id"
            class="flex items-start gap-md p-sm rounded-DEFAULT bg-surface-container-low border border-outline-variant/30"
        >
            <div class="flex flex-col items-center">
                <div
                    :class="['w-8 h-8 rounded-full flex items-center justify-center', statusIcon(step.status).tone]"
                >
                    <Icon
                        :name="statusIcon(step.status).name"
                        :size="20"
                        :filled="statusIcon(step.status).filled"
                        :class="step.status === 'RUNNING' ? 'animate-spin' : ''"
                    />
                </div>
                <div
                    v-if="index < visibleSteps.length - 1"
                    class="w-px h-4 bg-outline-variant/40 mt-1"
                />
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-baseline gap-sm flex-wrap">
                    <span class="text-body-md font-bold text-on-surface">{{ step.stepId }}</span>
                    <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">{{ step.stepType }}</span>
                </div>
                <div class="flex items-center gap-md text-body-sm font-body-sm text-on-surface-variant mt-1">
                    <span>{{ formatDuration(step.durationMs ?? null) }}</span>
                    <span v-if="step.attemptCount > 1">attempt {{ step.attemptCount }}/{{ step.maxAttempts }}</span>
                    <span v-if="step.errorMessage" class="text-failed truncate">{{ step.errorMessage }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
