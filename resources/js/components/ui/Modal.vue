<script setup>
defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: "",
    },
    wide: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(["close"]);
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="scrollbar-page fixed inset-0 z-[400] grid place-items-center overflow-auto bg-slate-900/60 p-5"
            @click.self="emit('close')"
        >
            <div
                class="flex max-h-[min(88vh,860px)] min-h-0 w-full flex-col border-2 border-green-900 bg-white shadow-xl"
                :class="wide ? 'max-w-[760px]' : 'max-w-[560px]'"
            >
                <header
                    class="flex shrink-0 items-center justify-between bg-green-900 px-4 py-3.5"
                >
                    <h3 class="m-0 text-lg font-semibold text-white">
                        {{ title }}
                    </h3>
                    <button
                        class="cursor-pointer border-0 bg-transparent px-1 text-2xl leading-none text-green-100"
                        type="button"
                        aria-label="Close"
                        @click="emit('close')"
                    >
                        ×
                    </button>
                </header>
                <div class="scrollbar-page min-h-0 flex-1 overflow-auto p-4">
                    <slot />
                </div>
            </div>
        </div>
    </Teleport>
</template>
