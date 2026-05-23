import type {
    ApiCollection,
    ApiItem,
    AuthTokenPair,
    AuthUser,
    Workflow,
    WorkflowRun,
    HealthMetrics,
    ExecutionLog,
    AiFailureAnalysis,
    RawAiFailureAnalysis,
    RawHealthMetrics,
    RawWorkflow,
    RawWorkflowRun,
    RawWorkflowVersion,
    RawExecutionLog,
    RawStepRun,
    StepRun,
    WorkflowVersion,
} from '@/types/api'

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly details?: unknown,
    ) {
        super(message)
    }
}

const jsonHeaders = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
}

let accessTokenProvider: (() => string | null) | null = null

export function setAccessTokenProvider(provider: () => string | null): void {
    accessTokenProvider = provider
}

function authHeaders(): Record<string, string> {
    const token = accessTokenProvider?.() ?? null

    return token === null ? {} : { Authorization: `Bearer ${token}` }
}

async function request<T>(url: string, init: RequestInit = {}): Promise<T> {
    const response = await fetch(url, {
        ...init,
        headers: {
            ...jsonHeaders,
            ...authHeaders(),
            ...(init.headers ?? {}),
        },
    })

    if (response.status === 204) {
        return undefined as T
    }

    const payload = await response.json().catch(() => null)

    if (!response.ok) {
        const message = payload && typeof payload === 'object' && 'message' in payload
            ? String((payload as { message?: string }).message)
            : `Request failed with status ${response.status}`

        throw new ApiError(message, response.status, payload)
    }

    return payload as T
}

export interface LoginResponse {
    tokens: AuthTokenPair
    user: AuthUser
}

interface RawLoginResponse {
    data: AuthTokenPair
    user: AuthUser
}

export async function login(email: string, password: string): Promise<LoginResponse> {
    const response = await request<RawLoginResponse>('/api/auth/login', {
        method: 'POST',
        body: JSON.stringify({ email, password }),
    })

    return { tokens: response.data, user: response.user }
}

export async function refreshToken(refreshToken: string): Promise<AuthTokenPair> {
    const response = await request<ApiItem<AuthTokenPair>>('/api/auth/refresh', {
        method: 'POST',
        body: JSON.stringify({ refresh_token: refreshToken }),
    })

    return response.data
}

export async function logout(refreshToken?: string): Promise<void> {
    await request('/api/auth/logout', {
        method: 'POST',
        body: JSON.stringify({ refresh_token: refreshToken }),
    })
}

export async function currentUser(): Promise<AuthUser> {
    const response = await request<ApiItem<AuthUser>>('/api/auth/me')

    return response.data
}

export async function workflows(): Promise<ApiCollection<Workflow>> {
    const response = await request<{ data: RawWorkflow[] }>('/api/workflows')
    return {
        ...response,
        data: response.data.map(rawToWorkflow),
    }
}

export async function workflowRuns(workflowId: string): Promise<ApiCollection<WorkflowRun>> {
    const response = await request<{ data: RawWorkflowRun[] }>(`/api/workflow-runs?workflow_id=${encodeURIComponent(workflowId)}`)
    return {
        ...response,
        data: response.data.map(rawToWorkflowRun),
    }
}

export async function workflowRun(runId: string): Promise<WorkflowRun> {
    const response = await request<{ data: RawWorkflowRun }>(`/api/workflow-runs/${runId}`)
    return rawToWorkflowRun(response.data)
}

export async function healthMetrics(): Promise<HealthMetrics> {
    const response = await request<{ data: RawHealthMetrics }>(`/api/health/metrics?window=last_24h`)
    return rawToHealthMetrics(response.data)
}

export async function runLogs(runId: string): Promise<ApiCollection<ExecutionLog>> {
    const response = await request<{ data: RawExecutionLog[] }>(`/api/workflow-runs/${runId}/logs`)
    return {
        ...response,
        data: response.data.map(rawToExecutionLog),
    }
}

export async function analyzeFailure(runId: string): Promise<AiFailureAnalysis> {
    const response = await request<ApiItem<RawAiFailureAnalysis>>(`/api/workflow-runs/${runId}/analyze-failure`, {
        method: 'POST',
    })

    return rawToAiFailureAnalysis(response.data)
}

export function connectRunStream(runId: string, onSnapshot: (run: WorkflowRun) => void, onComplete: (status: string) => void): EventSource {
    const token = accessTokenProvider?.()
    const params = new URLSearchParams({
        max_ticks: '60',
        interval_ms: '1000',
    })

    if (token) {
        // EventSource cannot send Authorization headers, so pass JWT via query string for the SSE endpoint.
        params.set('token', token)
    }

    const eventSource = new EventSource(`/api/workflow-runs/${runId}/events?${params.toString()}`, {
        withCredentials: true,
    })

    eventSource.addEventListener('open', () => {
        // Stream opened
    })

    eventSource.addEventListener('run.snapshot', (event) => {
        const payload = JSON.parse((event as MessageEvent).data) as RawWorkflowRun
        onSnapshot(rawToWorkflowRun(payload))
    })

    eventSource.addEventListener('run.completed', (event) => {
        onComplete((event as MessageEvent).data)
    })

    eventSource.addEventListener('heartbeat', () => {
        // Heartbeat received
    })

    eventSource.addEventListener('error', () => {
        eventSource.close()
    })

    return eventSource
}

function rawToWorkflow(raw: RawWorkflow): Workflow {
    return {
        id: raw.id,
        tenantId: raw.tenant_id,
        createdBy: raw.created_by,
        name: raw.name,
        description: raw.description,
        status: raw.status,
        currentVersion: raw.current_version ? rawToWorkflowVersion(raw.current_version) : null,
        createdAt: raw.created_at ?? undefined,
        updatedAt: raw.updated_at ?? undefined,
    }
}

function rawToWorkflowVersion(raw: RawWorkflowVersion): WorkflowVersion {
    return {
        id: raw.id,
        versionNumber: raw.version_number,
        definition: raw.definition,
        source: raw.source,
        changeSummary: raw.change_summary ?? null,
        rolledBackFromVersionId: raw.rolled_back_from_version_id ?? null,
        createdAt: raw.created_at ?? undefined,
    }
}

function rawToWorkflowRun(raw: RawWorkflowRun): WorkflowRun {
    return {
        id: raw.id,
        workflowId: raw.workflow_id,
        workflowVersionId: raw.workflow_version_id,
        workflowTriggerId: raw.workflow_trigger_id ?? null,
        status: raw.status,
        input: raw.input,
        timeoutMs: raw.timeout_ms ?? undefined,
        startedAt: raw.started_at ?? null,
        finishedAt: raw.finished_at ?? null,
        durationMs: raw.duration_ms ?? null,
        createdAt: raw.created_at ?? undefined,
        updatedAt: raw.updated_at ?? undefined,
        createdBy: raw.created_by ?? undefined,
        stepRuns: raw.step_runs?.map(rawToStepRun) ?? [],
        logs: raw.logs?.map(rawToExecutionLog) ?? [],
    }
}

function rawToAiFailureAnalysis(raw: RawAiFailureAnalysis): AiFailureAnalysis {
    return {
        id: raw.id,
        workflowRunId: raw.workflow_run_id,
        workflowStepRunId: raw.workflow_step_run_id,
        attemptCount: raw.attempt_count,
        rootCause: raw.root_cause,
        suggestedFix: raw.suggested_fix,
        confidence: raw.confidence,
        category: raw.category,
        evidence: raw.evidence,
        createdAt: raw.created_at ?? undefined,
    }
}
function rawToStepRun(raw: RawStepRun): StepRun {
    return {
        id: raw.id,
        stepId: raw.step_id,
        stepType: raw.step_type,
        status: raw.status,
        attemptCount: raw.attempt_count,
        maxAttempts: raw.max_attempts,
        startedAt: raw.started_at ?? undefined,
        finishedAt: raw.finished_at ?? undefined,
        durationMs: raw.duration_ms ?? undefined,
        output: raw.output,
        errorMessage: raw.error_message ?? undefined,
    }
}

function rawToExecutionLog(raw: RawExecutionLog): ExecutionLog {
    return {
        id: raw.id,
        workflowStepRunId: raw.workflow_step_run_id ?? undefined,
        level: raw.level,
        event: raw.event,
        message: raw.message,
        context: raw.context ?? undefined,
        createdAt: raw.created_at ?? undefined,
    }
}

function rawToHealthMetrics(raw: RawHealthMetrics): HealthMetrics {
    return {
        window: raw.window,
        generatedAt: raw.generated_at,
        activeRuns: raw.active_runs,
        totals: {
            runs: raw.totals.runs,
            success: raw.totals.success,
            failed: raw.totals.failed,
            timeout: raw.totals.timeout,
        },
        rates: {
            success: raw.rates.success,
            failure: raw.rates.failure,
            timeout: raw.rates.timeout,
        },
        averageDurationMs: raw.average_duration_ms,
        p95DurationMs: raw.p95_duration_ms,
    }
}
