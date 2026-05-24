<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue'
import Icon from './Icon.vue'

const props = withDefaults(
    defineProps<{
        open: boolean
        title?: string
        subtitle?: string
        width?: 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '4xl' | '6xl'
        dismissOnBackdrop?: boolean
    }>(),
    { width: '2xl', dismissOnBackdrop: true },
)

const emit = defineEmits<{ (e: 'close'): void }>()

const widthMap: Record<NonNullable<typeof props.width>, string> = {
    sm: 'max-w-md',
    md: 'max-w-lg',
    lg: 'max-w-2xl',
    xl: 'max-w-3xl',
    '2xl': 'max-w-4xl',
    '4xl': 'max-w-5xl',
    '6xl': 'max-w-6xl',
}

function handleBackdrop() {
    if (props.dismissOnBackdrop) emit('close')
}

function handleEscape(event: KeyboardEvent) {
    if (event.key === 'Escape' && props.open) emit('close')
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            document.body.style.overflow = 'hidden'
            window.addEventListener('keydown', handleEscape)
        } else {
            document.body.style.overflow = ''
            window.removeEventListener('keydown', handleEscape)
        }
    },
    { immediate: true },
)

onBeforeUnmount(() => {
    document.body.style.overflow = ''
    window.removeEventListener('keydown', handleEscape)
})
</script>

<template>
    <Teleport to="body">
        <Transition name="modal">
            <div v-if="open" class="fixed inset-0 z-[200] flex items-center justify-center p-md md:p-xl" role="dialog" aria-modal="true">
                <div
                    class="absolute inset-0 bg-background/80 backdrop-blur-sm"
                    @click="handleBackdrop"
                />
                <div
                    :class="[
                        'relative w-full bg-surface-container border border-outline-variant rounded-xl shadow-2xl flex flex-col max-h-[90vh] overflow-hidden',
                        widthMap[width],
                    ]"
                >
                    <header
                        v-if="title || $slots.header"
                        class="flex items-start justify-between gap-md px-lg py-md border-b border-outline-variant bg-surface-container-high"
                    >
                        <div v-if="title" class="min-w-0 flex-1">
                            <h2 class="text-headline-md font-headline-md text-on-surface m-0">{{ title }}</h2>
                            <p
                                v-if="subtitle"
                                class="text-body-sm font-body-sm text-on-surface-variant mt-xs"
                            >{{ subtitle }}</p>
                        </div>
                        <slot name="header" />
                        <button
                            class="text-on-surface-variant hover:text-on-surface transition-colors p-1 rounded-DEFAULT shrink-0"
                            type="button"
                            aria-label="Close"
                            @click="emit('close')"
                        >
                            <Icon name="close" :size="20" />
                        </button>
                    </header>
                    <div class="flex-1 overflow-y-auto">
                        <slot />
                    </div>
                    <footer
                        v-if="$slots.footer"
                        class="px-lg py-md border-t border-outline-variant bg-surface-container-high flex items-center justify-end gap-sm"
                    >
                        <slot name="footer" />
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.18s ease;
}
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
</style>
