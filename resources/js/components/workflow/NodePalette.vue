<script setup lang="ts">
import { computed, ref } from 'vue'
import Icon from '@/components/ui/Icon.vue'

export type StepType = 'HTTP' | 'SCRIPT' | 'DELAY' | 'CONDITION'

interface PaletteEntry {
    type: StepType
    label: string
    description: string
    icon: string
}

const entries: PaletteEntry[] = [
    { type: 'HTTP', label: 'HTTP Request', description: 'Call any URL — public APIs or the FlowForge playground.', icon: 'language' },
    { type: 'SCRIPT', label: 'Server Script', description: 'Run an allowlisted server-side operation.', icon: 'code' },
    { type: 'DELAY', label: 'Delay', description: 'Wait for a bounded duration before continuing.', icon: 'hourglass_top' },
    { type: 'CONDITION', label: 'Condition', description: 'Branch downstream steps based on an expression.', icon: 'fork_right' },
]

const search = ref('')

const filtered = computed(() =>
    entries.filter((entry) => entry.label.toLowerCase().includes(search.value.toLowerCase())),
)

defineEmits<{ (e: 'add', type: StepType): void }>()
</script>

<template>
    <aside class="w-full lg:w-64 shrink-0 flex flex-col bg-surface-container border border-outline-variant/30 rounded-xl overflow-hidden">
        <header class="px-md py-sm border-b border-outline-variant/30 bg-surface-container-high">
            <h3 class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0">Nodes Toolbar</h3>
        </header>
        <div class="p-sm flex flex-col gap-sm">
            <div class="relative">
                <Icon name="search" :size="16" class="absolute left-sm top-1/2 -translate-y-1/2 text-on-surface-variant" />
                <input
                    v-model="search"
                    placeholder="Search steps..."
                    class="input-dark w-full rounded-DEFAULT pl-9 pr-md py-1.5 text-body-sm"
                >
            </div>
            <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0 mt-xs">Step Types</p>
            <div class="flex flex-col gap-xs">
                <button
                    v-for="entry in filtered"
                    :key="entry.type"
                    type="button"
                    class="flex items-start gap-sm p-sm rounded-DEFAULT bg-surface-container-low border border-outline-variant/40 text-left hover:border-secondary/40 hover:bg-secondary/[0.04] transition-colors group"
                    @click="$emit('add', entry.type)"
                >
                    <span class="w-8 h-8 rounded-DEFAULT bg-secondary/10 text-secondary flex items-center justify-center shrink-0">
                        <Icon :name="entry.icon" :size="18" />
                    </span>
                    <span class="flex flex-col gap-0.5 min-w-0">
                        <span class="text-body-md font-bold text-on-surface">{{ entry.label }}</span>
                        <span class="text-body-sm text-on-surface-variant">{{ entry.description }}</span>
                    </span>
                </button>
            </div>
        </div>
    </aside>
</template>
