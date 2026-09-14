<script setup>
import Sidebar from "./Sidebar.vue";
import Navbar from "./Navbar.vue";
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { Toaster } from "vue-sonner";

defineProps({
    title: {
        type: String,
        default: "Dashboard",
    },
});

const sidebarCollapsed = ref(false);
const storageKey = "naac.sidebar.collapsed";
const RESIZE_SYNC_STEPS_MS = [0, 140, 280, 380];
let resizeSyncTimers = [];

const dispatchLayoutResize = () => {
    window.dispatchEvent(new Event("resize"));
};

const stopResizeSync = () => {
    resizeSyncTimers.forEach((timer) => {
        window.clearTimeout(timer);
    });

    resizeSyncTimers = [];
};

const startResizeSync = () => {
    stopResizeSync();

    resizeSyncTimers = RESIZE_SYNC_STEPS_MS.map((delay) =>
        window.setTimeout(() => {
            dispatchLayoutResize();
        }, delay),
    );
};

const toggleSidebar = () => {
    sidebarCollapsed.value = !sidebarCollapsed.value;
};

onMounted(() => {
    const saved = window.localStorage.getItem(storageKey);
    sidebarCollapsed.value = saved === "1";
});

watch(sidebarCollapsed, (value) => {
    window.localStorage.setItem(storageKey, value ? "1" : "0");

    nextTick(() => {
        startResizeSync();
    });
});

onBeforeUnmount(() => {
    stopResizeSync();
});
</script>

<template>
    <div
        class="app-shell grid h-screen overflow-hidden bg-naac-bg text-green-900 max-md:h-auto max-md:min-h-screen max-md:grid-cols-1 max-md:overflow-visible"
        :class="
            sidebarCollapsed ? 'grid-cols-[72px_1fr]' : 'grid-cols-[236px_1fr]'
        "
    >
        <Sidebar :collapsed="sidebarCollapsed" />

        <div class="flex min-h-0 min-w-0 flex-col overflow-hidden max-md:h-auto max-md:overflow-visible">
            <div class="sticky top-0 z-30 shrink-0">
                <Navbar
                    :title="title"
                    :collapsed="sidebarCollapsed"
                    @toggle-sidebar="toggleSidebar"
                />
            </div>
            <main
                class="scrollbar-page grid min-h-0 min-w-0 flex-1 content-start gap-4 overflow-auto bg-naac-bg px-5 py-4 max-md:flex-none max-md:overflow-visible"
            >
                <slot />
            </main>
        </div>

        <Toaster position="top-right" rich-colors />
    </div>
</template>
