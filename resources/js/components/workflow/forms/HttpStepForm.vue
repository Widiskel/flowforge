<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import Alert from '@/components/ui/Alert.vue'
import type { BuilderStep } from './_shared'

interface KvRow {
    key: string
    value: string
}

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

const sendQuery = ref(false)
const sendHeaders = ref(false)
const sendBody = ref(false)
const queryRows = ref<KvRow[]>([])
const headerRows = ref<KvRow[]>([])
const bodyMode = ref<'json' | 'form' | 'raw'>('json')
const bodyJson = ref('')
const bodyRaw = ref('')
const bodyForm = ref<KvRow[]>([])
const bodyError = ref<string | null>(null)

function syncFromStep() {
    const cfg = props.step.config

    // Query params
    const url = String(cfg.url ?? '')
    const queryString = url.includes('?') ? url.split('?')[1] : ''
    if (queryString) {
        sendQuery.value = true
        queryRows.value = queryString.split('&').filter(Boolean).map((pair) => {
            const [k, ...rest] = pair.split('=')
            return { key: decodeURIComponent(k), value: decodeURIComponent(rest.join('=') ?? '') }
        })
    } else {
        sendQuery.value = false
        queryRows.value = []
    }

    // Headers
    const headers = (cfg.headers ?? {}) as Record<string, string>
    const headerEntries = Object.entries(headers)
    if (headerEntries.length > 0) {
        sendHeaders.value = true
        headerRows.value = headerEntries.map(([k, v]) => ({ key: k, value: String(v) }))
    } else {
        sendHeaders.value = false
        headerRows.value = []
    }

    // Body
    const body = cfg.body
    if (body !== null && body !== undefined && body !== '') {
        sendBody.value = true
        if (typeof body === 'string') {
            bodyMode.value = 'raw'
            bodyRaw.value = body
        } else if (typeof body === 'object') {
            bodyMode.value = 'json'
            bodyJson.value = JSON.stringify(body, null, 2)
        }
    } else {
        sendBody.value = false
    }
}

watch(() => props.step.id, syncFromStep, { immediate: true })

function commitQuery() {
    let base = url.value.split('?')[0]
    if (sendQuery.value && queryRows.value.length > 0) {
        const qs = queryRows.value
            .filter((r) => r.key.trim() !== '')
            .map((r) => `${encodeURIComponent(r.key)}=${encodeURIComponent(r.value)}`)
            .join('&')
        if (qs) base = `${base}?${qs}`
    }
    url.value = base
}

function addQueryRow() { queryRows.value.push({ key: '', value: '' }); commitQuery() }
function removeQueryRow(i: number) { queryRows.value.splice(i, 1); commitQuery() }

function commitHeaders() {
    if (!sendHeaders.value) {
        props.step.config.headers = {}
        return
    }
    const out: Record<string, string> = {}
    for (const row of headerRows.value) {
        if (row.key.trim() === '') continue
        out[row.key.trim()] = row.value
    }
    props.step.config.headers = out
}

function addHeaderRow() { headerRows.value.push({ key: '', value: '' }); commitHeaders() }
function removeHeaderRow(i: number) { headerRows.value.splice(i, 1); commitHeaders() }

function commitBody() {
    bodyError.value = null
    if (!sendBody.value) {
        props.step.config.body = null
        return
    }
    if (bodyMode.value === 'json') {
        const trimmed = bodyJson.value.trim()
        if (!trimmed) {
            props.step.config.body = null
            return
        }
        try {
            props.step.config.body = JSON.parse(trimmed)
        } catch (err) {
            bodyError.value = err instanceof Error ? err.message : 'Invalid JSON.'
        }
    } else if (bodyMode.value === 'form') {
        const out: Record<string, string> = {}
        for (const row of bodyForm.value) {
            if (row.key.trim() === '') continue
            out[row.key.trim()] = row.value
        }
        props.step.config.body = out
    } else {
        props.step.config.body = bodyRaw.value
    }
}

watch(sendQuery, commitQuery)
watch(sendHeaders, commitHeaders)
watch(sendBody, commitBody)
watch(bodyMode, commitBody)

const playgroundOrigin = `${window.location.origin}/api/playground`
const playgroundShortcuts = [
    { label: 'Echo', url: `${playgroundOrigin}/echo` },
    { label: 'Maybe-fail 30%', url: `${playgroundOrigin}/maybe-fail?fail_rate=0.3` },
    { label: 'Metrics', url: `${playgroundOrigin}/metrics` },
    { label: 'User 42', url: `${playgroundOrigin}/users/42` },
]

function applyShortcut(target: string) {
    url.value = target
    syncFromStep()
}

function importCurl(raw: string) {
    // Lightweight curl parser — picks up method, URL, -H, -d only.
    const args = raw.replace(/\\\n/g, ' ').match(/"[^"]*"|'[^']*'|\S+/g)
    if (!args) return
    const tokens = args.map((s) => s.replace(/^['"]|['"]$/g, ''))
    if (tokens[0] !== 'curl') return
    let i = 1
    const headers: Record<string, string> = {}
    let body: string | null = null
    let urlOut = ''
    let methodOut = 'GET'
    while (i < tokens.length) {
        const t = tokens[i]
        if ((t === '-X' || t === '--request') && tokens[i + 1]) {
            methodOut = tokens[i + 1].toUpperCase()
            i += 2
            continue
        }
        if ((t === '-H' || t === '--header') && tokens[i + 1]) {
            const [k, ...rest] = tokens[i + 1].split(':')
            headers[k.trim()] = rest.join(':').trim()
            i += 2
            continue
        }
        if ((t === '-d' || t === '--data' || t === '--data-raw') && tokens[i + 1]) {
            body = tokens[i + 1]
            if (methodOut === 'GET') methodOut = 'POST'
            i += 2
            continue
        }
        if (!t.startsWith('-')) {
            urlOut = t
        }
        i += 1
    }
    method.value = methodOut
    url.value = urlOut
    sendHeaders.value = Object.keys(headers).length > 0
    headerRows.value = Object.entries(headers).map(([k, v]) => ({ key: k, value: v }))
    commitHeaders()
    if (body) {
        sendBody.value = true
        bodyMode.value = 'raw'
        bodyRaw.value = body
        try {
            JSON.parse(body)
            bodyMode.value = 'json'
            bodyJson.value = JSON.stringify(JSON.parse(body), null, 2)
        } catch {
            // keep as raw
        }
        commitBody()
    }
    syncFromStep()
}

const curlText = ref('')
const curlOpen = ref(false)
function applyCurl() {
    importCurl(curlText.value)
    curlText.value = ''
    curlOpen.value = false
}
</script>

<template>
    <div class="flex flex-col gap-md">
        <div class="flex items-center justify-end gap-1">
            <button
                type="button"
                class="px-sm py-1 rounded-full text-label-caps font-label-caps uppercase border border-outline-variant/40 bg-surface-container-low text-on-surface-variant hover:text-on-surface hover:border-secondary/40 transition-colors"
                @click="curlOpen = !curlOpen"
            >
                <Icon name="terminal" :size="14" class="inline-block align-middle mr-1" />
                Import cURL
            </button>
        </div>

        <div v-if="curlOpen" class="flex flex-col gap-sm rounded-DEFAULT border border-outline-variant/40 bg-surface-container-low p-sm">
            <textarea
                v-model="curlText"
                rows="3"
                class="input-dark rounded-DEFAULT px-sm py-1.5 text-code-sm font-code-md resize-y"
                placeholder='curl -X POST https://api.example.com/foo -H "Authorization: Bearer …" -d "{}"'
            />
            <div class="flex items-center justify-end gap-sm">
                <button class="text-body-sm text-on-surface-variant hover:text-on-surface" @click="curlOpen = false">Cancel</button>
                <button
                    class="px-sm py-1 rounded-DEFAULT bg-secondary text-on-secondary text-label-caps font-label-caps uppercase font-bold"
                    :disabled="!curlText.trim()"
                    @click="applyCurl"
                >Apply</button>
            </div>
        </div>

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

        <div class="flex flex-wrap gap-1.5">
            <button
                v-for="shortcut in playgroundShortcuts"
                :key="shortcut.url"
                type="button"
                class="px-sm py-1 rounded-full bg-secondary/10 text-secondary text-code-sm font-code-sm border border-secondary/30 hover:bg-secondary/20 transition-colors"
                @click="applyShortcut(shortcut.url)"
            >Playground · {{ shortcut.label }}</button>
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

        <!-- Send Query Parameters -->
        <section class="flex flex-col gap-sm">
            <label class="flex items-center justify-between gap-sm">
                <span class="text-body-sm text-on-surface">Send Query Parameters</span>
                <button
                    type="button"
                    role="switch"
                    :aria-checked="sendQuery"
                    :class="['toggle-mini', sendQuery ? 'is-on' : '']"
                    @click="sendQuery = !sendQuery"
                ><span /></button>
            </label>
            <div v-if="sendQuery" class="flex flex-col gap-1">
                <div v-for="(row, i) in queryRows" :key="i" class="grid grid-cols-[1fr_1fr_auto] gap-1">
                    <input v-model="row.key" placeholder="Name" class="input-dark rounded-DEFAULT px-sm py-1 text-code-sm font-code-md" @blur="commitQuery">
                    <input v-model="row.value" placeholder="Value" class="input-dark rounded-DEFAULT px-sm py-1 text-code-sm font-code-md" @blur="commitQuery">
                    <button type="button" class="text-on-surface-variant hover:text-failed p-1" aria-label="Remove" @click="removeQueryRow(i)">
                        <Icon name="close" :size="16" />
                    </button>
                </div>
                <button type="button" class="self-start px-sm py-1 rounded-DEFAULT border border-outline-variant/40 bg-surface-container-low text-on-surface-variant hover:text-on-surface text-label-caps font-label-caps uppercase" @click="addQueryRow">
                    <Icon name="add" :size="14" class="inline-block align-middle mr-1" />
                    Add parameter
                </button>
            </div>
        </section>

        <!-- Send Headers -->
        <section class="flex flex-col gap-sm">
            <label class="flex items-center justify-between gap-sm">
                <span class="text-body-sm text-on-surface">Send Headers</span>
                <button
                    type="button"
                    role="switch"
                    :aria-checked="sendHeaders"
                    :class="['toggle-mini', sendHeaders ? 'is-on' : '']"
                    @click="sendHeaders = !sendHeaders"
                ><span /></button>
            </label>
            <div v-if="sendHeaders" class="flex flex-col gap-1">
                <div v-for="(row, i) in headerRows" :key="i" class="grid grid-cols-[1fr_1fr_auto] gap-1">
                    <input v-model="row.key" placeholder="Name" class="input-dark rounded-DEFAULT px-sm py-1 text-code-sm font-code-md" @blur="commitHeaders">
                    <input v-model="row.value" placeholder="Value" class="input-dark rounded-DEFAULT px-sm py-1 text-code-sm font-code-md" @blur="commitHeaders">
                    <button type="button" class="text-on-surface-variant hover:text-failed p-1" aria-label="Remove" @click="removeHeaderRow(i)">
                        <Icon name="close" :size="16" />
                    </button>
                </div>
                <button type="button" class="self-start px-sm py-1 rounded-DEFAULT border border-outline-variant/40 bg-surface-container-low text-on-surface-variant hover:text-on-surface text-label-caps font-label-caps uppercase" @click="addHeaderRow">
                    <Icon name="add" :size="14" class="inline-block align-middle mr-1" />
                    Add header
                </button>
            </div>
        </section>

        <!-- Send Body -->
        <section class="flex flex-col gap-sm">
            <label class="flex items-center justify-between gap-sm">
                <span class="text-body-sm text-on-surface">Send Body</span>
                <button
                    type="button"
                    role="switch"
                    :aria-checked="sendBody"
                    :class="['toggle-mini', sendBody ? 'is-on' : '']"
                    @click="sendBody = !sendBody"
                ><span /></button>
            </label>
            <div v-if="sendBody" class="flex flex-col gap-sm">
                <div class="inline-flex rounded-DEFAULT border border-outline-variant/40 bg-surface-container-low p-0.5 self-start">
                    <button v-for="m in (['json','raw'] as const)" :key="m" type="button"
                        :class="['px-sm py-1 rounded-[3px] text-label-caps font-label-caps uppercase transition-colors', bodyMode === m ? 'bg-secondary/15 text-secondary' : 'text-on-surface-variant']"
                        @click="bodyMode = m">{{ m === 'json' ? 'JSON' : 'Raw' }}</button>
                </div>
                <textarea
                    v-if="bodyMode === 'json'"
                    v-model="bodyJson"
                    rows="5"
                    class="input-dark rounded-DEFAULT px-sm py-1.5 text-code-sm font-code-md resize-y"
                    placeholder='{ "key": "value" }'
                    @blur="commitBody"
                />
                <textarea
                    v-else
                    v-model="bodyRaw"
                    rows="4"
                    class="input-dark rounded-DEFAULT px-sm py-1.5 text-code-sm font-code-md resize-y"
                    placeholder="Plain text body"
                    @blur="commitBody"
                />
                <Alert v-if="bodyError" tone="error" compact>{{ bodyError }}</Alert>
            </div>
        </section>

        <div class="rounded-DEFAULT bg-surface-container-low border border-outline-variant/40 p-sm flex items-start gap-sm">
            <Icon name="output" :size="18" class="text-secondary shrink-0 mt-0.5" />
            <div>
                <p class="text-body-sm font-bold text-on-surface m-0">Response is exposed downstream</p>
                <p class="text-body-sm text-on-surface-variant m-0">
                    Other steps can read this step's response via <code class="font-code-sm">{{ step.id }}.status</code> and <code class="font-code-sm">{{ step.id }}.body</code>.
                </p>
            </div>
        </div>
    </div>
</template>

<style scoped>
.toggle-mini {
    width: 32px;
    height: 18px;
    border-radius: 999px;
    background: color-mix(in srgb, var(--color-outline-variant) 50%, transparent);
    border: 0;
    position: relative;
    cursor: pointer;
    padding: 0;
    transition: background 0.15s ease;
    flex-shrink: 0;
}

.toggle-mini.is-on {
    background: var(--color-secondary);
}

.toggle-mini > span {
    position: absolute;
    top: 2px;
    left: 2px;
    width: 14px;
    height: 14px;
    border-radius: 999px;
    background: var(--color-on-secondary);
    transition: transform 0.15s ease;
}

.toggle-mini.is-on > span {
    transform: translateX(14px);
}
</style>
