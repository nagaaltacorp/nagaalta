<script setup>
defineProps({
    title: {
        type: String,
        default: "",
    },
});
</script>

<template>
    <section class="overflow-hidden border border-gray-300 bg-white">
        <header
            v-if="title || $slots.header"
            class="border-b border-gray-200 bg-white px-4 py-3"
        >
            <slot name="header">
                <h3
                    class="m-0 text-xs font-extrabold uppercase tracking-wider text-green-900"
                >
                    {{ title }}
                </h3>
            </slot>
        </header>
        <div class="bg-white p-4">
            <slot />
        </div>
    </section>
</template>
