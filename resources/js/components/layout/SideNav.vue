<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import Icon from '@/components/ui/Icon.vue'
import Logo from '@/components/ui/Logo.vue'
import { useAuthStore } from '@/stores/auth'

interface NavItem {
    name: string
    label: string
    icon: string
    matches?: string[]
    children?: { name: string; label: string }[]
}

const auth = useAuthStore()
const route = useRoute()

const primary: NavItem[] = [
    { name: 'dashboard', label: 'Dashboard', icon: 'dashboard' },
    {
        name: 'workflows.list',
        label: 'Workflows',
        icon: 'account_tree',
        matches: ['workflows.list', 'workflows.builder'],
    },
    { name: 'runs', label: 'Runs', icon: 'play_arrow' },
    { name: 'settings', label: 'Settings', icon: 'settings' },
]

const footer: { href: string; label: string; icon: string }[] = [
    { href: '#', label: 'Documentation', icon: 'menu_book' },
    { href: 'mailto:support@flowforge.test', label: 'Support', icon: 'contact_support' },
]

const tenantName = computed(() => auth.user?.tenant?.name ?? 'FlowForge')
const tenantSlug = computed(() => auth.user?.tenant?.slug ?? 'primary tenant')

function isActive(item: NavItem): boolean {
    const candidates = item.matches ?? [item.name]
    return candidates.includes(String(route.name ?? ''))
}

function isChildActive(name: string): boolean {
    return route.name === name
}
</script>

<template>
    <nav
        class="hidden md:flex flex-col h-screen w-64 fixed left-0 top-0 z-50 bg-surface-container border-r border-outline-variant/30 py-lg"
    >
        <div class="px-md mb-lg">
            <div class="flex items-center gap-sm">
                <Logo :size="36" rounded="md" />
                <div class="min-w-0">
                    <h2 class="text-headline-sm font-headline-sm font-bold text-on-surface m-0 truncate">
                        {{ tenantName }}
                    </h2>
                    <p class="text-label-caps font-label-caps text-on-surface-variant uppercase m-0 truncate">
                        {{ tenantSlug }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex-1 px-sm flex flex-col gap-xs overflow-y-auto">
            <template v-for="item in primary" :key="item.name">
                <RouterLink
                    :to="{ name: item.name }"
                    custom
                    v-slot="{ href, navigate }"
                >
                    <a
                        :href="href"
                        :class="[
                            'flex items-center gap-md px-md py-sm rounded-DEFAULT text-label-caps font-label-caps uppercase transition-all duration-150',
                            isActive(item)
                                ? 'text-secondary bg-secondary/10 border-r-2 border-secondary'
                                : 'text-on-surface-variant hover:text-on-surface hover:bg-surface-variant/30 border-r-2 border-transparent',
                        ]"
                        @click="navigate"
                    >
                        <Icon :name="item.icon" :size="20" :filled="isActive(item)" />
                        <span>{{ item.label }}</span>
                    </a>
                </RouterLink>
                <div v-if="item.children && isActive(item)" class="ml-[44px] flex flex-col gap-xs mb-xs">
                    <RouterLink
                        v-for="child in item.children"
                        :key="child.name"
                        :to="{ name: child.name }"
                        custom
                        v-slot="{ href, navigate }"
                    >
                        <a
                            :href="href"
                            :class="[
                                'pl-md py-1 text-body-sm transition-colors border-l-2',
                                isChildActive(child.name)
                                    ? 'text-secondary border-secondary bg-secondary/5'
                                    : 'text-on-surface-variant hover:text-on-surface border-transparent hover:border-outline-variant',
                            ]"
                            @click="navigate"
                        >{{ child.label }}</a>
                    </RouterLink>
                </div>
            </template>
        </div>

        <div class="px-sm pt-md mt-auto border-t border-outline-variant/30 flex flex-col gap-xs">
            <a
                v-for="item in footer"
                :key="item.href"
                :href="item.href"
                class="flex items-center gap-md px-md py-sm rounded-DEFAULT text-label-caps font-label-caps uppercase text-on-surface-variant hover:text-on-surface hover:bg-surface-variant/30 transition-all duration-150"
            >
                <Icon :name="item.icon" :size="20" />
                <span>{{ item.label }}</span>
            </a>
        </div>
    </nav>
</template>
