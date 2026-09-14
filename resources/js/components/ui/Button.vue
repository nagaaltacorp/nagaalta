<script setup>
const props = defineProps({
    type: {
        type: String,
        default: "button",
    },
    variant: {
        type: String,
        default: "primary",
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const variants = {
    primary:
        "border-green-600 bg-green-600 text-white hover:border-green-700 hover:bg-green-700",
    secondary:
        "border-gray-300 bg-white text-green-900 hover:bg-gray-50",
    outline:
        "border-green-900 bg-white text-green-900 hover:bg-green-50",
    danger: "border-red-200 bg-red-100 text-red-800 hover:bg-red-200",
};
</script>

<template>
    <button
        class="inline-flex cursor-pointer items-center justify-center gap-2 rounded-sm border px-3.5 py-2 text-[13px] font-extrabold uppercase tracking-wide transition disabled:cursor-not-allowed disabled:opacity-60"
        :class="variants[props.variant] || variants.primary"
        :type="props.type"
        :disabled="props.disabled"
    >
        <slot />
    </button>
</template>
