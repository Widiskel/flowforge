<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import Button from '@/components/ui/Button.vue'
import Icon from '@/components/ui/Icon.vue'
import Alert from '@/components/ui/Alert.vue'

export interface WorkflowMetaSubmit {
    name: string
    description: string
    globalTimeoutMs: number
    defaultMaxAttempts: number
    initialStatus: 'draft' | 'active' | 'archived'
}

const props = withDefaults(
    defineProps<{
        open: boolean
        submitting?: boolean
    }>(),
    { submitting: false },
)

const emit = defineEmits<{
    (e: 'close'): void
    (e: 'submit', payload: WorkflowMetaSubmit): void
}>()

const step = ref<1 | 2 | 3>(1)
const name = ref('')
const description = ref('')
const globalTimeoutMs = ref(60000)
const defaultMaxAttempts = ref(3)
const initialStatus = ref<'draft' | 'active' | 'archived'>('draft')
const error = ref<string | null>(null)

watch(
    () => props.open,
    (open) => {
        if (open) {
            step.value = 1
            name.value = ''
            description.value = ''
            globalTimeoutMs.value = 60000
            defaultMaxAttempts.value = 3
            initialStatus.value = 'draft'
            error.value = null
        }
    },
    { immediate: true },
)

const timeoutSeconds = computed(() => Math.round(globalTimeoutMs.value / 1000))

function setTimeoutSeconds(value: number) {
    globalTimeoutMs.value = Math.max(1, Math.min(600, value)) * 1000
}

function next() {
    if (step.value === 1 && !name.value.trim()) {
        error.value = 'Workflow name is required.'
        return
    }
    if (step.value === 1) {
        error.value = null
        step.value = 2
        return
    }
    if (step.value === 2) {
        if (globalTimeoutMs.value < 1000 || globalTimeoutMs.value > 600000) {
            error.value = 'Global timeout must be between 1s and 600s.'
            return
        }
        if (defaultMaxAttempts.value < 1 || defaultMaxAttempts.value > 5) {
            error.value = 'Max attempts must be between 1 and 5.'
            return
        }
        error.value = null
        step.value = 3
    }
}

function back() {
    error.value = null
    if (step.value === 3) step.value = 2
    else if (step.value === 2) step.value = 1
}

function submit() {
    if (!name.value.trim()) {
        error.value = 'Workflow name is required.'
        step.value = 1
        return
    }
    if (globalTimeoutMs.value < 1000 || globalTimeoutMs.value > 600000) {
        error.value = 'Global timeout must be between 1s and 600s.'
        step.value = 2
        return
    }
    if (defaultMaxAttempts.value < 1 || defaultMaxAttempts.value > 5) {
        error.value = 'Max attempts must be between 1 and 5.'
        step.value = 2
        return
    }
    emit('submit', {
        name: name.value.trim(),
        description: description.value.trim(),
        globalTimeoutMs: globalTimeoutMs.value,
        defaultMaxAttempts: defaultMaxAttempts.value,
        initialStatus: initialStatus.value,
    })
}
</script>

<template>
    <Modal
        :open="open"
        title="New workflow"
        subtitle="Define the basics first, then build the DAG."
        width="lg"
        @close="emit('close')"
    >
        <template #header>
            <div class="flex items-center gap-1.5">
                <span
                    :class="[
                        'inline-flex items-center justify-center w-7 h-7 rounded-full text-label-caps font-label-caps font-bold border transition-colors',
                        step === 1 ? 'bg-secondary/10 text-secondary border-secondary/40' : 'bg-success/10 text-success border-success/40',
                    ]"
                >{{ step === 1 ? '1' : '✓' }}</span>
                <span class="w-6 h-px bg-outline-variant/40" />
                <span
                    :class="[
                        'inline-flex items-center justify-center w-7 h-7 rounded-full text-label-caps font-label-caps font-bold border transition-colors',
                        step === 2 ? 'bg-secondary/10 text-secondary border-secondary/40' : step > 2 ? 'bg-success/10 text-success border-success/40' : 'bg-surface-variant/40 text-on-surface-variant border-outline-variant/40',
                    ]"
                >{{ step > 2 ? '✓' : '2' }}</span>
                <span class="w-6 h-px bg-outline-variant/40" />
                <span
                    :class="[
                        'inline-flex items-center justify-center w-7 h-7 rounded-full text-label-caps font-label-caps font-bold border transition-colors',
                        step === 3 ? 'bg-secondary/10 text-secondary border-secondary/40' : 'bg-surface-variant/40 text-on-surface-variant border-outline-variant/40',
                    ]"
                >3</span>
            </div>
        </template>

        <div class="p-lg flex flex-col gap-md">
            <Alert v-if="error" tone="error" compact>{{ error }}</Alert>

            <Transition name="fade-step" mode="out-in">
                <section v-if="step === 1" key="step1" class="flex flex-col gap-md">
                    <div>
                        <p class="text-label-caps font-label-caps text-secondary uppercase tracking-wider m-0 mb-xs">Step 1 of 3</p>
                        <h3 class="text-headline-sm font-headline-sm text-on-surface m-0">Identity</h3>
                        <p class="text-body-sm text-on-surface-variant m-0 mt-1">How should this workflow appear in the catalog?</p>
                    </div>

                    <label class="flex flex-col gap-1">
                        <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Workflow name</span>
                        <input
                            v-model="name"
                            class="input-dark rounded-DEFAULT px-md py-sm text-body-md w-full"
                            placeholder="e.g. User onboarding flow"
                            autofocus
                            @keydown.enter="next"
                        >
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Description (optional)</span>
                        <textarea
                            v-model="description"
                            rows="3"
                            class="input-dark rounded-DEFAULT px-md py-sm text-body-md w-full resize-y"
                            placeholder="Briefly describe what this workflow accomplishes."
                        />
                    </label>
                </section>

                <section v-else-if="step === 2" key="step2" class="flex flex-col gap-md">
                    <div>
                        <p class="text-label-caps font-label-caps text-secondary uppercase tracking-wider m-0 mb-xs">Step 2 of 3</p>
                        <h3 class="text-headline-sm font-headline-sm text-on-surface m-0">Execution defaults</h3>
                        <p class="text-body-sm text-on-surface-variant m-0 mt-1">Set the global timeout and default retry policy. You can tweak step-level overrides later.</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-md">
                        <label class="flex flex-col gap-1">
                            <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Global timeout</span>
                            <div class="flex items-center gap-sm">
                                <input
                                    type="number"
                                    min="1"
                                    max="600"
                                    :value="timeoutSeconds"
                                    class="input-dark rounded-DEFAULT px-md py-sm text-body-md w-32 tabular-nums"
                                    @input="(e) => setTimeoutSeconds(Number((e.target as HTMLInputElement).value))"
                                >
                                <span class="text-body-sm text-on-surface-variant">seconds (max 600)</span>
                            </div>
                        </label>

                        <label class="flex flex-col gap-1">
                            <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Default max attempts</span>
                            <input
                                v-model.number="defaultMaxAttempts"
                                type="number"
                                min="1"
                                max="5"
                                class="input-dark rounded-DEFAULT px-md py-sm text-body-md w-32 tabular-nums"
                            >
                        </label>
                    </div>

                    <div class="rounded-DEFAULT bg-surface-container-low border border-outline-variant/40 p-md flex items-start gap-sm">
                        <Icon name="info" :size="18" class="text-secondary shrink-0 mt-0.5" />
                        <p class="text-body-sm text-on-surface-variant m-0">
                            Steps inherit the default retry policy unless overridden in the inspector. Allowed step types: HTTP, Script (allowlisted ops), Delay (capped at 30s for safety), Condition.
                        </p>
                    </div>
                </section>

                <section v-else key="step3" class="flex flex-col gap-md">
                    <div>
                        <p class="text-label-caps font-label-caps text-secondary uppercase tracking-wider m-0 mb-xs">Step 3 of 3</p>
                        <h3 class="text-headline-sm font-headline-sm text-on-surface m-0">Initial status</h3>
                        <p class="text-body-sm text-on-surface-variant m-0 mt-1">Choose how this workflow lands after save. Draft is recommended until the DAG is verified.</p>
                    </div>

                    <div class="flex flex-col gap-sm">
                        <button
                            v-for="opt in [
                                { value: 'draft', label: 'Draft', description: 'Saved but not triggerable. Edit freely before activating.', icon: 'edit_note', tone: 'warning' },
                                { value: 'active', label: 'Active', description: 'Live immediately. Manual triggers and webhooks are accepted.', icon: 'bolt', tone: 'success' },
                                { value: 'archived', label: 'Archived', description: 'Hidden from default lists. Useful for snapshots/templates.', icon: 'inventory_2', tone: 'pending' },
                            ]"
                            :key="opt.value"
                            type="button"
                            :class="[
                                'flex items-start gap-sm p-md rounded-DEFAULT border text-left transition-all',
                                initialStatus === opt.value
                                    ? 'border-secondary/60 bg-secondary/[0.06]'
                                    : 'border-outline-variant/40 bg-surface-container-low hover:border-secondary/30 hover:bg-secondary/[0.03]',
                            ]"
                            @click="initialStatus = opt.value as typeof initialStatus"
                        >
                            <span
                                :class="[
                                    'inline-flex items-center justify-center w-9 h-9 rounded-DEFAULT shrink-0',
                                    opt.tone === 'success' ? 'bg-success/12 text-success' :
                                    opt.tone === 'warning' ? 'bg-warning/12 text-warning' :
                                    'bg-surface-variant text-on-surface-variant',
                                ]"
                            >
                                <Icon :name="opt.icon" :size="18" />
                            </span>
                            <span class="flex flex-col gap-0.5 min-w-0 flex-1">
                                <span class="text-body-md font-bold text-on-surface">{{ opt.label }}</span>
                                <span class="text-body-sm text-on-surface-variant">{{ opt.description }}</span>
                            </span>
                            <span
                                :class="[
                                    'mt-1 w-4 h-4 rounded-full border-2 shrink-0',
                                    initialStatus === opt.value ? 'border-secondary bg-secondary' : 'border-outline-variant',
                                ]"
                            />
                        </button>
                    </div>
                </section>
            </Transition>
        </div>

        <template #footer>
            <Button variant="ghost" :disabled="submitting" @click="emit('close')">Cancel</Button>
            <span class="flex-1" />
            <Button v-if="step > 1" variant="secondary" :disabled="submitting" @click="back">Back</Button>
            <Button
                v-if="step < 3"
                glow
                trailing-icon="arrow_forward"
                @click="next"
            >Next</Button>
            <Button
                v-else
                glow
                leading-icon="rocket_launch"
                :disabled="submitting"
                @click="submit"
            >{{ submitting ? 'Creating…' : 'Open builder' }}</Button>
        </template>
    </Modal>
</template>

<style scoped>
.fade-step-enter-active,
.fade-step-leave-active {
    transition: opacity 0.15s ease, transform 0.15s ease;
}
.fade-step-enter-from,
.fade-step-leave-to {
    opacity: 0;
    transform: translateY(4px);
}
</style>
