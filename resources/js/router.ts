import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const DashboardPage = () => import('@/pages/DashboardPage.vue')
const LoginPage = () => import('@/pages/LoginPage.vue')
const WorkflowBuilderPage = () => import('@/pages/WorkflowBuilderPage.vue')
const WorkflowListPage = () => import('@/pages/WorkflowListPage.vue')
const RunsPage = () => import('@/pages/RunsPage.vue')
const SettingsPage = () => import('@/pages/SettingsPage.vue')

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
            path: '/workflows',
            name: 'workflows',
            redirect: { name: 'workflows.list' },
            meta: { requiresAuth: true },
        },
        {
            path: '/workflows/list',
            name: 'workflows.list',
            component: WorkflowListPage,
            meta: { requiresAuth: true },
        },
        {
            path: '/workflows/builder',
            name: 'workflows.builder',
            component: WorkflowBuilderPage,
            meta: { requiresAuth: true, fullBleed: true },
        },
        {
            path: '/runs',
            name: 'runs',
            component: RunsPage,
            meta: { requiresAuth: true },
        },
        {
            path: '/settings',
            name: 'settings',
            component: SettingsPage,
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
