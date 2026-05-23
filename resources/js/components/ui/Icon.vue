<script setup lang="ts">
defineProps<{
    name: string
    size?: number | string
    filled?: boolean
}>()
</script>

<template>
    <span
        class="material-symbols-outlined"
        :class="{ filled }"
        :style="size ? { fontSize: typeof size === 'number' ? `${size}px` : size } : undefined"
        :data-icon="name"
        aria-hidden="true"
    >{{ name }}</span>
</template>
