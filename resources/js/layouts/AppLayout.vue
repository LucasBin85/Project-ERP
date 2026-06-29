<script setup lang="ts">
import AppLayout from '@/layouts/app/AppSidebarLayout.vue'
import type { BreadcrumbItemType } from '@/types'
import { Head } from '@inertiajs/vue3'

interface Props {
    title?: string
    breadcrumbs?: BreadcrumbItemType[]
}

withDefaults(defineProps<Props>(), {
    title: '',
    breadcrumbs: () => [],
})
</script>

<template>
    <Head
        v-if="title"
        :title="title"
    />

    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
    </AppLayout>
</template>