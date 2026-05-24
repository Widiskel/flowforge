<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import GlassPanel from '@/components/ui/GlassPanel.vue'
import Icon from '@/components/ui/Icon.vue'
import Logo from '@/components/ui/Logo.vue'
import Button from '@/components/ui/Button.vue'
import Alert from '@/components/ui/Alert.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const form = reactive({
    email: 'editor@flowforge.test',
    password: 'password',
})
const submitting = ref(false)
const showPassword = ref(false)
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
    <main class="min-h-screen relative overflow-hidden bg-background flex items-center justify-center p-md">
        <div class="absolute inset-0 bg-grid-pattern opacity-40 pointer-events-none" />
        <div
            class="absolute inset-0 pointer-events-none"
            style="background: radial-gradient(circle at center, transparent 0%, var(--color-background) 100%);"
        />

        <GlassPanel
            radius="xl"
            class="relative z-10 w-full p-lg flex flex-col gap-lg shadow-2xl"
            style="max-width: 28rem; min-width: min(28rem, calc(100vw - 2rem));"
        >
            <div class="flex flex-col items-center gap-sm text-center">
                <Logo :size="56" rounded="lg" class="glow-active" />
                <h1 class="text-headline-md font-headline-md text-on-surface m-0">Sign in to FlowForge</h1>
                <p class="text-body-sm font-body-sm text-on-surface-variant m-0">
                    Multi-tenant workflow orchestration. JWT bearer access, tenant-scoped data.
                </p>
            </div>

            <form class="flex flex-col gap-md" @submit.prevent="submit">
                <div class="flex flex-col gap-xs">
                    <label
                        for="email"
                        class="text-label-caps font-label-caps text-on-surface-variant uppercase tracking-wider"
                    >Email address</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        required
                        class="input-dark rounded-md px-md py-sm font-code-md text-code-md w-full"
                        placeholder="admin@flowforge.test"
                    >
                </div>

                <div class="flex flex-col gap-xs">
                    <div class="flex items-center justify-between">
                        <label
                            for="password"
                            class="text-label-caps font-label-caps text-on-surface-variant uppercase tracking-wider"
                        >Password</label>
                    </div>
                    <div class="relative">
                        <input
                            id="password"
                            v-model="form.password"
                            :type="showPassword ? 'text' : 'password'"
                            autocomplete="current-password"
                            required
                            class="input-dark rounded-md px-md py-sm font-code-md text-code-md w-full pr-10"
                            placeholder="••••••••"
                        >
                        <button
                            type="button"
                            aria-label="Toggle password visibility"
                            class="absolute inset-y-0 right-0 flex items-center pr-sm text-on-surface-variant hover:text-on-surface transition-colors"
                            @click="showPassword = !showPassword"
                        >
                            <Icon :name="showPassword ? 'visibility_off' : 'visibility'" :size="20" />
                        </button>
                    </div>
                </div>

                <Alert v-if="loginError" tone="error" compact>
                    {{ loginError }}
                </Alert>

                <Button
                    type="submit"
                    :disabled="submitting"
                    glow
                    trailing-icon="arrow_forward"
                    class="mt-sm w-full"
                >
                    {{ submitting ? 'Authenticating…' : 'Sign in' }}
                </Button>
            </form>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-sm pt-sm border-t border-outline-variant/30">
                <div class="text-center">
                    <p class="text-label-caps font-label-caps text-on-surface m-0 mb-1">Admin</p>
                    <p class="text-body-sm font-body-sm text-on-surface-variant m-0">full control</p>
                </div>
                <div class="text-center border-l border-r border-outline-variant/30">
                    <p class="text-label-caps font-label-caps text-on-surface m-0 mb-1">Editor</p>
                    <p class="text-body-sm font-body-sm text-on-surface-variant m-0">trigger runs</p>
                </div>
                <div class="text-center">
                    <p class="text-label-caps font-label-caps text-on-surface m-0 mb-1">Viewer</p>
                    <p class="text-body-sm font-body-sm text-on-surface-variant m-0">read only</p>
                </div>
            </div>

            <p class="text-body-sm font-body-sm text-on-surface-variant text-center m-0 text-balance">
                Demo accounts seeded by <code class="font-code-sm">DatabaseSeeder</code>. Default form is the Editor account.
            </p>
        </GlassPanel>
    </main>
</template>
