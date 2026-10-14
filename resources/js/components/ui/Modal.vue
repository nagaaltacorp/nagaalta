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
    landscape: {
        type: Boolean,
        default: false,
    },
    fill: {
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
                class="flex min-h-0 w-full flex-col border-2 border-green-900 bg-white shadow-xl"
                :class="[
                    landscape
                        ? 'max-w-[1040px]'
                        : wide
                          ? 'max-w-[760px]'
                          : 'max-w-[560px]',
                    fill
                        ? 'h-[min(88vh,720px)] max-h-[min(88vh,720px)]'
                        : 'max-h-[min(88vh,860px)]',
                ]"
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
                <div
                    class="min-h-0 flex-1 p-4"
                    :class="
                        fill
                            ? 'flex flex-col overflow-hidden'
                            : 'scrollbar-page overflow-auto'
                    "
                >
                    <slot />
                </div>
            </div>
        </div>
    </Teleport>
</template>
