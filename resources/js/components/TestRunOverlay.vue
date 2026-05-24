<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Tabs from '@/components/ui/Tabs.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import StatusBadge from '@/components/workflow/StatusBadge.vue'
import StepTimeline from '@/components/workflow/StepTimeline.vue'
import LogTerminal from '@/components/workflow/LogTerminal.vue'
import { formatDuration, formatTime } from '@/utils/format'
import type { WorkflowRun } from '@/types/api'

const props = defineProps<{
    run: WorkflowRun
    isOpen: boolean
}>()

const emit = defineEmits<{ (e: 'close'): void }>()

const headerSubtitle = computed(() =>
    `Workflow ${props.run.workflowId.slice(0, 8)} · started ${formatTime(props.run.startedAt ?? null)}`,
)

const isTerminal = computed(() => {
    const terminal = ['SUCCESS', 'FAILED', 'TIMEOUT', 'CANCELLED', 'SKIPPED']
    return terminal.includes(props.run.status)
})

const stepRuns = computed(() => props.run.stepRuns ?? [])
const logs = computed(() => props.run.logs ?? [])
const stepsWithOutput = computed(() => stepRuns.value.filter((s) => s.output !== null && s.output !== undefined))

const stepsLoading = computed(() => !isTerminal.value && stepRuns.value.length === 0)
const logsLoading = computed(() => !isTerminal.value && logs.value.length === 0)

type TabKey = 'path' | 'logs' | 'output'
const activeTab = ref<TabKey>('path')

watch(() => props.run.id, () => {
    activeTab.value = 'path'
})

const tabs = computed(() => [
    { value: 'path' as TabKey, label: 'Execution Path', badge: stepRuns.value.length ? String(stepRuns.value.length) : undefined },
    { value: 'logs' as TabKey, label: 'Live Logs', badge: logs.value.length ? String(logs.value.length) : undefined },
    { value: 'output' as TabKey, label: 'Step Output', badge: stepsWithOutput.value.length ? String(stepsWithOutput.value.length) : undefined },
])
</script>

<template>
    <Modal
        :open="isOpen"
        :title="`Run #${run.id.slice(0, 8)}`"
        :subtitle="headerSubtitle"
        width="4xl"
        @close="emit('close')"
    >
        <template #header>
            <div class="flex items-center gap-sm">
                <StatusBadge :status="run.status" dot />
                <Badge tone="info">{{ formatDuration(run.durationMs ?? null) }}</Badge>
            </div>
        </template>

        <div class="flex flex-col gap-md p-md">
            <Tabs v-model="activeTab" :items="tabs" />

            <section v-if="activeTab === 'path'" class="flex flex-col gap-sm">
                <div
                    v-if="stepsLoading"
                    class="rounded-DEFAULT border border-outline-variant/40 bg-surface-container-low/40 p-md flex items-center gap-sm text-body-sm text-on-surface-variant"
                >
                    <span class="step-loader" aria-hidden="true" />
                    Waiting for the queue worker to pick this run up…
                </div>
                <StepTimeline v-else :steps="stepRuns" />
            </section>

            <section v-else-if="activeTab === 'logs'" class="flex flex-col gap-sm">
                <LogTerminal :logs="logs" :loading="logsLoading" />
            </section>

            <section v-else-if="activeTab === 'output'" class="flex flex-col gap-md">
                <EmptyState
                    v-if="stepsWithOutput.length === 0 && !stepsLoading"
                    icon="data_object"
                    title="No step output yet"
                    description="Step output panels appear here once the queue worker writes them."
                    compact
                />
                <div
                    v-for="step in stepsWithOutput"
                    :key="step.id"
                    class="rounded-DEFAULT border border-outline-variant/40 bg-surface-container-low/50 overflow-hidden"
                >
                    <header class="flex items-center justify-between gap-sm px-sm py-1.5 border-b border-outline-variant/30 bg-surface-container-low">
                        <div class="flex items-center gap-sm min-w-0">
                            <code class="text-code-sm font-code-sm font-bold text-secondary">{{ step.stepId }}</code>
                            <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">{{ step.stepType }}</span>
                        </div>
                        <div class="flex items-center gap-sm text-body-sm text-on-surface-variant">
                            <StatusBadge :status="step.status" />
                            <span>{{ formatDuration(step.durationMs ?? null) }}</span>
                        </div>
                    </header>
                    <pre class="m-0 p-sm text-code-sm font-code-sm text-on-surface overflow-auto max-h-[260px]">{{ JSON.stringify(step.output, null, 2) }}</pre>
                </div>
            </section>
        </div>

        <template #footer>
            <Button variant="secondary" @click="emit('close')">Close</Button>
        </template>
    </Modal>
</template>

<style scoped>
.step-loader {
    display: inline-block;
    width: 14px;
    height: 14px;
    border-radius: 9999px;
    border: 2px solid color-mix(in srgb, var(--color-outline-variant) 60%, transparent);
    border-top-color: var(--color-secondary);
    animation: step-loader-spin 0.8s linear infinite;
}

@keyframes step-loader-spin {
    to { transform: rotate(360deg); }
}
</style>
