<script setup lang="ts">
import { RouterView, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function logout(): Promise<void> {
    await auth.logout()
    await router.push({ name: 'login' })
}
</script>

<template>
    <main class="app-shell">
        <div class="aurora aurora-one" />
        <div class="aurora aurora-two" />
        <div class="grid-noise" />

        <div class="app-frame">
            <header class="topbar">
                <div class="brand-lockup">
                    <div class="brand-mark">FF</div>
                    <div>
                        <p class="eyebrow">FlowForge</p>
                        <h1>Workflow Command Center</h1>
                    </div>
                </div>

                <div v-if="auth.user" class="topbar-user">
                    <div class="user-card">
                        <span>{{ auth.user.name }}</span>
                        <small>{{ auth.user.role }} · {{ auth.user.tenant?.slug }}</small>
                    </div>
                    <button type="button" class="ghost-button" @click="logout">
                        Logout
                    </button>
                </div>
            </header>

            <RouterView />
        </div>
    </main>
</template>
