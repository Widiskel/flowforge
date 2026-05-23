<script setup lang="ts">
import { computed } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
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

        <div class="grid grid-cols-1 md:grid-cols-[260px_minmax(0,1fr)] divide-y md:divide-y-0 md:divide-x divide-outline-variant/30">
            <section class="p-md flex flex-col gap-sm">
                <p class="text-label-caps font-label-caps text-secondary uppercase tracking-wider m-0">Execution Path</p>
                <StepTimeline :steps="run.stepRuns ?? []" />
            </section>
            <section class="p-md flex flex-col gap-sm min-w-0">
                <p class="text-label-caps font-label-caps text-secondary uppercase tracking-wider m-0">Live Logs</p>
                <LogTerminal :logs="run.logs ?? []" />
            </section>
        </div>

        <template #footer>
            <Button variant="secondary" @click="emit('close')">Close</Button>
        </template>
    </Modal>
</template>
