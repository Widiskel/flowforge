<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '@/components/ui/Icon.vue'
import Logo from '@/components/ui/Logo.vue'
import StatusPill from '@/components/ui/StatusPill.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const userInitials = computed(() => {
    const name = auth.user?.name ?? ''
    const parts = name.trim().split(/\s+/)
    if (parts.length === 0) return 'FF'
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
})

async function handleLogout(): Promise<void> {
    await auth.logout()
    await router.push({ name: 'login' })
}
</script>

<template>
    <header
        class="flex justify-between items-center w-full px-md md:px-lg h-16 bg-surface/80 backdrop-blur-md border-b border-outline-variant/30 sticky top-0 z-40 shrink-0"
    >
        <div class="flex items-center gap-md">
            <div class="flex items-center gap-sm">
                <Logo :size="32" rounded="md" />
                <span class="text-headline-sm font-headline-sm font-bold text-on-surface tracking-tight">FlowForge</span>
            </div>
        </div>
        <div class="flex items-center gap-md">
            <StatusPill label="System Status" tone="running" />
            <button
                class="hidden md:inline-flex items-center justify-center w-8 h-8 rounded-full text-on-surface-variant hover:text-on-surface hover:bg-surface-variant/40 transition-colors"
                aria-label="Notifications"
                type="button"
            >
                <Icon name="notifications" :size="20" />
            </button>
            <button
                class="hidden md:inline-flex items-center justify-center w-8 h-8 rounded-full text-on-surface-variant hover:text-on-surface hover:bg-surface-variant/40 transition-colors"
                aria-label="Help"
                type="button"
            >
                <Icon name="help_outline" :size="20" />
            </button>
            <div v-if="auth.user" class="flex items-center gap-sm pl-sm border-l border-outline-variant/30">
                <div class="hidden md:flex flex-col items-end leading-none">
                    <span class="text-body-sm font-bold text-on-surface">{{ auth.user.name }}</span>
                    <span class="text-label-caps font-label-caps text-on-surface-variant uppercase">{{ auth.user.role }}</span>
                </div>
                <div class="w-8 h-8 rounded-full bg-surface-variant overflow-hidden border border-outline-variant flex items-center justify-center text-body-sm font-bold text-on-surface">
                    {{ userInitials }}
                </div>
                <button
                    class="inline-flex items-center justify-center w-8 h-8 rounded-full text-on-surface-variant hover:text-failed hover:bg-failed/10 transition-colors"
                    aria-label="Sign out"
                    type="button"
                    @click="handleLogout"
                >
                    <Icon name="logout" :size="18" />
                </button>
            </div>
        </div>
    </header>
</template>
