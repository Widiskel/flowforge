<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Alert from '@/components/ui/Alert.vue'
import type { WorkflowTriggerType } from '@/types/api'

export interface TriggerDraft {
    type: WorkflowTriggerType
    cronExpression: string
    timezone: string
    webhookSecret: string
    enabled: boolean
}

const props = defineProps<{
    draft: TriggerDraft
    workflowId: string | null
    webhookUrl: string
    persistedSecretMasked: boolean
}>()

const emit = defineEmits<{
    (e: 'update', draft: TriggerDraft): void
    (e: 'remove'): void
    (e: 'change-type'): void
}>()

const cronExpression = computed({
    get: () => props.draft.cronExpression,
    set: (v: string) => emit('update', { ...props.draft, cronExpression: v }),
})

const timezone = computed({
    get: () => props.draft.timezone,
    set: (v: string) => emit('update', { ...props.draft, timezone: v }),
})

const webhookSecret = computed({
    get: () => props.draft.webhookSecret,
    set: (v: string) => emit('update', { ...props.draft, webhookSecret: v }),
})

const enabled = computed({
    get: () => props.draft.enabled,
    set: (v: boolean) => emit('update', { ...props.draft, enabled: v }),
})

const cronPresets = [
    { label: 'Every minute', value: '* * * * *' },
    { label: 'Every 5 minutes', value: '*/5 * * * *' },
    { label: 'Every hour', value: '0 * * * *' },
    { label: 'Daily at 06:00', value: '0 6 * * *' },
    { label: 'Weekdays 09:00', value: '0 9 * * 1-5' },
]

function applyPreset(value: string) {
    cronExpression.value = value
}

function generateSecret() {
    const bytes = new Uint8Array(24)
    crypto.getRandomValues(bytes)
    webhookSecret.value = Array.from(bytes).map((b) => b.toString(16).padStart(2, '0')).join('')
}

const copyConfirm = ref(false)
async function copy(text: string) {
    try {
        await navigator.clipboard.writeText(text)
        copyConfirm.value = true
        setTimeout(() => (copyConfirm.value = false), 1200)
    } catch {
        // ignore
    }
}

const triggerLabel = computed(() => {
    switch (props.draft.type) {
        case 'manual': return 'Manual trigger'
        case 'scheduled': return 'Scheduled trigger'
        case 'webhook': return 'Webhook trigger'
    }
})

const triggerIcon = computed(() => {
    switch (props.draft.type) {
        case 'manual': return 'play_circle'
        case 'scheduled': return 'schedule'
        case 'webhook': return 'webhook'
    }
})

watch(() => props.draft.type, () => {
    if (props.draft.type === 'webhook' && !props.draft.webhookSecret && !props.persistedSecretMasked) {
        generateSecret()
    }
})
</script>

<template>
    <aside class="trigger-inspector">
        <header class="trigger-inspector__head">
            <h3 class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Trigger</h3>
            <Button variant="ghost" leading-icon="swap_horiz" size="sm" @click="emit('change-type')">Change</Button>
        </header>
        <div class="trigger-inspector__body">
            <div class="flex items-center gap-sm">
                <span class="trigger-pill">
                    <Icon :name="triggerIcon" :size="18" />
                </span>
                <div class="flex flex-col gap-0.5 flex-1 min-w-0">
                    <Badge tone="info">{{ triggerLabel }}</Badge>
                    <span class="text-body-sm text-on-surface-variant">Entry-point of this workflow</span>
                </div>
                <Button
                    variant="icon"
                    aria-label="Remove trigger"
                    class="!text-failed hover:!bg-failed/10"
                    @click="emit('remove')"
                >
                    <Icon name="delete" :size="18" />
                </Button>
            </div>

            <label class="flex items-center justify-between gap-sm">
                <span class="text-body-sm text-on-surface">Enabled</span>
                <button
                    type="button"
                    role="switch"
                    :aria-checked="enabled"
                    :class="['toggle', enabled ? 'is-on' : '']"
                    @click="enabled = !enabled"
                >
                    <span class="toggle__dot" />
                </button>
            </label>

            <template v-if="draft.type === 'manual'">
                <Alert tone="info" title="Manual trigger" compact>
                    Click <span class="font-bold text-on-surface">Test Run</span> in the topbar or hit
                    <code class="font-code-sm">POST /api/workflows/&lt;id&gt;/trigger</code> to run.
                </Alert>
            </template>

            <template v-else-if="draft.type === 'scheduled'">
                <label class="flex flex-col gap-1">
                    <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Cron expression</span>
                    <input
                        v-model="cronExpression"
                        class="input-dark rounded-DEFAULT px-sm py-1.5 text-code-md font-code-md"
                        placeholder="0 * * * *"
                    >
                    <span class="text-body-sm text-on-surface-variant">Five-field cron syntax (minute, hour, day, month, weekday).</span>
                </label>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="p in cronPresets"
                        :key="p.value"
                        type="button"
                        class="px-sm py-1 rounded-full bg-secondary/10 text-secondary text-code-sm font-code-sm border border-secondary/30 hover:bg-secondary/20 transition-colors"
                        @click="applyPreset(p.value)"
                    >{{ p.label }}</button>
                </div>
                <label class="flex flex-col gap-1">
                    <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Timezone</span>
                    <input
                        v-model="timezone"
                        class="input-dark rounded-DEFAULT px-sm py-1.5 text-body-md"
                        placeholder="UTC"
                    >
                </label>
            </template>

            <template v-else>
                <div class="flex flex-col gap-1">
                    <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Webhook URL</span>
                    <div class="flex items-center gap-sm">
                        <code class="flex-1 min-w-0 truncate rounded-DEFAULT bg-surface-container-lowest border border-outline-variant/40 px-sm py-1.5 text-code-md font-code-md">{{ webhookUrl }}</code>
                        <Button variant="icon" :aria-label="copyConfirm ? 'Copied' : 'Copy URL'" @click="copy(webhookUrl)">
                            <Icon :name="copyConfirm ? 'check' : 'content_copy'" :size="16" />
                        </Button>
                    </div>
                    <span v-if="!workflowId" class="text-body-sm text-warning">Save the workflow first to mint a stable webhook URL.</span>
                </div>

                <Alert v-if="persistedSecretMasked" tone="warning" title="Secret already issued" compact>
                    The webhook secret is stored on the server and not retrievable. Generate a new one to rotate.
                </Alert>

                <div class="flex flex-col gap-1">
                    <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">HMAC secret</span>
                    <div class="flex items-center gap-sm">
                        <input
                            v-model="webhookSecret"
                            class="input-dark rounded-DEFAULT px-sm py-1.5 text-code-md font-code-md flex-1"
                            placeholder="Click generate to mint a secret"
                            type="text"
                        >
                        <Button variant="secondary" leading-icon="autorenew" size="sm" @click="generateSecret">Generate</Button>
                    </div>
                    <span class="text-body-sm text-on-surface-variant">Sign requests with <code class="font-code-sm">X-FlowForge-Signature: sha256=&lt;hmac&gt;</code>.</span>
                </div>
            </template>
        </div>
    </aside>
</template>

<style scoped>
.trigger-inspector {
    display: flex;
    flex-direction: column;
    height: 100%;
    background: var(--color-surface-container);
    border: 1px solid color-mix(in srgb, var(--color-outline-variant) 40%, transparent);
    border-radius: var(--radius-xl);
    overflow: hidden;
}

.trigger-inspector__head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    border-bottom: 1px solid color-mix(in srgb, var(--color-outline-variant) 40%, transparent);
    background: var(--color-surface-container-high);
}

.trigger-inspector__body {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.trigger-pill {
    width: 36px;
    height: 36px;
    border-radius: var(--radius-DEFAULT);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: color-mix(in srgb, var(--color-secondary) 14%, transparent);
    color: var(--color-secondary);
    border: 1px solid color-mix(in srgb, var(--color-secondary) 40%, transparent);
}

.toggle {
    width: 38px;
    height: 22px;
    border-radius: 999px;
    background: color-mix(in srgb, var(--color-outline-variant) 50%, transparent);
    border: 0;
    position: relative;
    cursor: pointer;
    padding: 0;
    transition: background 0.15s ease;
}

.toggle.is-on {
    background: var(--color-secondary);
}

.toggle__dot {
    position: absolute;
    top: 2px;
    left: 2px;
    width: 18px;
    height: 18px;
    border-radius: 999px;
    background: var(--color-on-secondary);
    transition: transform 0.15s ease;
}

.toggle.is-on .toggle__dot {
    transform: translateX(16px);
}
</style>
