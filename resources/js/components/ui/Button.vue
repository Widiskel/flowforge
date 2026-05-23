<script setup lang="ts">
import { computed } from 'vue'
import Icon from './Icon.vue'

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'icon'
type Size = 'sm' | 'md' | 'lg'

const props = withDefaults(
    defineProps<{
        variant?: Variant
        size?: Size
        type?: 'button' | 'submit' | 'reset'
        disabled?: boolean
        glow?: boolean
        leadingIcon?: string
        trailingIcon?: string
        iconFilled?: boolean
        ariaLabel?: string
    }>(),
    {
        variant: 'primary',
        size: 'md',
        type: 'button',
        disabled: false,
        glow: false,
        iconFilled: false,
    },
)

defineEmits<{ (e: 'click', payload: MouseEvent): void }>()

const sizeMap: Record<Size, string> = {
    sm: 'px-md py-1 text-label-caps',
    md: 'px-lg py-sm text-label-caps',
    lg: 'px-xl py-md text-body-md',
}

const iconSize = computed(() => (props.size === 'lg' ? 20 : 18))

const variantMap: Record<Variant, string> = {
    primary:
        'bg-secondary text-on-secondary hover:bg-secondary-fixed disabled:bg-secondary/40 disabled:text-on-secondary/60 font-bold uppercase tracking-wider',
    secondary:
        'bg-transparent text-on-surface border border-outline-variant hover:bg-surface-variant/40 hover:text-on-surface disabled:opacity-50 font-medium',
    ghost:
        'bg-transparent text-on-surface-variant hover:text-on-surface hover:bg-surface-variant/30 disabled:opacity-40',
    danger:
        'bg-transparent text-failed border border-failed/30 hover:bg-failed/10 hover:border-failed/60 disabled:opacity-50',
    icon:
        'bg-transparent text-on-surface-variant hover:text-on-surface hover:bg-surface-variant/40 disabled:opacity-40 rounded-full p-1.5',
}
</script>

<template>
    <button
        :type="type"
        :disabled="disabled"
        :aria-label="ariaLabel"
        :class="[
            'inline-flex items-center justify-center gap-sm rounded-DEFAULT transition-all duration-150 disabled:cursor-not-allowed select-none',
            variant === 'icon' ? '' : sizeMap[size],
            variantMap[variant],
            glow ? 'glow-active' : '',
        ]"
        @click="$emit('click', $event)"
    >
        <Icon v-if="leadingIcon" :name="leadingIcon" :size="iconSize" :filled="iconFilled" />
        <slot />
        <Icon v-if="trailingIcon" :name="trailingIcon" :size="iconSize" :filled="iconFilled" />
    </button>
</template>
