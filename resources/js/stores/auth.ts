import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import {
    currentUser,
    login as apiLogin,
    logout as apiLogout,
    refreshToken as apiRefreshToken,
    setAccessTokenProvider,
} from '@/services/api/client'
import type { AuthTokenPair, AuthUser } from '@/types/api'

const ACCESS_TOKEN_KEY = 'flowforge.access_token'
const REFRESH_TOKEN_KEY = 'flowforge.refresh_token'

export const useAuthStore = defineStore('auth', () => {
    const user = ref<AuthUser | null>(null)
    const loading = ref(false)
    const bootstrapped = ref(false)
    const error = ref<string | null>(null)
    const accessToken = ref<string | null>(localStorage.getItem(ACCESS_TOKEN_KEY))
    const refreshTokenValue = ref<string | null>(localStorage.getItem(REFRESH_TOKEN_KEY))

    setAccessTokenProvider(() => accessToken.value)

    const isAuthenticated = computed(() => accessToken.value !== null)
    const canTrigger = computed(() => user.value?.role === 'admin' || user.value?.role === 'editor')

    function persistTokens(tokens: AuthTokenPair | null): void {
        accessToken.value = tokens?.accessToken ?? null
        refreshTokenValue.value = tokens?.refreshToken ?? null

        if (tokens === null) {
            localStorage.removeItem(ACCESS_TOKEN_KEY)
            localStorage.removeItem(REFRESH_TOKEN_KEY)
            return
        }

        localStorage.setItem(ACCESS_TOKEN_KEY, tokens.accessToken)
        localStorage.setItem(REFRESH_TOKEN_KEY, tokens.refreshToken)
    }

    async function bootstrap(): Promise<void> {
        loading.value = true
        error.value = null

        try {
            if (refreshTokenValue.value !== null && accessToken.value === null) {
                const refreshed = await apiRefreshToken(refreshTokenValue.value)
                persistTokens(refreshed)
            }

            if (accessToken.value !== null) {
                user.value = await currentUser()
            }
        } catch (exception) {
            persistTokens(null)
            user.value = null
            error.value = exception instanceof Error ? exception.message : 'Unable to restore session.'
        } finally {
            loading.value = false
            bootstrapped.value = true
        }
    }

    async function login(email: string, password: string): Promise<void> {
        loading.value = true
        error.value = null

        try {
            const { tokens, user: signedIn } = await apiLogin(email, password)
            persistTokens(tokens)
            user.value = signedIn
        } catch (exception) {
            persistTokens(null)
            user.value = null
            error.value = exception instanceof Error ? exception.message : 'Login failed.'
            throw exception
        } finally {
            loading.value = false
        }
    }

    async function logout(): Promise<void> {
        loading.value = true
        error.value = null

        try {
            if (refreshTokenValue.value !== null) {
                await apiLogout(refreshTokenValue.value)
            }
        } finally {
            persistTokens(null)
            user.value = null
            loading.value = false
        }
    }

    return {
        user,
        loading,
        bootstrapped,
        error,
        accessToken,
        refreshToken: refreshTokenValue,
        isAuthenticated,
        canTrigger,
        bootstrap,
        login,
        logout,
    }
})
