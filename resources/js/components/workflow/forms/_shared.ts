export interface BuilderStep {
    id: string
    name: string
    type: 'HTTP' | 'SCRIPT' | 'DELAY' | 'CONDITION'
    dependsOn: string[]
    config: Record<string, unknown>
    timeoutMs?: number
    retry?: {
        maxAttempts?: number
        backoff?: 'exponential' | 'fixed' | string
        initialDelayMs?: number
        maxDelayMs?: number
    }
    notes?: string
    displayNoteInFlow?: boolean
    position?: { x: number; y: number }
}

/** Reactively coerce a config value with a typed getter/setter. */
export function configValue<T>(step: BuilderStep, key: string, fallback: T): T {
    const value = step.config[key]
    return (value === undefined || value === null ? fallback : (value as T))
}

export function setConfig(step: BuilderStep, key: string, value: unknown): void {
    if (value === '' || value === null || value === undefined) {
        delete step.config[key]
    } else {
        step.config[key] = value
    }
}
