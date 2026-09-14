<script setup>
import { Link, usePage } from "@inertiajs/vue3";
import {
    BarChart3,
    Building2,
    Boxes,
    LayoutDashboard,
    Percent,
    Scale,
    Package,
    RefreshCw,
    Settings,
    ShoppingBag,
    User,
    Users,
    Warehouse,
} from "lucide-vue-next";

defineProps({
    collapsed: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();
const sideNavLogo =
    "/assets/side-nav-logo/Gemini_Generated_Image_7wme0a7wme0a7wme-removebg-preview.png";

const sections = [
    {
        items: [
            { label: "Dashboard", href: "/dashboard", icon: LayoutDashboard },
        ],
    },
    {
        label: "Products",
        items: [
            { label: "Products Setup", href: "/products", icon: Package },
            { label: "Product VAT", href: "/product-vat", icon: Percent },
            { label: "Retail Setup", href: "/product-retail", icon: Scale },
        ],
    },
    {
        label: "Inventories",
        items: [
            { label: "Branch Inventory", href: "/inventories", icon: Boxes },
            {
                label: "Main Inventory",
                href: "/main-inventory",
                icon: Warehouse,
            },
        ],
    },
    {
        label: "Sales Management",
        items: [
            { label: "Sales Report", href: "/sales-report", icon: BarChart3 },
            {
                label: "Daily Cashier Reports",
                href: "/daily-cashier-reports",
                icon: ShoppingBag,
            },
            { label: "Discount Setup", href: "/discount-setup", icon: Percent },
            {
                label: "Product Replacement",
                href: "/product-replacement",
                icon: RefreshCw,
            },
            {
                label: "Product Profit Analysis",
                href: "/product-profit-analysis",
                icon: BarChart3,
            },
        ],
    },
    {
        label: "Administration",
        items: [
            { label: "Employees", href: "/employees", icon: User },
            { label: "Branches", href: "/branches", icon: Building2 },
            { label: "Users", href: "/users", icon: Users },
            { label: "Settings", href: "/settings", icon: Settings },
        ],
    },
];

const isActive = (href) => page.url === href || page.url.startsWith(`${href}/`);

const signedName =
    page.props.auth?.user?.name ??
    page.props.auth?.user?.user_name ??
    "Officer";
const signedRole = String(page.props.auth?.user?.role ?? "officer");
</script>

<template>
    <aside
        class="sticky top-0 flex h-screen min-h-0 flex-col overflow-hidden border-r border-green-900 bg-green-900 p-3.5 max-md:static max-md:h-auto max-md:overflow-visible"
        :class="collapsed ? 'px-2.5 py-4' : 'px-3.5'"
    >
        <div
            class="mb-4 flex shrink-0 items-center gap-2.5 border-b border-green-100/20 pb-4"
            :class="collapsed ? 'justify-center px-0' : 'px-1.5'"
        >
            <div
                class="grid h-11 w-11 shrink-0 place-items-center rounded-full border-2 border-green-300 bg-green-50"
            >
                <img
                    :src="sideNavLogo"
                    alt="Naga Alta Agri Corp"
                    class="block h-auto w-[30px] object-contain"
                />
            </div>
            <div v-if="!collapsed" class="min-w-0 md:block">
                <p
                    class="m-0 text-xs font-extrabold uppercase tracking-[0.14em] text-white"
                >
                    Admin Portal
                </p>
                <p class="mt-0.5 text-[11px] font-semibold leading-snug text-green-200">
                    Naga Alta Agri Corp
                </p>
            </div>
        </div>

        <nav
            class="scrollbar-sidebar flex min-h-0 flex-1 flex-col gap-4 overflow-x-hidden overflow-y-auto pb-3 max-md:overflow-visible"
        >
            <div
                v-for="(section, index) in sections"
                :key="section.label || `section-${index}`"
                class="grid gap-0.5"
            >
                <p
                    v-if="section.label && !collapsed"
                    class="mb-1 px-2.5 text-[10px] font-extrabold uppercase tracking-[0.14em] text-green-300"
                >
                    {{ section.label }}
                </p>
                <Link
                    v-for="item in section.items"
                    :key="item.href"
                    :href="item.href"
                    :title="item.label"
                    class="flex items-center rounded-sm text-sm font-medium text-green-50/90 transition hover:bg-white/10 hover:text-white"
                    :class="[
                        isActive(item.href)
                            ? 'bg-green-50/15 font-bold text-white'
                            : '',
                        collapsed
                            ? 'justify-center p-2.5'
                            : 'gap-2.5 px-2.5 py-2',
                    ]"
                >
                    <component :is="item.icon" class="h-[15px] w-[15px] shrink-0" />
                    <span
                        v-if="!collapsed"
                        class="min-w-0 truncate leading-snug"
                    >
                        {{ item.label }}
                    </span>
                </Link>
            </div>
        </nav>

        <div
            v-if="!collapsed"
            class="mt-auto shrink-0 border-t border-green-100/20 px-2.5 pt-3"
        >
            <p
                class="m-0 text-[10px] font-extrabold uppercase tracking-wider text-green-300"
            >
                Signed in
            </p>
            <p class="mt-1 text-sm font-bold leading-snug text-white">
                {{ signedName }}
            </p>
            <p class="mt-0.5 text-xs font-semibold capitalize text-green-200">
                {{ signedRole }}
            </p>
        </div>
    </aside>
</template>
