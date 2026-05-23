<script setup lang="ts" generic="T extends string">
withDefaults(
    defineProps<{
        modelValue: T
        items: { value: T; label: string; icon?: string; badge?: string }[]
        underline?: boolean
    }>(),
    { underline: true },
)

defineEmits<{ (e: 'update:modelValue', value: T): void }>()
</script>

<template>
    <div
        :class="[
            'flex items-end gap-1',
            underline ? 'border-b border-outline-variant/30' : '',
        ]"
    >
        <button
            v-for="tab in items"
            :key="tab.value"
            type="button"
            :class="[
                'px-md py-sm text-body-sm font-bold transition-all duration-150 inline-flex items-center gap-sm',
                modelValue === tab.value
                    ? 'text-secondary border-b-2 border-secondary -mb-px'
                    : 'text-on-surface-variant hover:text-on-surface border-b-2 border-transparent -mb-px',
            ]"
            @click="$emit('update:modelValue', tab.value)"
        >
            <span>{{ tab.label }}</span>
            <span
                v-if="tab.badge"
                class="px-1.5 py-0.5 rounded-full bg-surface-variant text-on-surface-variant text-[10px] font-bold"
            >{{ tab.badge }}</span>
        </button>
    </div>
</template>
