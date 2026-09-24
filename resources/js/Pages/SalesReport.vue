<script setup>
import { Head } from "@inertiajs/vue3";
import { Download } from "lucide-vue-next";
import { computed, onMounted, onUnmounted, ref } from "vue";
import { toast } from "vue-sonner";
import AppLayout from "../components/layout/AppLayout.vue";
import Table from "../components/ui/Table.vue";
import api from "../services/api";

const POLL_INTERVAL_MS = 10000;

const sales = ref([]);
const searchQuery = ref("");
const dateFrom = ref("");
const dateTo = ref("");
const paymentFilter = ref("all");
const exporting = ref("");
let pollTimerId = null;
let isRefreshing = false;

const getProcessedBy = (sale) =>
    sale.processed_by?.name ||
    sale.processed_by?.user_name ||
    sale.processed_by?.email ||
    "-";

const getUnitTypeLabel = (sale) => {
    const unitType = String(sale.unit_type ?? "").trim();

    if (unitType) {
        return unitType.charAt(0).toUpperCase() + unitType.slice(1);
    }

    const productUnit = String(sale.product?.unit ?? "").trim();

    return productUnit || "-";
};

const isUnpaidUtang = (sale) =>
    String(sale.payment_method || "").toLowerCase() === "utang" && !sale.paid_at;

const recognizedAtValue = (sale) => {
    if (String(sale.payment_method || "").toLowerCase() === "utang") {
        return sale.paid_at || null;
    }

    return sale.created_at;
};

const getCreatedAtDate = (sale) => {
    const date = new Date(recognizedAtValue(sale));

    return Number.isNaN(date.getTime()) ? null : date;
};

const filteredSales = computed(() => {
    const keyword = searchQuery.value.trim().toLowerCase();
    const fromDate = dateFrom.value
        ? new Date(`${dateFrom.value}T00:00:00`)
        : null;
    const toDate = dateTo.value
        ? new Date(`${dateTo.value}T23:59:59.999`)
        : null;

    return sales.value.filter((sale) => {
        const createdAt = getCreatedAtDate(sale);

        if (isUnpaidUtang(sale)) {
            return false;
        }

        if (fromDate && (!createdAt || createdAt < fromDate)) {
            return false;
        }

        if (toDate && (!createdAt || createdAt > toDate)) {
            return false;
        }

        const payment = String(sale.payment_method || "cash").toLowerCase();

        if (paymentFilter.value === "utang" && payment !== "utang") {
            return false;
        }

        if (paymentFilter.value === "cash" && payment === "utang") {
            return false;
        }

        if (!keyword) {
            return true;
        }

                        const searchable = [
                            sale.sale_number,
                            sale.product?.name,
                            getUnitTypeLabel(sale),
                            String(sale.quantity ?? ""),
                            Number(sale.total_price ?? 0).toFixed(2),
                            Number(sale.discount_percent ?? 0) > 0
                                ? `${Number(sale.discount_percent).toFixed(2)}%`
                                : "",
                            getProcessedBy(sale),
                            createdAt ? createdAt.toLocaleString() : "",
                        ]
            .join(" ")
            .toLowerCase();

        return searchable.includes(keyword);
    });
});

const total = computed(() =>
    filteredSales.value.reduce(
        (sum, sale) => sum + Number(sale.total_price),
        0,
    ),
);

const exportReport = async (format) => {
    if (exporting.value) {
        return;
    }

    exporting.value = format;

    try {
        const { data } = await api.get(`/sales/export/${format}`, {
            params: {
                search: searchQuery.value,
                from: dateFrom.value,
                to: dateTo.value,
                payment: paymentFilter.value,
            },
            responseType: "blob",
        });
        const type =
            format === "pdf"
                ? "application/pdf"
                : "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
        const url = window.URL.createObjectURL(new Blob([data], { type }));
        const link = document.createElement("a");
        const stamp = new Date().toISOString().slice(0, 10);
        link.href = url;
        link.download =
            format === "pdf"
                ? `sales-report-${stamp}.pdf`
                : `sales-report-${stamp}.xlsx`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    } catch (error) {
        toast.error(
            error?.response?.data?.message ||
                "Unable to export this sales report.",
        );
    } finally {
        exporting.value = "";
    }
};

const resetFilters = () => {
    searchQuery.value = "";
    dateFrom.value = "";
    dateTo.value = "";
    paymentFilter.value = "all";
};

const loadSales = async () => {
    if (isRefreshing) {
        return;
    }

    isRefreshing = true;

    try {
        const { data } = await api.get("/sales");
        sales.value = data.data;
    } finally {
        isRefreshing = false;
    }
};

const stopPolling = () => {
    if (pollTimerId !== null) {
        window.clearInterval(pollTimerId);
        pollTimerId = null;
    }
};

const startPolling = () => {
    stopPolling();

    pollTimerId = window.setInterval(() => {
        if (document.visibilityState === "visible") {
            loadSales();
        }
    }, POLL_INTERVAL_MS);
};

const handleVisibilityChange = () => {
    if (document.visibilityState === "visible") {
        loadSales();
    }
};

onMounted(() => {
    loadSales();
    startPolling();
    document.addEventListener("visibilitychange", handleVisibilityChange);
});

onUnmounted(() => {
    stopPolling();
    document.removeEventListener("visibilitychange", handleVisibilityChange);
});
</script>

<template>
    <Head title="Sales Report" />

    <AppLayout title="Sales Report">
        <section class="sales-report-page">
            <section class="dashboard-surface-card">
            <h2 class="panel-title">Sales Listing</h2>
            <div class="sales-report-filters">
                    <div class="sales-report-filters__group">
                        <label
                            class="sales-report-filter sales-report-filter--search"
                        >
                            <span class="sales-report-filter__label"
                                >Search</span
                            >
                            <input
                                v-model="searchQuery"
                                type="text"
                                class="input"
                                placeholder="Sale number, product, user..."
                            />
                        </label>

                        <label class="sales-report-filter">
                            <span class="sales-report-filter__label">From</span>
                            <input
                                v-model="dateFrom"
                                type="date"
                                class="input"
                            />
                        </label>

                        <label class="sales-report-filter">
                            <span class="sales-report-filter__label">To</span>
                            <input v-model="dateTo" type="date" class="input" />
                        </label>

                        <label class="sales-report-filter">
                            <span class="sales-report-filter__label">Payment</span>
                            <select v-model="paymentFilter" class="input">
                                <option value="all">All payments</option>
                                <option value="cash">Cash and other</option>
                                <option value="utang">Utang paid</option>
                            </select>
                        </label>
                    </div>

                    <div class="sales-report-filters__actions">
                        <p class="sales-report-meta">
                            Showing {{ filteredSales.length }} result(s)
                        </p>
                        <button
                            type="button"
                            class="btn btn--secondary"
                            :disabled="exporting !== ''"
                            @click="exportReport('pdf')"
                        >
                            <Download class="sales-report-export-icon" />
                            {{ exporting === "pdf" ? "Exporting..." : "PDF" }}
                        </button>
                        <button
                            type="button"
                            class="btn btn--secondary"
                            :disabled="exporting !== ''"
                            @click="exportReport('excel')"
                        >
                            <Download class="sales-report-export-icon" />
                            {{
                                exporting === "excel"
                                    ? "Exporting..."
                                    : "Excel"
                            }}
                        </button>
                        <button
                            type="button"
                            class="btn btn--secondary"
                            @click="resetFilters"
                        >
                            Clear
                        </button>
                    </div>
                </div>

                <Table
                    :columns="[
                        'Sale Number',
                        'Product',
                        'Unit Type',
                        'Quantity',
                        'VAT',
                        'Discount',
                        'Total Price',
                        'Processed By',
                        'Date',
                    ]"
                >
                    <tr v-if="!filteredSales.length">
                        <td colspan="9" class="sales-report-empty">
                            No sales match your filters.
                        </td>
                    </tr>

                    <tr v-for="sale in filteredSales" :key="sale.id">
                        <td>{{ sale.sale_number || "-" }}</td>
                        <td>
                            {{ sale.product?.name || "-" }}
                            <span
                                v-if="sale.payment_method === 'utang'"
                                class="sales-report-flag"
                            >
                                {{ sale.paid_at ? "Utang paid" : "Utang" }}
                            </span>
                            <span
                                v-if="sale.is_replacement"
                                class="sales-report-flag"
                            >
                                Replacement
                            </span>
                            <span
                                v-else-if="sale.is_replaced"
                                class="sales-report-flag sales-report-flag--muted"
                            >
                                Replaced
                            </span>
                        </td>
                        <td>{{ getUnitTypeLabel(sale) }}</td>
                        <td>{{ sale.quantity_display || sale.quantity }}</td>
                        <td>
                            {{
                                Number(sale.vat_amount || 0) > 0
                                    ? `${Number(sale.vat_rate || 0).toFixed(2)}% (₱ ${Number(sale.vat_amount).toFixed(2)})`
                                    : "-"
                            }}
                        </td>
                        <td>
                            {{
                                Number(sale.discount_percent || 0) > 0
                                    ? `${Number(sale.discount_percent).toFixed(2)}% (₱ ${Number(sale.discount_amount || 0).toFixed(2)})`
                                    : "-"
                            }}
                        </td>
                        <td>{{ Number(sale.total_price).toFixed(2) }}</td>
                        <td>{{ getProcessedBy(sale) }}</td>
                        <td>
                            {{ new Date(recognizedAtValue(sale)).toLocaleString() }}
                        </td>
                    </tr>
                </Table>

                <div class="report-total">
                    Grand Total: {{ total.toFixed(2) }}
                </div>
            </section>
        </section>
    </AppLayout>
</template>
