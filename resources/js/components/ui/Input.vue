<script setup>
const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: "",
    },
    label: {
        type: String,
        default: "",
    },
    type: {
        type: String,
        default: "text",
    },
    placeholder: {
        type: String,
        default: "",
    },
});

const emit = defineEmits(["update:modelValue"]);
</script>

<template>
    <label class="grid gap-1.5">
        <span
            v-if="label"
            class="text-xs font-bold text-green-900"
        >
            {{ label }}
        </span>
        <input
            class="input"
            :type="type"
            :value="modelValue"
            :placeholder="placeholder"
            @input="emit('update:modelValue', $event.target.value)"
        />
    </label>
</template>
