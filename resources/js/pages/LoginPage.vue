<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const form = reactive({
    email: 'editor@flowforge.test',
    password: 'password',
})
const submitting = ref(false)
const loginError = ref<string | null>(null)

async function submit(): Promise<void> {
    submitting.value = true
    loginError.value = null

    try {
        await auth.login(form.email, form.password)
        await router.push({ name: 'dashboard' })
    } catch (exception) {
        loginError.value = exception instanceof Error ? exception.message : 'Login failed.'
    } finally {
        submitting.value = false
    }
}
</script>

<template>
    <section class="login-shell">
        <div class="login-card">
            <div class="login-hero">
                <div class="hero-orbit orbit-a" />
                <div class="hero-orbit orbit-b" />
                <p class="eyebrow">Secure tenant cockpit</p>
                <h2>Workflow ops, without tenant bleed.</h2>
                <p>
                    JWT bearer login opens a tenant-scoped command center for workflow DAGs, realtime runs, logs, metrics, and failure analysis.
                </p>
                <div class="role-grid">
                    <div><strong>Admin</strong><span>full control</span></div>
                    <div><strong>Editor</strong><span>trigger runs</span></div>
                    <div><strong>Viewer</strong><span>read only</span></div>
                </div>
            </div>

            <form class="login-form" @submit.prevent="submit">
                <p class="eyebrow">Demo access</p>
                <h3>Masuk ke dashboard</h3>
                <p class="form-hint">
                    Demo user tersedia dari database seeder. Default form memakai Editor; bisa diganti ke Admin atau Viewer untuk cek RBAC.
                </p>

                <label class="field-label">
                    Email
                    <input v-model="form.email" type="email" autocomplete="email">
                </label>

                <label class="field-label">
                    Password
                    <input v-model="form.password" type="password" autocomplete="current-password">
                </label>

                <p v-if="loginError" class="error-banner">
                    {{ loginError }}
                </p>

                <button type="submit" :disabled="submitting" class="primary-button wide">
                    {{ submitting ? 'Authenticating…' : 'Open dashboard' }}
                </button>
            </form>
        </div>
    </section>
</template>
