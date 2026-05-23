<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import GlassPanel from '@/components/ui/GlassPanel.vue'
import Button from '@/components/ui/Button.vue'
import Icon from '@/components/ui/Icon.vue'
import DataTable from '@/components/ui/DataTable.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import Alert from '@/components/ui/Alert.vue'
import StatusBadge from '@/components/workflow/StatusBadge.vue'
import WorkflowMetaWizard, { type WorkflowMetaSubmit } from '@/components/workflow/WorkflowMetaWizard.vue'
import { workflows, deleteWorkflow, triggerWorkflow } from '@/services/api/client'
import type { Workflow, WorkflowStatus } from '@/types/api'
import { formatRelativeTime } from '@/utils/format'

const router = useRouter()
const loading = ref(true)
const error = ref<string | null>(null)
const search = ref('')
const statusFilter = ref<'all' | WorkflowStatus>('all')
const workflowList = ref<Workflow[]>([])
const wizardOpen = ref(false)

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'status', label: 'Status' },
    { key: 'version', label: 'Version', align: 'left' as const },
    { key: 'steps', label: 'Steps', align: 'right' as const },
    { key: 'updated', label: 'Updated' },
    { key: 'description', label: 'Description' },
    { key: 'actions', label: 'Actions', align: 'right' as const, width: '180px' },
]

const filteredWorkflows = computed(() =>
    workflowList.value.filter((workflow) => {
        const matchesSearch =
            workflow.name.toLowerCase().includes(search.value.toLowerCase()) ||
            (workflow.description ?? '').toLowerCase().includes(search.value.toLowerCase()) ||
            workflow.id.toLowerCase().includes(search.value.toLowerCase())
        const matchesStatus = statusFilter.value === 'all' || workflow.status === statusFilter.value
        return matchesSearch && matchesStatus
    }),
)

async function loadWorkflows(): Promise<void> {
    loading.value = true
    error.value = null
    try {
        const response = await workflows()
        workflowList.value = response.data
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load workflows'
    } finally {
        loading.value = false
    }
}

function viewWorkflow(workflow: Workflow): void {
    router.push({ name: 'workflows.builder', query: { workflowId: workflow.id, mode: 'view' } })
}

function editWorkflow(workflow: Workflow): void {
    router.push({ name: 'workflows.builder', query: { workflowId: workflow.id, mode: 'edit' } })
}

function openWizard(): void {
    wizardOpen.value = true
}

function handleWizardSubmit(payload: WorkflowMetaSubmit): void {
    wizardOpen.value = false
    router.push({
        name: 'workflows.builder',
        query: {
            mode: 'create',
            name: payload.name,
            description: payload.description || undefined,
            globalTimeoutMs: String(payload.globalTimeoutMs),
            maxAttempts: String(payload.defaultMaxAttempts),
            initialStatus: payload.initialStatus,
        },
    })
}

async function removeWorkflow(workflow: Workflow): Promise<void> {
    if (!confirm(`Delete workflow "${workflow.name}"?`)) return
    try {
        await deleteWorkflow(workflow.id)
        await loadWorkflows()
    } catch (err) {
        alert(err instanceof Error ? err.message : 'Failed to delete workflow')
    }
}

async function runWorkflow(workflow: Workflow): Promise<void> {
    try {
        const run = await triggerWorkflow(workflow.id)
        await router.push({ name: 'runs', query: { runId: run.id } })
    } catch (err) {
        alert(err instanceof Error ? err.message : 'Failed to trigger workflow')
    }
}

onMounted(loadWorkflows)
</script>

<template>
    <div>
        <PageHeader
            eyebrow="Workflow Catalog"
            title="Workflows"
            subtitle="Manage, trigger, and inspect your workflow definitions. All actions hit the real backend."
        >
            <template #actions>
                <Button variant="secondary" leading-icon="refresh" :disabled="loading" @click="loadWorkflows">Refresh</Button>
                <Button leading-icon="add" @click="openWizard">New Workflow</Button>
            </template>
        </PageHeader>

        <Alert v-if="error" tone="error" class="mb-md">{{ error }}</Alert>

        <GlassPanel radius="xl" clamp>
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-sm md:gap-md p-sm md:p-md border-b border-outline-variant/30 bg-surface-container-high/40">
                <div class="relative flex-1 max-w-lg">
                    <Icon
                        name="search"
                        :size="18"
                        class="absolute left-sm top-1/2 -translate-y-1/2 text-on-surface-variant pointer-events-none"
                    />
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search by name, ID, or description…"
                        class="input-dark w-full rounded-DEFAULT pl-9 pr-md py-sm text-body-md"
                    >
                </div>
                <div class="flex items-center gap-sm">
                    <div class="relative">
                        <Icon
                            name="filter_list"
                            :size="16"
                            class="absolute left-sm top-1/2 -translate-y-1/2 text-on-surface-variant pointer-events-none"
                        />
                        <select
                            v-model="statusFilter"
                            class="input-dark rounded-DEFAULT pl-8 pr-md py-sm text-body-md min-w-[160px] appearance-none"
                        >
                            <option value="all">All status</option>
                            <option value="draft">Draft</option>
                            <option value="active">Active</option>
                            <option value="archived">Archived</option>
                        </select>
                        <Icon
                            name="expand_more"
                            :size="18"
                            class="absolute right-2 top-1/2 -translate-y-1/2 text-on-surface-variant pointer-events-none"
                        />
                    </div>
                </div>
            </div>

            <DataTable
                :items="filteredWorkflows"
                :columns="columns"
                :row-key="(w) => w.id"
                :loading="loading"
                empty-icon="account_tree"
                empty-title="No workflows match"
                :empty-description="search ? 'Adjust your filters or clear the search.' : 'Click New Workflow to create your first DAG.'"
            >
                <template #cell-name="{ item }">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-body-md font-bold text-on-surface">{{ (item as Workflow).name }}</span>
                        <span class="text-code-sm font-code-sm text-on-surface-variant">{{ (item as Workflow).id }}</span>
                    </div>
                </template>
                <template #cell-status="{ item }">
                    <StatusBadge :status="(item as Workflow).status" dot />
                </template>
                <template #cell-version="{ item }">
                    <code class="text-code-sm font-code-sm text-on-surface">{{ (item as Workflow).currentVersion ? `v${(item as Workflow).currentVersion!.versionNumber}` : '—' }}</code>
                </template>
                <template #cell-steps="{ item }">
                    <span class="text-body-md font-bold tabular-nums text-on-surface">{{ (item as Workflow).currentVersion?.definition.steps.length ?? 0 }}</span>
                </template>
                <template #cell-updated="{ item }">
                    <span class="text-body-sm text-on-surface-variant">{{ formatRelativeTime((item as Workflow).updatedAt) }}</span>
                </template>
                <template #cell-description="{ item }">
                    <span class="text-body-sm text-on-surface-variant block max-w-md truncate" :title="(item as Workflow).description ?? ''">{{ (item as Workflow).description || 'No description provided.' }}</span>
                </template>
                <template #cell-actions="{ item }">
                    <div class="inline-flex items-center gap-1 justify-end">
                        <Button
                            variant="icon"
                            aria-label="Run"
                            @click="runWorkflow(item as Workflow)"
                        >
                            <Icon name="play_arrow" :size="18" filled />
                        </Button>
                        <Button
                            variant="icon"
                            aria-label="View"
                            @click="viewWorkflow(item as Workflow)"
                        >
                            <Icon name="visibility" :size="18" />
                        </Button>
                        <Button
                            variant="icon"
                            aria-label="Edit"
                            @click="editWorkflow(item as Workflow)"
                        >
                            <Icon name="edit" :size="18" />
                        </Button>
                        <Button
                            variant="icon"
                            aria-label="Delete"
                            class="hover:!text-failed"
                            @click="removeWorkflow(item as Workflow)"
                        >
                            <Icon name="delete" :size="18" />
                        </Button>
                    </div>
                </template>
            </DataTable>

            <div class="px-md py-sm border-t border-outline-variant/30 bg-surface-container-low/30">
                <span class="text-body-sm font-body-sm text-on-surface-variant">
                    Showing <span class="font-bold text-on-surface">{{ filteredWorkflows.length }}</span> of <span class="font-bold text-on-surface">{{ workflowList.length }}</span> workflows
                </span>
            </div>
        </GlassPanel>

        <WorkflowMetaWizard
            :open="wizardOpen"
            @close="wizardOpen = false"
            @submit="handleWizardSubmit"
        />
    </div>
</template>
