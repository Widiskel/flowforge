<script setup lang="ts">
defineProps<{
    eyebrow?: string
    title: string
    subtitle?: string
}>()
</script>

<template>
    <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-md mb-lg">
        <div class="min-w-0">
            <p
                v-if="eyebrow"
                class="text-label-caps font-label-caps text-secondary uppercase tracking-wider mb-xs"
            >{{ eyebrow }}</p>
            <h1 class="text-headline-lg font-headline-lg text-on-surface m-0">{{ title }}</h1>
            <p
                v-if="subtitle"
                class="text-body-md font-body-md text-on-surface-variant mt-xs max-w-2xl"
            >{{ subtitle }}</p>
        </div>
        <div class="flex items-center gap-sm shrink-0">
            <slot name="actions" />
        </div>
    </div>
</template>
