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

export type WorkflowStatus = 'draft' | 'active' | 'paused' | 'archived'

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
    stepRuns?: StepRun[]
}

export type ExecutionLog = {
    id: string
    level: 'debug' | 'info' | 'warning' | 'error' | string
    event: string
    message: string
    context?: Record<string, unknown>
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

export type AuthTokenPair = {
    accessToken: string
    refreshToken: string
    tokenType: 'Bearer'
    expiresIn: number
}
