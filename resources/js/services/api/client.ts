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
    return request<ApiCollection<Workflow>>('/api/workflows')
}

export async function workflowRuns(workflowId: string): Promise<ApiCollection<WorkflowRun>> {
    return request<ApiCollection<WorkflowRun>>(`/api/workflows/${workflowId}/runs`)
}

export async function healthMetrics(): Promise<HealthMetrics> {
    const response = await request<ApiItem<HealthMetrics>>('/api/health/metrics?window=last_24h')

    return response.data
}

export async function runLogs(workflowId: string, runId: string): Promise<ApiCollection<ExecutionLog>> {
    return request<ApiCollection<ExecutionLog>>(`/api/workflows/${workflowId}/runs/${runId}/logs`)
}

export async function analyzeFailure(workflowId: string, runId: string): Promise<AiFailureAnalysis> {
    const response = await request<ApiItem<AiFailureAnalysis>>(`/api/workflows/${workflowId}/runs/${runId}/analyze-failure`, {
        method: 'POST',
    })

    return response.data
}
