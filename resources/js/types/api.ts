export type Tenant = {
    id: string
    name: string
    slug: string
}

export type AuthUser = {
    id: number | string
    name: string
    email: string
    role: 'admin' | 'editor' | 'viewer'
    tenant: Tenant | null
}

export type ApiCollection<T> = {
    data: T[]
    meta?: {
        current_page?: number
        last_page?: number
        per_page?: number
        total?: number
    }
    links?: Record<string, string | null>
}

export type ApiItem<T> = {
    data: T
}

export type WorkflowStepDefinition = {
    id: string
    type: 'HTTP' | 'DELAY' | 'CONDITION' | 'SCRIPT' | string
    name: string
    dependsOn?: string[]
    timeoutMs?: number
    config?: Record<string, unknown>
    retry?: { maxAttempts?: number }
}

export type WorkflowDefinition = {
    schemaVersion: number
    name: string
    globalTimeoutMs: number
    steps: WorkflowStepDefinition[]
}

export type WorkflowVersion = {
    id: string
    versionNumber: number
    definition: WorkflowDefinition
    source: string
    changeSummary?: string | null
    rolledBackFromVersionId?: string | null
    createdAt?: string
}

export type WorkflowStatus = 'draft' | 'active' | 'archived'

export type Workflow = {
    id: string
    tenantId: string
    createdBy: number | string
    name: string
    description: string | null
    status: WorkflowStatus
    currentVersion: WorkflowVersion | null
    createdAt?: string
    updatedAt?: string
}

export type RunStatus =
    | 'PENDING'
    | 'RUNNING'
    | 'SUCCESS'
    | 'FAILED'
    | 'SKIPPED'
    | 'RETRYING'
    | 'TIMEOUT'
    | 'CANCELLED'

export type StepRun = {
    id: string
    stepId: string
    stepType: string
    status: RunStatus
    attemptCount: number
    maxAttempts: number
    startedAt?: string | null
    finishedAt?: string | null
    durationMs?: number | null
    output?: unknown
    errorMessage?: string | null
}

export type WorkflowRun = {
    id: string
    workflowId: string
    workflowVersionId: string
    workflowTriggerId?: string | null
    status: RunStatus
    input?: Record<string, unknown>
    timeoutMs?: number
    startedAt?: string | null
    finishedAt?: string | null
    durationMs?: number | null
    createdAt?: string
    updatedAt?: string
    createdBy?: number | string
    stepRuns?: StepRun[]
    logs?: ExecutionLog[]
}

export type ExecutionLog = {
    id: string
    workflowStepRunId?: string | null
    level: 'debug' | 'info' | 'warning' | 'error' | string
    event: string
    message: string
    context?: Record<string, unknown> | null
    createdAt?: string
}

export type AiFailureAnalysis = {
    id: string
    workflowRunId: string
    workflowStepRunId: string | null
    attemptCount: number
    rootCause: string
    suggestedFix: string
    confidence: 'low' | 'medium' | 'high'
    category: string
    evidence: Array<{ observation: string; source: string }>
    createdAt?: string
}

export type RawAiFailureAnalysis = {
    id: string
    workflow_run_id: string
    workflow_step_run_id: string | null
    attempt_count: number
    root_cause: string
    suggested_fix: string
    confidence: 'low' | 'medium' | 'high'
    category: string
    evidence: Array<{ observation: string; source: string }>
    created_at?: string
}

export type HealthMetrics = {
    window: 'last_24h'
    generatedAt: string
    activeRuns: number
    totals: {
        runs: number
        success: number
        failed: number
        timeout: number
    }
    rates: {
        success: number
        failure: number
        timeout: number
    }
    averageDurationMs: number | null
    p95DurationMs: number | null
}

export type RawHealthMetrics = {
    window: 'last_24h'
    generated_at: string
    active_runs: number
    totals: {
        runs: number
        success: number
        failed: number
        timeout: number
    }
    rates: {
        success: number
        failure: number
        timeout: number
    }
    average_duration_ms: number | null
    p95_duration_ms: number | null
}

export type RawWorkflow = {
    id: string
    tenant_id: string
    created_by: number | string
    name: string
    description: string | null
    status: WorkflowStatus
    current_version: RawWorkflowVersion | null
    created_at?: string
    updated_at?: string
}

export type RawWorkflowVersion = {
    id: string
    version_number: number
    definition: WorkflowDefinition
    source: string
    change_summary?: string | null
    rolled_back_from_version_id?: string | null
    created_at?: string
}

export type RawStepRun = {
    id: string
    step_id: string
    step_type: string
    status: RunStatus
    attempt_count: number
    max_attempts: number
    started_at?: string | null
    finished_at?: string | null
    duration_ms?: number | null
    output?: unknown
    error_message?: string | null
}

export type RawWorkflowRun = {
    id: string
    workflow_id: string
    workflow_version_id: string
    workflow_trigger_id?: string | null
    status: RunStatus
    input?: Record<string, unknown>
    timeout_ms?: number
    started_at?: string | null
    finished_at?: string | null
    duration_ms?: number | null
    created_at?: string
    updated_at?: string
    created_by?: number | string
    step_runs?: RawStepRun[]
    logs?: RawExecutionLog[]
}

export type RawExecutionLog = {
    id: string
    workflow_step_run_id?: string | null
    level: string
    event: string
    message: string
    context?: Record<string, unknown> | null
    created_at?: string
}

export type AuthTokenPair = {
    accessToken: string
    refreshToken: string
    tokenType: 'Bearer'
    expiresIn: number
}
