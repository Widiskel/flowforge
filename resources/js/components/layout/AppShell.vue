<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import SideNav from './SideNav.vue'
import TopBar from './TopBar.vue'

const route = useRoute()

// Some pages (e.g. workflow builder) need a full-bleed canvas without padding.
const fullBleed = computed(() => route.meta?.fullBleed === true)
</script>

<template>
    <div class="min-h-screen bg-background text-on-surface flex">
        <SideNav />
        <div class="flex-1 flex flex-col min-w-0 md:ml-64">
            <TopBar />
            <main
                class="flex-1 overflow-y-auto"
                :class="fullBleed ? '' : 'p-md md:p-lg'"
            >
                <slot />
            </main>
        </div>
    </div>
</template>
