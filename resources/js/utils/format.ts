export function formatDuration(ms: number | null | undefined): string {
    if (ms === null || ms === undefined || ms <= 0) return '—'
    if (ms < 1000) return `${ms}ms`
    if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`
    const minutes = Math.floor(ms / 60000)
    const seconds = Math.floor((ms % 60000) / 1000)
    return `${minutes}m ${seconds.toString().padStart(2, '0')}s`
}

export function formatRelativeTime(value?: string | null): string {
    if (!value) return 'Unknown'
    const diff = Date.now() - new Date(value).getTime()
    if (diff < 0) return 'Just now'
    if (diff < 60_000) return 'Just now'
    if (diff < 3_600_000) return `${Math.floor(diff / 60_000)}m ago`
    if (diff < 86_400_000) return `${Math.floor(diff / 3_600_000)}h ago`
    return new Date(value).toLocaleDateString()
}

export function formatDateTime(value?: string | null): string {
    if (!value) return '—'
    return new Date(value).toLocaleString()
}

export function formatTime(value?: string | null): string {
    if (!value) return ''
    return new Date(value).toLocaleTimeString('en-US', { hour12: false })
}

export function formatPercent(value: number | null | undefined, digits = 1): string {
    if (value === null || value === undefined) return '—'
    return `${value.toFixed(digits)}%`
}
