<script setup>
import { Link, usePage } from "@inertiajs/vue3";
import { PanelLeftClose, PanelLeftOpen } from "lucide-vue-next";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";

defineProps({
    title: {
        type: String,
        default: "Dashboard",
    },
    collapsed: {
        type: Boolean,
        default: false,
    },
});

defineEmits(["toggle-sidebar"]);

const page = usePage();
const now = ref(new Date());
let clockTimer = null;

const adminName = computed(
    () =>
        page.props.auth?.user?.name ??
        page.props.auth?.user?.user_name ??
        "Officer",
);

const officerRole = computed(
    () => String(page.props.auth?.user?.role ?? "officer").toUpperCase(),
);

const clockLabel = computed(() =>
    now.value.toLocaleString("en-PH", {
        timeZone: "Asia/Manila",
        weekday: "long",
        day: "2-digit",
        month: "long",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
        hour12: true,
    }),
);

onMounted(() => {
    clockTimer = window.setInterval(() => {
        now.value = new Date();
    }, 30000);
});

onBeforeUnmount(() => {
    if (clockTimer) {
        window.clearInterval(clockTimer);
    }
});
</script>

<template>
    <header
        class="relative z-20 flex min-h-16 items-center justify-between gap-4 border-b border-gray-300 bg-white px-5 pb-2.5 pt-3.5 max-sm:flex-col max-sm:items-start"
    >
        <div
            class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-green-900 from-34% via-green-600 via-67% to-green-300"
        />

        <div class="flex min-w-0 items-center gap-2.5">
            <button
                type="button"
                class="grid h-8 w-8 cursor-pointer place-items-center rounded-sm border border-gray-300 bg-white transition hover:border-green-600 hover:bg-green-50"
                :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                :title="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                @click="$emit('toggle-sidebar')"
            >
                <PanelLeftOpen
                    v-if="collapsed"
                    class="h-4 w-4 text-green-900"
                />
                <PanelLeftClose v-else class="h-4 w-4 text-green-900" />
            </button>
            <div class="grid min-w-0 gap-0.5">
                <p
                    class="m-0 text-[13px] font-extrabold uppercase tracking-wider text-green-900"
                >
                    Naga Alta Agri Corp
                </p>
                <p class="m-0 text-xs font-semibold text-slate-500">
                    {{ title }} · {{ clockLabel }}
                </p>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-3.5">
            <div class="grid justify-items-end gap-0.5">
                <span class="text-sm font-bold leading-tight text-green-900">
                    {{ adminName }}
                </span>
                <span
                    class="text-[10px] font-extrabold tracking-widest text-slate-500"
                >
                    {{ officerRole }}
                </span>
            </div>
            <Link
                href="/logout"
                method="post"
                as="button"
                class="cursor-pointer rounded-sm border border-green-900 bg-green-900 px-3 py-1.5 text-[11px] font-extrabold uppercase tracking-wider text-white hover:bg-green-800"
            >
                Sign Out
            </Link>
        </div>
    </header>
</template>
