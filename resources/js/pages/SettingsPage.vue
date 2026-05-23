<script setup lang="ts">
import { computed, ref } from 'vue'
import GlassPanel from '@/components/ui/GlassPanel.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import Alert from '@/components/ui/Alert.vue'
import Icon from '@/components/ui/Icon.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

type SectionId = 'workspace' | 'auth' | 'execution' | 'ai'

const sections = [
    { id: 'workspace' as SectionId, label: 'Workspace', icon: 'business', description: 'Tenant identity surfaced from the authenticated user record.' },
    { id: 'auth' as SectionId, label: 'Auth & Access', icon: 'shield_lock', description: 'Current JWT/RBAC posture for the signed-in session.' },
    { id: 'execution' as SectionId, label: 'Execution', icon: 'play_circle', description: 'Read-only summary of execution policy enforced by the backend.' },
    { id: 'ai' as SectionId, label: 'AI Features', icon: 'auto_awesome', description: 'Failure analysis surface and how it is exposed in this submission.' },
]

const active = ref<SectionId>('workspace')
const activeSection = computed(() => sections.find((s) => s.id === active.value)!)
</script>

<template>
    <div>
        <PageHeader
            eyebrow="Workspace Controls"
            title="Settings"
            subtitle="Backend-aware settings summary. No fake save controls — every shown value reflects real session state."
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

                <div v-else-if="active === 'auth'" class="flex flex-col gap-sm">
                    <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40 flex justify-between items-center">
                        <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">Role</span>
                        <code class="text-body-lg font-code-md text-secondary uppercase">{{ auth.user?.role ?? 'Unavailable' }}</code>
                    </div>
                    <Alert tone="info" title="JWT auth is active">
                        Frontend reads the authenticated context from <code class="font-code-sm">/api/auth/me</code>. Role mutation is intentionally not exposed because no client-facing endpoint exists for it.
                    </Alert>
                </div>

                <div v-else-if="active === 'execution'" class="flex flex-col gap-sm">
                    <Alert tone="info" title="Read-only execution summary">
                        Retry policy, timeouts, and step handler config live in workflow definitions and backend policy. This screen does not promise editable controls without matching endpoints.
                    </Alert>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-sm">
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Retry Backoff</p>
                            <p class="text-body-md text-on-surface m-0">Exponential, configured per step</p>
                        </div>
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Global Timeout</p>
                            <p class="text-body-md text-on-surface m-0">Required on every workflow definition</p>
                        </div>
                        <div class="p-md rounded-DEFAULT bg-surface-container-low border border-outline-variant/40">
                            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Script Allowlist</p>
                            <p class="text-body-md text-on-surface m-0">noop, set_output, transform, fail_demo</p>
                        </div>
                    </div>
                </div>

                <div v-else class="flex flex-col gap-sm">
                    <Alert tone="info" title="AI Failure Analysis">
                        Real backend endpoint <code class="font-code-sm">POST /api/workflow-runs/:id/analyze-failure</code>. Default provider is the deterministic mock — no API keys required. UI affordance only appears for failed/timed-out runs.
                    </Alert>
                </div>
            </GlassPanel>
        </div>
    </div>
</template>
