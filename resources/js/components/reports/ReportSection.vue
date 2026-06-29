<template>
    <div class="w-full rounded-xl border border-gray-700 bg-[#111827]">
        <div
            v-if="$slots.header"
            class="border-b border-gray-700 px-5 py-4"
        >
            <slot name="header" />
        </div>

        <div :class="$slots.header ? 'p-0' : 'p-4'">
            <slot />
        </div>
    </div>
</template>