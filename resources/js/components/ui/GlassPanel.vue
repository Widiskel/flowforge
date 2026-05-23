<script setup lang="ts">
withDefaults(
    defineProps<{
        as?: string
        radius?: 'lg' | 'xl' | 'full'
        padded?: boolean
        clamp?: boolean
    }>(),
    {
        as: 'div',
        radius: 'xl',
        padded: false,
        clamp: false,
    },
)
</script>

<template>
    <component
        :is="as"
        :class="[
            'glass-panel',
            radius === 'lg' ? 'rounded-lg' : radius === 'xl' ? 'rounded-xl' : 'rounded-full',
            padded ? 'p-md' : '',
            clamp ? 'overflow-hidden' : '',
        ]"
    >
        <slot />
    </component>
</template>
