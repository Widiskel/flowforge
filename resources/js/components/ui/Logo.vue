<script setup lang="ts">
withDefaults(
    defineProps<{
        size?: number | string
        rounded?: 'sm' | 'md' | 'lg' | 'full'
        bordered?: boolean
        ariaLabel?: string
    }>(),
    {
        size: 32,
        rounded: 'sm',
        bordered: true,
        ariaLabel: 'FlowForge logo',
    },
)

const logoSrc = '/images/flowforge-logo.jpg'
</script>

<template>
    <div
        :class="[
            'overflow-hidden flex items-center justify-center bg-primary-container shrink-0',
            bordered ? 'border border-outline-variant/40' : '',
            rounded === 'sm' ? 'rounded-DEFAULT' : rounded === 'md' ? 'rounded-lg' : rounded === 'lg' ? 'rounded-xl' : 'rounded-full',
        ]"
        :style="{ width: typeof size === 'number' ? `${size}px` : size, height: typeof size === 'number' ? `${size}px` : size }"
        :aria-label="ariaLabel"
    >
        <img
            :src="logoSrc"
            alt="FlowForge"
            class="w-full h-full object-cover"
            loading="eager"
            decoding="async"
        >
    </div>
</template>
