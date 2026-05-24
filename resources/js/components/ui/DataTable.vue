<script setup lang="ts" generic="T">
import { computed, ref, watch } from 'vue'
import EmptyState from './EmptyState.vue'
import Icon from './Icon.vue'

export interface DataTableColumn<Row = unknown> {
    key: string
    label: string
    align?: 'left' | 'right' | 'center'
    /** Pixel min-width per column. Default 160. */
    minWidth?: number
    /** Whether the column header is clickable to sort. */
    sortable?: boolean
    /** Custom comparator. Falls back to comparing the value at `column.key`. */
    sortAccessor?: (row: Row) => string | number | null | undefined
}

const props = withDefaults(
    defineProps<{
        items: T[]
        columns: DataTableColumn<T>[]
        rowKey: (item: T) => string
        loading?: boolean
        emptyTitle?: string
        emptyDescription?: string
        emptyIcon?: string
        pageSize?: number
        pageSizeOptions?: number[]
        defaultSortKey?: string
        defaultSortDir?: 'asc' | 'desc'
    }>(),
    {
        loading: false,
        pageSize: 10,
        pageSizeOptions: () => [10, 25, 50, 100],
    },
)

const sortKey = ref<string | null>(props.defaultSortKey ?? null)
const sortDir = ref<'asc' | 'desc'>(props.defaultSortDir ?? 'asc')
const currentPage = ref(1)
const pageSizeRef = ref<number>(props.pageSize)

watch(
    () => props.items.length,
    () => {
        // Reset to page 1 when item count changes (filter applied, refresh, etc).
        currentPage.value = 1
    },
)

watch(
    () => props.pageSize,
    (val) => {
        pageSizeRef.value = val
    },
)

function toggleSort(column: DataTableColumn<T>) {
    if (column.sortable === false) return
    if (sortKey.value === column.key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
    } else {
        sortKey.value = column.key
        sortDir.value = 'asc'
    }
    currentPage.value = 1
}

const sortedItems = computed(() => {
    if (!sortKey.value) return props.items
    const column = props.columns.find((c) => c.key === sortKey.value)
    if (!column) return props.items
    const dirMultiplier = sortDir.value === 'asc' ? 1 : -1
    const accessor = column.sortAccessor ?? ((row: T) => (row as Record<string, unknown>)[column.key] as string | number | null | undefined)
    const copy = [...props.items]
    copy.sort((a, b) => {
        const av = accessor(a)
        const bv = accessor(b)
        if (av === null || av === undefined) return 1
        if (bv === null || bv === undefined) return -1
        if (typeof av === 'number' && typeof bv === 'number') return (av - bv) * dirMultiplier
        return String(av).localeCompare(String(bv), undefined, { numeric: true, sensitivity: 'base' }) * dirMultiplier
    })
    return copy
})

const totalPages = computed(() => Math.max(1, Math.ceil(sortedItems.value.length / pageSizeRef.value)))

watch(totalPages, (total) => {
    if (currentPage.value > total) currentPage.value = total
})

const pagedItems = computed(() => {
    const start = (currentPage.value - 1) * pageSizeRef.value
    return sortedItems.value.slice(start, start + pageSizeRef.value)
})

const rangeFrom = computed(() => (sortedItems.value.length === 0 ? 0 : (currentPage.value - 1) * pageSizeRef.value + 1))
const rangeTo = computed(() => Math.min(currentPage.value * pageSizeRef.value, sortedItems.value.length))

function goPrev() {
    if (currentPage.value > 1) currentPage.value -= 1
}

function goNext() {
    if (currentPage.value < totalPages.value) currentPage.value += 1
}

function goPage(p: number) {
    currentPage.value = Math.max(1, Math.min(totalPages.value, p))
}

function setPageSize(value: number) {
    pageSizeRef.value = value
    currentPage.value = 1
}

const pageNumbers = computed<(number | '…')[]>(() => {
    const total = totalPages.value
    const current = currentPage.value
    if (total <= 7) {
        return Array.from({ length: total }, (_, i) => i + 1)
    }
    const result: (number | '…')[] = [1]
    if (current > 3) result.push('…')
    const start = Math.max(2, current - 1)
    const end = Math.min(total - 1, current + 1)
    for (let i = start; i <= end; i++) result.push(i)
    if (current < total - 2) result.push('…')
    result.push(total)
    return result
})
</script>

<template>
    <div class="data-table">
        <div v-if="loading" class="data-table__placeholder">Loading…</div>
        <EmptyState
            v-else-if="items.length === 0"
            :icon="emptyIcon ?? 'inbox'"
            :title="emptyTitle ?? 'No items found'"
            :description="emptyDescription"
        />
        <template v-else>
            <div class="data-table__scroll">
                <table class="data-table__table">
                    <thead>
                        <tr>
                            <th
                                v-for="col in columns"
                                :key="col.key"
                                :style="{ minWidth: `${col.minWidth ?? 160}px` }"
                                :class="[
                                    'data-table__th',
                                    col.align === 'right' ? 'text-right' : col.align === 'center' ? 'text-center' : 'text-left',
                                    col.sortable === false ? '' : 'data-table__th--sortable',
                                    sortKey === col.key ? 'is-active' : '',
                                ]"
                                @click="toggleSort(col)"
                            >
                                <span class="inline-flex items-center gap-1.5">
                                    {{ col.label }}
                                    <span
                                        v-if="col.sortable !== false"
                                        class="data-table__sort"
                                        :class="{
                                            'is-asc': sortKey === col.key && sortDir === 'asc',
                                            'is-desc': sortKey === col.key && sortDir === 'desc',
                                        }"
                                    >
                                        <Icon
                                            :name="sortKey === col.key ? (sortDir === 'asc' ? 'arrow_upward' : 'arrow_downward') : 'unfold_more'"
                                            :size="14"
                                        />
                                    </span>
                                </span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in pagedItems"
                            :key="rowKey(item)"
                            class="data-table__row"
                        >
                            <td
                                v-for="col in columns"
                                :key="col.key"
                                :style="{ minWidth: `${col.minWidth ?? 160}px` }"
                                :class="[
                                    'data-table__td',
                                    col.align === 'right' ? 'text-right' : col.align === 'center' ? 'text-center' : 'text-left',
                                ]"
                            >
                                <slot :name="`cell-${col.key}`" :item="item" :column="col">
                                    {{ (item as Record<string, unknown>)[col.key] ?? '' }}
                                </slot>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <footer class="data-table__footer">
                <div class="flex items-center gap-sm text-body-sm text-on-surface-variant">
                    <span>Rows per page</span>
                    <select
                        :value="pageSizeRef"
                        class="input-dark rounded-DEFAULT px-sm py-1 text-body-sm tabular-nums"
                        @change="(e) => setPageSize(Number((e.target as HTMLSelectElement).value))"
                    >
                        <option v-for="opt in pageSizeOptions" :key="opt" :value="opt">{{ opt }}</option>
                    </select>
                </div>

                <div class="flex items-center gap-md text-body-sm text-on-surface-variant tabular-nums">
                    <span>{{ rangeFrom }}–{{ rangeTo }} of {{ sortedItems.length }}</span>
                    <div class="data-table__pages">
                        <button
                            type="button"
                            class="data-table__pgbtn"
                            :disabled="currentPage === 1"
                            aria-label="Previous page"
                            @click="goPrev"
                        >
                            <Icon name="chevron_left" :size="16" />
                        </button>
                        <button
                            v-for="(p, idx) in pageNumbers"
                            :key="`${p}-${idx}`"
                            type="button"
                            class="data-table__pgbtn"
                            :class="{ 'is-active': p === currentPage }"
                            :disabled="p === '…'"
                            @click="typeof p === 'number' && goPage(p)"
                        >{{ p }}</button>
                        <button
                            type="button"
                            class="data-table__pgbtn"
                            :disabled="currentPage === totalPages"
                            aria-label="Next page"
                            @click="goNext"
                        >
                            <Icon name="chevron_right" :size="16" />
                        </button>
                    </div>
                </div>
            </footer>
        </template>
    </div>
</template>

<style scoped>
.data-table {
    display: flex;
    flex-direction: column;
}

.data-table__placeholder {
    padding: 32px;
    text-align: center;
    color: var(--color-on-surface-variant);
}

.data-table__scroll {
    overflow-x: auto;
    overflow-y: visible;
}

.data-table__table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.data-table__th,
.data-table__td {
    padding: 12px 16px;
    border-bottom: 1px solid color-mix(in srgb, var(--color-outline-variant) 30%, transparent);
    white-space: nowrap;
    vertical-align: middle;
}

.data-table__th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: color-mix(in srgb, var(--color-surface-container-high) 80%, transparent);
    font-family: var(--font-label-caps);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--color-on-surface-variant);
    user-select: none;
}

.data-table__th--sortable {
    cursor: pointer;
    transition: color 0.15s ease, background 0.15s ease;
}

.data-table__th--sortable:hover {
    color: var(--color-on-surface);
    background: color-mix(in srgb, var(--color-surface-container-high) 95%, var(--color-secondary) 5%);
}

.data-table__th.is-active {
    color: var(--color-secondary);
}

.data-table__sort {
    color: color-mix(in srgb, var(--color-on-surface-variant) 60%, transparent);
    transition: color 0.15s ease;
}

.data-table__sort.is-asc,
.data-table__sort.is-desc {
    color: var(--color-secondary);
}

.data-table__td {
    font-size: 13px;
    color: var(--color-on-surface);
}

.data-table__row:hover .data-table__td {
    background: color-mix(in srgb, var(--color-secondary) 4%, transparent);
}

.data-table__footer {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 16px;
    border-top: 1px solid color-mix(in srgb, var(--color-outline-variant) 30%, transparent);
    background: color-mix(in srgb, var(--color-surface-container-low) 60%, transparent);
}

.data-table__pages {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    background: color-mix(in srgb, var(--color-surface-container-low) 80%, transparent);
    border: 1px solid color-mix(in srgb, var(--color-outline-variant) 40%, transparent);
    border-radius: var(--radius-DEFAULT);
    padding: 2px;
}

.data-table__pgbtn {
    min-width: 28px;
    height: 28px;
    padding: 0 8px;
    border: 0;
    background: transparent;
    color: var(--color-on-surface-variant);
    font-size: 12px;
    font-weight: 600;
    border-radius: 3px;
    cursor: pointer;
    transition: color 0.15s ease, background 0.15s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.data-table__pgbtn:hover:not(:disabled) {
    color: var(--color-on-surface);
    background: color-mix(in srgb, var(--color-surface-variant) 60%, transparent);
}

.data-table__pgbtn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.data-table__pgbtn.is-active {
    background: color-mix(in srgb, var(--color-secondary) 14%, transparent);
    color: var(--color-secondary);
}
</style>
