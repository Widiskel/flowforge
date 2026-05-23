import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const DashboardPage = () => import('@/pages/DashboardPage.vue')
const LoginPage = () => import('@/pages/LoginPage.vue')

export const router = createRouter({
    history: createWebHistory(),
    routes: [
        {
            path: '/',
            name: 'dashboard',
            component: DashboardPage,
            meta: { requiresAuth: true },
        },
        {
            path: '/login',
            name: 'login',
            component: LoginPage,
            meta: { guestOnly: true },
        },
        {
            path: '/:pathMatch(.*)*',
            redirect: { name: 'dashboard' },
        },
    ],
})

router.beforeEach(async (to) => {
    const auth = useAuthStore()

    if (!auth.bootstrapped) {
        await auth.bootstrap()
    }

    if (to.meta.requiresAuth && !auth.isAuthenticated) {
        return { name: 'login' }
    }

    if (to.meta.guestOnly && auth.isAuthenticated) {
        return { name: 'dashboard' }
    }

    return true
})
