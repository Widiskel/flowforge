<script setup lang="ts">
import { computed, ref } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import Button from '@/components/ui/Button.vue'
import Icon from '@/components/ui/Icon.vue'
import type { WorkflowTriggerType } from '@/types/api'

const props = defineProps<{
    open: boolean
    title?: string
    subtitle?: string
}>()

const emit = defineEmits<{
    (e: 'close'): void
    (e: 'select', type: WorkflowTriggerType): void
}>()

const search = ref('')

const options: { type: WorkflowTriggerType; label: string; description: string; icon: string }[] = [
    {
        type: 'manual',
        label: 'Trigger manually',
        description: 'Run the workflow on demand by clicking a button or hitting the trigger endpoint. Good for getting started quickly.',
        icon: 'play_circle',
    },
    {
        type: 'scheduled',
        label: 'On a schedule',
        description: 'Run the workflow on a cron schedule (e.g. every hour, every weekday at 06:00). Configurable timezone.',
        icon: 'schedule',
    },
    {
        type: 'webhook',
        label: 'On webhook call',
        description: 'Run the workflow when an external system POSTs to the workflow webhook URL. HMAC signature is required.',
        icon: 'webhook',
    },
]

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase()
    if (!q) return options
    return options.filter(
        (o) => o.label.toLowerCase().includes(q) || o.description.toLowerCase().includes(q),
    )
})
</script>

<template>
    <Modal
        :open="open"
        :title="title ?? 'What triggers this workflow?'"
        :subtitle="subtitle ?? 'A trigger is the entry-point that starts your workflow.'"
        width="lg"
        @close="emit('close')"
    >
        <div class="p-md flex flex-col gap-md">
            <label class="relative">
                <Icon name="search" :size="18" class="absolute left-md top-1/2 -translate-y-1/2 text-on-surface-variant pointer-events-none" />
                <input
                    v-model="search"
                    type="search"
                    placeholder="Search trigger types…"
                    class="input-dark w-full rounded-DEFAULT pl-12 pr-md h-11 text-body-md"
                >
            </label>

            <div class="flex flex-col gap-1">
                <button
                    v-for="opt in filtered"
                    :key="opt.type"
                    type="button"
                    class="trigger-option"
                    @click="emit('select', opt.type)"
                >
                    <span class="trigger-option__icon">
                        <Icon :name="opt.icon" :size="22" />
                    </span>
                    <span class="trigger-option__body">
                        <span class="trigger-option__label">{{ opt.label }}</span>
                        <span class="trigger-option__copy">{{ opt.description }}</span>
                    </span>
                    <Icon name="arrow_forward" :size="18" class="text-on-surface-variant shrink-0" />
                </button>
                <div v-if="filtered.length === 0" class="px-md py-sm text-body-sm text-on-surface-variant">
                    No trigger types match.
                </div>
            </div>
        </div>

        <template #footer>
            <Button variant="ghost" @click="emit('close')">Cancel</Button>
        </template>
    </Modal>
</template>

<style scoped>
.trigger-option {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    border-radius: var(--radius-DEFAULT);
    background: var(--color-surface-container-low);
    border: 1px solid color-mix(in srgb, var(--color-outline-variant) 40%, transparent);
    text-align: left;
    cursor: pointer;
    transition: border-color 0.15s ease, background 0.15s ease, transform 0.15s ease;
}

.trigger-option:hover {
    border-color: color-mix(in srgb, var(--color-secondary) 50%, transparent);
    background: color-mix(in srgb, var(--color-secondary) 4%, var(--color-surface-container-low));
    transform: translateY(-1px);
}

.trigger-option__icon {
    width: 40px;
    height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--radius-DEFAULT);
    background: color-mix(in srgb, var(--color-secondary) 14%, transparent);
    color: var(--color-secondary);
    border: 1px solid color-mix(in srgb, var(--color-secondary) 40%, transparent);
    flex-shrink: 0;
}

.trigger-option__body {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.trigger-option__label {
    font-size: 14px;
    font-weight: 700;
    color: var(--color-on-surface);
}

.trigger-option__copy {
    font-size: 12px;
    line-height: 1.45;
    color: var(--color-on-surface-variant);
}
</style>
