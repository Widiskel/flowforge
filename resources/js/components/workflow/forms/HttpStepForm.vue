<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import Alert from '@/components/ui/Alert.vue'
import type { BuilderStep } from './_shared'

const props = defineProps<{ step: BuilderStep }>()

const methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as const

const method = computed({
    get: () => String(props.step.config.method ?? 'GET').toUpperCase(),
    set: (value: string) => {
        props.step.config.method = value
    },
})

const url = computed({
    get: () => String(props.step.config.url ?? ''),
    set: (value: string) => {
        props.step.config.url = value
    },
})

const timeoutMs = computed({
    get: () => Number(props.step.config.timeoutMs ?? 10000),
    set: (value: number) => {
        props.step.config.timeoutMs = Number.isFinite(value) ? value : 10000
    },
})

const headersJson = ref('')
const bodyJson = ref('')
const headersError = ref<string | null>(null)
const bodyError = ref<string | null>(null)

function syncHeadersFromConfig() {
    const raw = props.step.config.headers
    if (!raw || (typeof raw === 'object' && Object.keys(raw as object).length === 0)) {
        headersJson.value = ''
        return
    }
    try {
        headersJson.value = JSON.stringify(raw, null, 2)
    } catch {
        headersJson.value = ''
    }
}

function syncBodyFromConfig() {
    const raw = props.step.config.body
    if (raw === undefined || raw === null) {
        bodyJson.value = ''
        return
    }
    if (typeof raw === 'string') {
        bodyJson.value = raw
        return
    }
    try {
        bodyJson.value = JSON.stringify(raw, null, 2)
    } catch {
        bodyJson.value = ''
    }
}

watch(() => props.step.id, () => {
    syncHeadersFromConfig()
    syncBodyFromConfig()
}, { immediate: true })

function commitHeaders() {
    if (!headersJson.value.trim()) {
        props.step.config.headers = {}
        headersError.value = null
        return
    }
    try {
        const parsed = JSON.parse(headersJson.value)
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
            props.step.config.headers = parsed
            headersError.value = null
        } else {
            headersError.value = 'Headers must be a JSON object.'
        }
    } catch (err) {
        headersError.value = err instanceof Error ? err.message : 'Invalid JSON.'
    }
}

function commitBody() {
    const trimmed = bodyJson.value.trim()
    if (!trimmed) {
        props.step.config.body = null
        bodyError.value = null
        return
    }
    try {
        props.step.config.body = JSON.parse(trimmed)
        bodyError.value = null
    } catch {
        // Allow plain text bodies — store as raw string.
        props.step.config.body = bodyJson.value
        bodyError.value = null
    }
}
</script>

<template>
    <div class="flex flex-col gap-md">
        <div class="grid grid-cols-[120px_minmax(0,1fr)] gap-sm">
            <label class="flex flex-col gap-1">
                <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Method</span>
                <select
                    v-model="method"
                    class="input-dark rounded-DEFAULT px-sm py-1.5 text-body-md w-full"
                >
                    <option v-for="m in methods" :key="m" :value="m">{{ m }}</option>
                </select>
            </label>
            <label class="flex flex-col gap-1 min-w-0">
                <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">URL</span>
                <input
                    v-model="url"
                    type="url"
                    class="input-dark rounded-DEFAULT px-sm py-1.5 text-body-md w-full font-code-md"
                    placeholder="https://api.example.com/resource"
                >
            </label>
        </div>

        <label class="flex flex-col gap-1">
            <span class="flex items-center justify-between">
                <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Timeout</span>
                <span class="text-body-sm text-on-surface-variant">ms (max 30000)</span>
            </span>
            <input
                v-model.number="timeoutMs"
                type="number"
                min="1000"
                max="30000"
                step="500"
                class="input-dark rounded-DEFAULT px-sm py-1.5 text-body-md tabular-nums"
            >
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Headers (JSON)</span>
            <textarea
                v-model="headersJson"
                rows="3"
                class="input-dark rounded-DEFAULT px-sm py-1.5 text-code-sm font-code-md resize-y"
                placeholder='{ "Authorization": "Bearer …" }'
                @blur="commitHeaders"
            />
            <Alert v-if="headersError" tone="error" compact>{{ headersError }}</Alert>
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Body</span>
            <textarea
                v-model="bodyJson"
                rows="4"
                class="input-dark rounded-DEFAULT px-sm py-1.5 text-code-sm font-code-md resize-y"
                placeholder='{ "key": "value" } or plain text'
                @blur="commitBody"
            />
            <Alert v-if="bodyError" tone="error" compact>{{ bodyError }}</Alert>
        </label>

        <div class="rounded-DEFAULT bg-surface-container-low border border-outline-variant/40 p-sm flex items-start gap-sm">
            <Icon name="output" :size="18" class="text-secondary shrink-0 mt-0.5" />
            <div>
                <p class="text-body-sm font-bold text-on-surface m-0">Response is exposed downstream</p>
                <p class="text-body-sm text-on-surface-variant m-0">
                    Other steps can read this step's response via <code class="font-code-sm">steps.{{ step.id }}.status</code> and <code class="font-code-sm">steps.{{ step.id }}.body</code>.
                </p>
            </div>
        </div>
    </div>
</template>
