<script setup lang="ts" generic="T">
import EmptyState from './EmptyState.vue'

defineProps<{
    items: T[]
    columns: { key: string; label: string; align?: 'left' | 'right' | 'center'; width?: string }[]
    rowKey: (item: T) => string
    loading?: boolean
    emptyTitle?: string
    emptyDescription?: string
    emptyIcon?: string
}>()
</script>

<template>
    <div class="overflow-x-auto">
        <div v-if="loading" class="p-xl text-center text-on-surface-variant">Loading…</div>
        <EmptyState
            v-else-if="items.length === 0"
            :icon="emptyIcon ?? 'inbox'"
            :title="emptyTitle ?? 'No items found'"
            :description="emptyDescription"
        />
        <table v-else class="w-full border-collapse">
            <thead>
                <tr>
                    <th
                        v-for="col in columns"
                        :key="col.key"
                        :style="col.width ? { width: col.width } : undefined"
                        :class="[
                            'px-md py-sm text-label-caps font-label-caps text-on-surface-variant uppercase border-b border-outline-variant/30 bg-surface-container-high/40 sticky top-0',
                            col.align === 'right' ? 'text-right' : col.align === 'center' ? 'text-center' : 'text-left',
                        ]"
                    >{{ col.label }}</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="item in items"
                    :key="rowKey(item)"
                    class="border-b border-outline-variant/20 hover:bg-secondary/[0.04] transition-colors"
                >
                    <td
                        v-for="col in columns"
                        :key="col.key"
                        :class="[
                            'px-md py-sm text-body-sm align-middle',
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
</template>
