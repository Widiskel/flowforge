<script setup lang="ts">
import { computed, ref } from 'vue'
import GlassPanel from '@/components/ui/GlassPanel.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import Alert from '@/components/ui/Alert.vue'
import Icon from '@/components/ui/Icon.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

type SectionId = 'workspace' | 'auth' | 'execution' | 'security' | 'ai'

const sections = [
    { id: 'workspace' as SectionId, label: 'Workspace', icon: 'business', description: 'Tenant identity surfaced from the authenticated user record.' },
    { id: 'auth' as SectionId, label: 'Auth & Access', icon: 'shield_lock', description: 'Current JWT/RBAC posture for the signed-in session.' },
    { id: 'execution' as SectionId, label: 'Execution', icon: 'play_circle', description: 'How the queued executor + handler stack behaves at runtime.' },
    { id: 'security' as SectionId, label: 'Security', icon: 'gpp_good', description: 'Step-level guardrails: SCRIPT sandbox, HTTP SSRF, webhook HMAC.' },
    { id: 'ai' as SectionId, label: 'AI Failure Analysis', icon: 'auto_awesome', description: 'Intelligent enhancement surface for FAILED / TIMEOUT runs.' },
]

const active = ref<SectionId>('workspace')
const activeSection = computed(() => sections.find((s) => s.id === active.value)!)
</script>

<template>
    <div>
        <PageHeader
            eyebrow="Workspace Controls"
            title="Settings"
            subtitle="Backend-aware settings summary. Every shown value reflects real session or backend policy — there are no fake save controls."
        />

        <div class="grid grid-cols-1 lg:grid-cols-[280px_minmax(0,1fr)] gap-md items-start">
            <GlassPanel radius="xl" clamp>
                <nav class="flex flex-col gap-1 p-sm">
                    <button
                        v-for="section in sections"
                        :key="section.id"
                        type="button"
                        :class="[
                            'flex items-start gap-sm p-sm rounded-DEFAULT text-left transition-all border',
                            active === section.id
                                ? 'border-secondary/40 bg-secondary/5 text-on-surface'
                                : 'border-transparent text-on-surface-variant hover:bg-surface-variant/30 hover:text-on-surface',
                        ]"
                        @click="active = section.id"
                    >
                        <Icon :name="section.icon" :size="20" :filled="active === section.id" :class="active === section.id ? 'text-secondary' : ''" />
                        <span class="flex flex-col gap-0.5">
                            <span class="text-body-md font-bold">{{ section.label }}</span>
                            <span class="text-body-sm text-on-surface-variant leading-tight">{{ section.description }}</span>
                        </span>
                    </button>
                </nav>
            </GlassPanel>

            <GlassPanel radius="xl" padded class="min-h-[420px]">
                <p class="text-label-caps font-label-caps text-secondary uppercase tracking-wider m-0 mb-xs">{{ activeSection.label }}</p>
                <h3 class="text-headline-md font-headline-md text-on-surface m-0">{{ activeSection.label }}</h3>
                <p class="text-body-md text-on-surface-variant mt-xs mb-md">{{ activeSection.description }}</p>

                <!-- Workspace -->
                <div v-if="active === 'workspace'" class="grid grid-cols-1 md:grid-cols-2 gap-sm">
                    <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40 flex flex-col gap-1 min-w-0">
                        <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Tenant Name</span>
                        <span class="text-body-lg font-bold text-on-surface truncate">{{ auth.user?.tenant?.name ?? 'Unavailable' }}</span>
                    </div>
                    <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40 flex flex-col gap-1 min-w-0">
                        <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Tenant Slug</span>
                        <code class="text-body-lg font-code-md text-on-surface truncate">{{ auth.user?.tenant?.slug ?? 'Unavailable' }}</code>
                    </div>
                    <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40 flex flex-col gap-1 min-w-0">
                        <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Signed-in user</span>
                        <span class="text-body-lg font-bold text-on-surface truncate">{{ auth.user?.name ?? 'Unavailable' }}</span>
                    </div>
                    <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40 flex flex-col gap-1 min-w-0">
                        <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Email</span>
                        <span class="text-body-lg text-on-surface truncate">{{ auth.user?.email ?? 'Unavailable' }}</span>
                    </div>
                </div>

                <!-- Auth & Access -->
                <div v-else-if="active === 'auth'" class="flex flex-col gap-sm">
                    <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40 flex justify-between items-center">
                        <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Role</span>
                        <code class="text-body-lg font-code-md text-secondary uppercase">{{ auth.user?.role ?? 'Unavailable' }}</code>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-sm">
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Token type</p>
                            <p class="text-body-md text-on-surface m-0">JWT bearer + refresh rotation. Refresh token is single-use; reuse triggers revocation.</p>
                        </div>
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Tenant scoping</p>
                            <p class="text-body-md text-on-surface m-0">Every request resolves the active tenant from the JWT claim. Cross-tenant resources return 404, never 403.</p>
                        </div>
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">RBAC matrix</p>
                            <p class="text-body-md text-on-surface m-0">Admin: full CRUD. Editor: workflow + trigger CRUD, run trigger. Viewer: read-only.</p>
                        </div>
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Rate limits</p>
                            <p class="text-body-md text-on-surface m-0">Per route group: api, login, refresh, webhook, ai-analyze, metrics, sse, playground.</p>
                        </div>
                    </div>
                    <Alert tone="info" title="JWT auth is active">
                        Frontend reads the authenticated context from <code class="font-code-sm">/api/auth/me</code>.
                        Role mutation is intentionally not exposed because no client-facing endpoint exists for it.
                    </Alert>
                </div>

                <!-- Execution -->
                <div v-else-if="active === 'execution'" class="flex flex-col gap-sm">
                    <Alert tone="info" title="Queued executor">
                        Trigger requests return a <code class="font-code-sm">PENDING</code> run immediately.
                        The actual workflow runs in <code class="font-code-sm">App\Jobs\ExecuteWorkflowRun</code>
                        on the queue worker, so HTTP steps that loop back to the same dev server don't deadlock.
                    </Alert>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-sm">
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Step types</p>
                            <p class="text-body-md text-on-surface m-0">HTTP, DELAY, CONDITION, SCRIPT, LOG. Each handler is registered in <code class="font-code-sm">WorkflowExecutor</code>.</p>
                        </div>
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Retry policy</p>
                            <p class="text-body-md text-on-surface m-0">Exponential or fixed backoff per step, configurable via the Settings tab on the inspector. Capped at 5 attempts.</p>
                        </div>
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Global timeout</p>
                            <p class="text-body-md text-on-surface m-0">Required on every workflow definition. Validator caps the value between 1s and 600s.</p>
                        </div>
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">DELAY ceiling</p>
                            <p class="text-body-md text-on-surface m-0">DELAY step caps duration at 30 seconds at the handler layer; the builder UI mirrors that limit.</p>
                        </div>
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Run states</p>
                            <p class="text-body-md text-on-surface m-0">PENDING, RUNNING, SUCCESS, FAILED, SKIPPED, RETRYING, TIMEOUT, CANCELLED. Tracked separately on runs and step runs.</p>
                        </div>
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Live monitoring</p>
                            <p class="text-body-md text-on-surface m-0">SSE stream at <code class="font-code-sm">/api/workflow-runs/&#123;run&#125;/events</code> emits snapshots until terminal status, with a heartbeat tick.</p>
                        </div>
                    </div>
                </div>

                <!-- Security -->
                <div v-else-if="active === 'security'" class="flex flex-col gap-sm">
                    <Alert tone="info" title="Defense-in-depth at the step layer">
                        Each step type ships its own guardrails before the request leaves the executor.
                    </Alert>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-sm">
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">SCRIPT sandbox</p>
                            <p class="text-body-md text-on-surface m-0">User JavaScript runs in a Node 18 child process. <code class="font-code-sm">$doc</code> + <code class="font-code-sm">fetch</code> + <code class="font-code-sm">console</code> exposed; filesystem, child_process, vm wiped from <code class="font-code-sm">require.cache</code>. 8s wall-clock, 16 KB script length.</p>
                        </div>
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">HTTP SSRF guard</p>
                            <p class="text-body-md text-on-surface m-0">URL scheme allowlist (http/https). Optional private/loopback IP block via <code class="font-code-sm">HTTP_STEP_ALLOW_PRIVATE_NETWORK=false</code> in production.</p>
                        </div>
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Webhook trigger</p>
                            <p class="text-body-md text-on-surface m-0">HMAC signature mandatory, compared with <code class="font-code-sm">hash_equals</code>. Bounded payload size.</p>
                        </div>
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">AI prompt sanitization</p>
                            <p class="text-body-md text-on-surface m-0">Failure-analysis context redacts <code class="font-code-sm">authorization</code>, <code class="font-code-sm">cookie</code>, <code class="font-code-sm">token</code>, <code class="font-code-sm">password</code>, <code class="font-code-sm">secret</code>, <code class="font-code-sm">api[_-]?key</code> keys + values. Oversized strings truncated.</p>
                        </div>
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40 md:col-span-2">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Server-controlled fields</p>
                            <p class="text-body-md text-on-surface m-0">tenant_id, created_by, version_number, run / step status — all server-set. The client cannot impersonate them through the API.</p>
                        </div>
                    </div>
                </div>

                <!-- AI Failure Analysis -->
                <div v-else class="flex flex-col gap-sm">
                    <Alert tone="info" title="Intelligent failure analysis">
                        Endpoint <code class="font-code-sm">POST /api/workflow-runs/&#123;run&#125;/analyze-failure</code>.
                        Default provider is the deterministic mock — no API keys required.
                        UI affordance only appears for runs in <code class="font-code-sm">FAILED</code> or <code class="font-code-sm">TIMEOUT</code>.
                    </Alert>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-sm">
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Driver abstraction</p>
                            <p class="text-body-md text-on-surface m-0">Real LLM provider can replace the mock by swapping the <code class="font-code-sm">FailureAnalyzer</code> binding — no controller, resource, or test changes.</p>
                        </div>
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Structured response</p>
                            <p class="text-body-md text-on-surface m-0">rootCause, suggestedFix, confidence, category, evidence. Stored in <code class="font-code-sm">ai_failure_analyses</code> per run, idempotent unless re-analyze is forced.</p>
                        </div>
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Access control</p>
                            <p class="text-body-md text-on-surface m-0">Admin / Editor only, same tenant. Viewer or cross-tenant requests return 4xx (covered by tests).</p>
                        </div>
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Audit trail</p>
                            <p class="text-body-md text-on-surface m-0">Every analysis attempt writes a row to <code class="font-code-sm">ai_audit_log</code> with sanitized prompt + response metadata.</p>
                        </div>
                    </div>
                </div>
            </GlassPanel>
        </div>
    </div>
</template>
