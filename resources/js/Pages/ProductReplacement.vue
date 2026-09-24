<script setup>
import { Head } from "@inertiajs/vue3";
import { ArrowRight, Filter, RefreshCw, Search } from "lucide-vue-next";
import { computed, onMounted, ref } from "vue";
import { toast } from "vue-sonner";
import AppLayout from "../components/layout/AppLayout.vue";
import Button from "../components/ui/Button.vue";
import Table from "../components/ui/Table.vue";
import api from "../services/api";

const windowDays = ref(7);
const savedDays = ref(7);
const replacements = ref([]);
const searchQuery = ref("");
const typeFilter = ref("all");
const paymentFilter = ref("all");
const isSaving = ref(false);
const saveError = ref("");
const saveMessage = ref("");

const isDirty = computed(
    () => Number(windowDays.value) !== Number(savedDays.value),
);

const extraPaidCount = computed(
    () =>
        replacements.value.filter(
            (item) => Number(item.additional_payment || 0) > 0,
        ).length,
);

const filteredReplacements = computed(() => {
    const keyword = searchQuery.value.trim().toLowerCase();

    return replacements.value.filter((item) => {
        const sameProduct = isSameProduct(item);
        const extra = Number(item.additional_payment || 0) > 0;

        if (typeFilter.value === "same" && !sameProduct) {
            return false;
        }

        if (typeFilter.value === "different" && sameProduct) {
            return false;
        }

        if (paymentFilter.value === "extra" && !extra) {
            return false;
        }

        if (paymentFilter.value === "none" && extra) {
            return false;
        }

        if (!keyword) {
            return true;
        }

        const haystack = [
            item.sale_number,
            item.replacement_number,
            item.original_product?.name,
            item.new_product?.name,
            item.processed_by?.name,
            item.processed_by?.user_name,
        ]
            .filter(Boolean)
            .join(" ")
            .toLowerCase();

        return haystack.includes(keyword);
    });
});

const periodLabel = computed(() => {
    const days = Number(savedDays.value);

    if (days <= 0) {
        return "Same day only";
    }

    return days === 1 ? "1 day" : `${days} days`;
});

const formatMoney = (value) =>
    Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

const formatDate = (value) => {
    if (!value) {
        return "-";
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? "-" : date.toLocaleString();
};

const isSameProduct = (item) =>
    Number(item.original_product?.id) === Number(item.new_product?.id) ||
    String(item.original_product?.name || "") ===
        String(item.new_product?.name || "");

const loadData = async () => {
    const { data } = await api.get("/replacement-setup");
    const payload = data.data ?? {};

    windowDays.value = Number(payload.replacement_window_days ?? 7);
    savedDays.value = windowDays.value;
    replacements.value = payload.replacements ?? [];
};

const saveDays = async () => {
    saveError.value = "";
    saveMessage.value = "";
    isSaving.value = true;

    try {
        await api.put("/replacement-setup", {
            replacement_window_days: Number(windowDays.value),
        });
        savedDays.value = Number(windowDays.value);
        saveMessage.value = "Replacement period saved.";
        toast.success("Cashiers can now replace items within this many days.");
        await loadData();
    } catch (error) {
        saveError.value =
            error?.response?.data?.errors?.replacement_window_days?.[0] ||
            error?.response?.data?.message ||
            "Unable to save replacement period.";
    } finally {
        isSaving.value = false;
    }
};

onMounted(loadData);
</script>

<template>
    <Head title="Product Replacement" />

    <AppLayout title="Product Replacement">
        <div class="grid min-w-0 content-start gap-4">
            <section class="border border-gray-300 bg-white p-4">
                <div
                    class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
                >
                    <div class="min-w-0 max-w-3xl">
                        <h2 class="panel-title">Replacement period</h2>
                        <p class="m-0 text-sm leading-relaxed text-slate-600">
                            Cashiers look up the
                            <strong class="font-bold text-green-900"
                                >receipt number</strong
                            >
                            and can replace with the same product or a different
                            one. A higher-priced item collects the difference. A
                            lower-priced item has no refund. The receipt number
                            stays the same.
                        </p>
                    </div>

                    <form
                        class="flex shrink-0 flex-wrap items-end gap-2.5"
                        @submit.prevent="saveDays"
                    >
                        <label class="grid min-w-[11rem] gap-1.5">
                            <span
                                class="text-[11px] font-extrabold uppercase tracking-wider text-slate-500"
                            >
                                Days buyer can replace
                            </span>
                            <input
                                v-model="windowDays"
                                class="input h-10 w-32"
                                type="number"
                                min="0"
                                max="365"
                                step="1"
                            />
                        </label>
                        <Button
                            type="submit"
                            :disabled="isSaving || !isDirty"
                        >
                            <RefreshCw class="h-3.5 w-3.5" />
                            <span>{{ isSaving ? "Saving..." : "Save" }}</span>
                        </Button>
                    </form>
                </div>

                <p
                    class="mt-3 mb-0 text-xs font-semibold"
                    :class="
                        saveError
                            ? 'text-red-700'
                            : isDirty
                              ? 'text-amber-700'
                              : 'text-slate-500'
                    "
                >
                    {{
                        saveError ||
                        (isDirty
                            ? "Unsaved change"
                            : saveMessage ||
                              `Current window: ${periodLabel}. 0 means same-day replacement only.`)
                    }}
                </p>
            </section>

            <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
                <div
                    class="flex h-max flex-col gap-1 self-start border border-gray-300 bg-white px-4 py-3"
                >
                    <span
                        class="text-[11px] font-extrabold uppercase tracking-wider text-slate-500"
                    >
                        Eligible days
                    </span>
                    <strong
                        class="text-2xl font-extrabold leading-tight text-green-900"
                    >
                        {{ savedDays }}
                    </strong>
                </div>
                <div
                    class="flex h-max flex-col gap-1 self-start border border-gray-300 bg-white px-4 py-3"
                >
                    <span
                        class="text-[11px] font-extrabold uppercase tracking-wider text-slate-500"
                    >
                        Replacements logged
                    </span>
                    <strong
                        class="text-2xl font-extrabold leading-tight text-green-900"
                    >
                        {{ replacements.length }}
                    </strong>
                </div>
                <div
                    class="flex h-max flex-col gap-1 self-start border border-gray-300 bg-white px-4 py-3"
                >
                    <span
                        class="text-[11px] font-extrabold uppercase tracking-wider text-slate-500"
                    >
                        Extra payment collected
                    </span>
                    <strong
                        class="text-2xl font-extrabold leading-tight text-green-900"
                    >
                        {{ extraPaidCount }}
                    </strong>
                </div>
                <div
                    class="flex h-max flex-col gap-1 self-start border border-gray-300 bg-white px-4 py-3"
                >
                    <span
                        class="text-[11px] font-extrabold uppercase tracking-wider text-slate-500"
                    >
                        Receipt number
                    </span>
                    <strong
                        class="text-2xl font-extrabold leading-tight text-green-900"
                    >
                        Same
                    </strong>
                </div>
            </div>

            <section class="min-w-0 border border-gray-300 bg-white p-4">
                <div
                    class="mb-4 flex flex-wrap items-center justify-between gap-3"
                >
                    <div class="min-w-0">
                        <h2 class="panel-title !mb-1">Recent replacements</h2>
                        <p class="m-0 text-sm text-slate-600">
                            Showing
                            {{ filteredReplacements.length }}
                            of {{ replacements.length }} log(s). Additional
                            payment is only the extra amount collected when the
                            new product costs more.
                        </p>
                    </div>
                </div>

                <div class="products-toolbar">
                    <div class="products-controls">
                        <label class="products-control products-control--search">
                            <Search class="products-control-icon" />
                            <input
                                v-model="searchQuery"
                                class="input"
                                type="search"
                                placeholder="Search receipt, product, cashier"
                            />
                        </label>
                        <label class="products-control">
                            <Filter class="products-control-icon" />
                            <select v-model="typeFilter" class="input">
                                <option value="all">All types</option>
                                <option value="same">Same product</option>
                                <option value="different">Different product</option>
                            </select>
                        </label>
                        <label class="products-control">
                            <Filter class="products-control-icon" />
                            <select v-model="paymentFilter" class="input">
                                <option value="all">All payments</option>
                                <option value="extra">Extra paid</option>
                                <option value="none">No extra payment</option>
                            </select>
                        </label>
                    </div>
                </div>

                <Table
                    :columns="[
                        'Receipt',
                        'Original product',
                        'Replacement product',
                        'Type',
                        'Extra paid',
                        'Cashier',
                        'Date',
                    ]"
                >
                    <tr v-if="filteredReplacements.length === 0">
                        <td
                            class="py-10 text-center font-semibold text-slate-500"
                            colspan="7"
                        >
                            {{
                                replacements.length === 0
                                    ? "No replacements yet."
                                    : "No replacements match this search."
                            }}
                        </td>
                    </tr>
                    <tr
                        v-for="item in filteredReplacements"
                        :key="item.id"
                    >
                        <td>
                            <strong class="block font-extrabold tracking-wide">
                                {{ item.sale_number }}
                            </strong>
                            <span class="text-xs font-semibold text-slate-500">
                                {{ item.replacement_number }}
                            </span>
                        </td>
                        <td class="max-w-[14rem]">
                            <span class="block font-semibold">
                                {{ item.original_product?.name || "-" }}
                            </span>
                        </td>
                        <td class="max-w-[14rem]">
                            <span
                                class="inline-flex items-start gap-1.5 font-semibold"
                            >
                                <ArrowRight
                                    class="mt-0.5 h-3.5 w-3.5 shrink-0 text-green-700"
                                />
                                {{ item.new_product?.name || "-" }}
                            </span>
                        </td>
                        <td>
                            <span
                                class="inline-block border px-2 py-0.5 text-[11px] font-extrabold uppercase tracking-wide"
                                :class="
                                    isSameProduct(item)
                                        ? 'border-green-200 bg-green-50 text-green-800'
                                        : 'border-amber-200 bg-amber-50 text-amber-800'
                                "
                            >
                                {{
                                    isSameProduct(item)
                                        ? "Same item"
                                        : "Different item"
                                }}
                            </span>
                        </td>
                        <td>
                            <span
                                v-if="Number(item.additional_payment || 0) > 0"
                                class="font-extrabold text-green-800"
                            >
                                ₱ {{ formatMoney(item.additional_payment) }}
                            </span>
                            <span v-else class="font-semibold text-slate-500">
                                None
                            </span>
                        </td>
                        <td>
                            {{
                                item.processed_by?.name ||
                                item.processed_by?.user_name ||
                                "-"
                            }}
                        </td>
                        <td class="whitespace-nowrap">
                            {{ formatDate(item.created_at) }}
                        </td>
                    </tr>
                </Table>
            </section>
        </div>
    </AppLayout>
</template>
