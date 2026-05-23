<script setup lang="ts">
import Icon from './Icon.vue'

withDefaults(
    defineProps<{
        icon?: string
        title?: string
        description?: string
        compact?: boolean
    }>(),
    { compact: false },
)
</script>

<template>
    <div
        :class="[
            'flex flex-col items-center justify-center text-center gap-sm border border-dashed border-outline-variant/60 rounded-DEFAULT bg-surface-container/50',
            compact ? 'p-md' : 'p-xl',
        ]"
    >
        <Icon
            v-if="icon"
            :name="icon"
            :size="compact ? 24 : 32"
            class="text-on-surface-variant/60"
        />
        <h3
            v-if="title"
            class="text-body-lg font-body-lg text-on-surface m-0"
        >{{ title }}</h3>
        <p
            v-if="description"
            class="text-body-sm font-body-sm text-on-surface-variant m-0 max-w-md"
        >{{ description }}</p>
        <slot />
    </div>
</template>
