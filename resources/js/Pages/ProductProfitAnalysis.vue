<script setup>
import { Head } from "@inertiajs/vue3";
import { computed, onMounted, onUnmounted, ref } from "vue";
import VueApexCharts from "vue3-apexcharts";
import AppLayout from "../components/layout/AppLayout.vue";
import Table from "../components/ui/Table.vue";
import api from "../services/api";

const POLL_INTERVAL_MS = 10000;
const TOP_BAR_LIMIT = 8;
const TOP_TREND_LIMIT = 5;
const CHART_COLORS = [
    "#16a34a",
    "#65a30d",
    "#15803d",
    "#84cc16",
    "#22c55e",
    "#4ade80",
    "#166534",
    "#a3e635",
];

const sales = ref([]);
const searchQuery = ref("");
const dateFrom = ref("");
const dateTo = ref("");
let pollTimerId = null;
let isRefreshing = false;

const formatCurrency = (value) =>
    Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

const formatCount = (value) => Number(value || 0).toLocaleString();

const formatPercent = (value) =>
    Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    });

const truncateLabel = (value, maxLength = 18) => {
    const label = String(value || "").trim();

    if (label.length <= maxLength) {
        return label;
    }

    return `${label.slice(0, maxLength - 1)}…`;
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

const getUnitLabel = (sale) => {
    const unit = String(sale.unit_type || sale.product?.unit || "unit")
        .trim()
        .toLowerCase();

    if (!unit) {
        return "unit";
    }

    if (unit.includes("bag")) {
        return "bag";
    }

    if (unit.includes("sack")) {
        return "sack";
    }

    if (unit.includes("kilo") || unit.includes("kg")) {
        return "kg";
    }

    return unit;
};

const formatQtyLabel = (units, totalQty) => {
    const parts = Array.from(units.entries())
        .filter(([, quantity]) => quantity > 0)
        .sort((a, b) => b[1] - a[1])
        .map(([unit, quantity]) => `${formatCount(quantity)} ${unit}`);

    if (!parts.length) {
        return `${formatCount(totalQty)} unit`;
    }

    return parts.join(" + ");
};

const formatDateKey = (dateValue) => {
    const date = new Date(dateValue);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");

    return `${year}-${month}-${day}`;
};

const formatShortDate = (dateKey) => {
    const date = new Date(`${dateKey}T00:00:00`);

    if (Number.isNaN(date.getTime())) {
        return dateKey;
    }

    return date.toLocaleDateString(undefined, {
        month: "short",
        day: "numeric",
    });
};

const dateFilteredSales = computed(() => {
    const fromDate = dateFrom.value
        ? new Date(`${dateFrom.value}T00:00:00`)
        : null;
    const toDate = dateTo.value
        ? new Date(`${dateTo.value}T23:59:59.999`)
        : null;

    return sales.value.filter((sale) => {
        if (isUnpaidUtang(sale)) {
            return false;
        }

        const createdAt = getCreatedAtDate(sale);

        if (fromDate && (!createdAt || createdAt < fromDate)) {
            return false;
        }

        if (toDate && (!createdAt || createdAt > toDate)) {
            return false;
        }

        return true;
    });
});

const searchedSales = computed(() => {
    const keyword = searchQuery.value.trim().toLowerCase();

    if (!keyword) {
        return dateFilteredSales.value;
    }

    return dateFilteredSales.value.filter((sale) => {
        const searchable = [
            sale.sale_number,
            sale.product?.name,
            sale.product?.category,
            getUnitLabel(sale),
            String(sale.quantity ?? ""),
        ]
            .filter(Boolean)
            .join(" ")
            .toLowerCase();

        return searchable.includes(keyword);
    });
});

const productRows = computed(() => {
    const grouped = new Map();

    searchedSales.value.forEach((sale) => {
        const productId = sale.product_id ?? sale.product?.id ?? "unknown";
        const productName = sale.product?.name || "Unknown Product";
        const quantity = Number(sale.quantity || 0);
        const totalPrice = Number(sale.total_price || 0);
        const unit = getUnitLabel(sale);

        if (!grouped.has(productId)) {
            grouped.set(productId, {
                id: String(productId),
                productName,
                qtySold: 0,
                orderCount: 0,
                totalSales: 0,
                units: new Map(),
            });
        }

        const row = grouped.get(productId);
        row.qtySold += quantity;
        row.orderCount += 1;
        row.totalSales += totalPrice;
        row.units.set(unit, Number(row.units.get(unit) || 0) + quantity);
    });

    const rows = Array.from(grouped.values());
    const totalRevenue = rows.reduce((sum, row) => sum + row.totalSales, 0);

    return rows
        .map((row) => ({
            ...row,
            averagePrice: row.qtySold > 0 ? row.totalSales / row.qtySold : 0,
            share: totalRevenue > 0 ? (row.totalSales / totalRevenue) * 100 : 0,
            qtyLabel: formatQtyLabel(row.units, row.qtySold),
        }))
        .sort((a, b) => {
            if (b.qtySold !== a.qtySold) {
                return b.qtySold - a.qtySold;
            }

            return b.totalSales - a.totalSales;
        })
        .map((row, index) => ({
            ...row,
            rank: index + 1,
        }));
});

const totalRevenue = computed(() =>
    productRows.value.reduce((sum, row) => sum + row.totalSales, 0),
);

const totalUnitsSold = computed(() =>
    productRows.value.reduce((sum, row) => sum + row.qtySold, 0),
);

const totalOrders = computed(() =>
    productRows.value.reduce((sum, row) => sum + row.orderCount, 0),
);

const topProduct = computed(() => productRows.value[0] ?? null);

const metricCards = computed(() => [
    {
        key: "revenue",
        label: "Total Sales",
        value: `₱ ${formatCurrency(totalRevenue.value)}`,
    },
    {
        key: "units",
        label: "Units Sold",
        value: formatCount(totalUnitsSold.value),
    },
    {
        key: "products",
        label: "Products Sold",
        value: formatCount(productRows.value.length),
    },
    {
        key: "top",
        label: "Most Bought Product",
        value: topProduct.value?.productName ?? "No sales yet",
        valueType: "text",
        tone: topProduct.value ? "positive" : "neutral",
    },
]);

const topBarRows = computed(() => productRows.value.slice(0, TOP_BAR_LIMIT));

const barChartHeight = computed(() =>
    Math.max(280, topBarRows.value.length * 42 + 48),
);

const barChartSeries = computed(() => [
    {
        name: "Qty Sold",
        data: topBarRows.value.map((row) => row.qtySold),
    },
]);

const barChartOptions = computed(() => ({
    chart: {
        type: "bar",
        toolbar: { show: false },
        animations: {
            enabled: true,
            speed: 220,
        },
    },
    colors: [CHART_COLORS[0]],
    plotOptions: {
        bar: {
            horizontal: true,
            borderRadius: 6,
            barHeight: topBarRows.value.length > 4 ? "62%" : "42%",
        },
    },
    dataLabels: { enabled: false },
    xaxis: {
        categories: topBarRows.value.map((row) => row.productName),
        labels: {
            formatter: (value) => {
                const numeric = Number(value);

                return Number.isFinite(numeric)
                    ? numeric.toFixed(0)
                    : String(value ?? "");
            },
            style: {
                colors: "#94a3b8",
            },
        },
    },
    yaxis: {
        reversed: true,
        labels: {
            maxWidth: 128,
            formatter: (value) => truncateLabel(value),
            style: {
                colors: "#64748b",
            },
        },
    },
    grid: {
        borderColor: "#e2e8f0",
        strokeDashArray: 4,
        xaxis: {
            lines: { show: true },
        },
        yaxis: {
            lines: { show: false },
        },
    },
    tooltip: {
        theme: "light",
        y: {
            formatter: (value, { dataPointIndex }) => {
                const row = topBarRows.value[dataPointIndex];

                if (!row) {
                    return formatCount(value);
                }

                return `${row.qtyLabel} · ₱ ${formatCurrency(row.totalSales)}`;
            },
        },
    },
}));

const trendRows = computed(() => productRows.value.slice(0, TOP_TREND_LIMIT));

const trendData = computed(() => {
    const byDate = new Map();

    searchedSales.value.forEach((sale) => {
        const dateKey = formatDateKey(recognizedAtValue(sale));

        if (!dateKey) {
            return;
        }

        if (!byDate.has(dateKey)) {
            byDate.set(dateKey, new Map());
        }

        const productId = String(
            sale.product_id ?? sale.product?.id ?? "unknown",
        );
        const dayMap = byDate.get(dateKey);
        dayMap.set(
            productId,
            Number(dayMap.get(productId) || 0) + Number(sale.total_price || 0),
        );
    });

    const sortedEntries = Array.from(byDate.entries()).sort((a, b) =>
        a[0].localeCompare(b[0]),
    );

    return {
        labels: sortedEntries.map(([dateKey]) => formatShortDate(dateKey)),
        valuesByProduct: sortedEntries.map(([, dayMap]) => dayMap),
    };
});

const trendSeries = computed(() =>
    trendRows.value.map((row) => ({
        name: row.productName,
        data: trendData.value.valuesByProduct.map((dayMap) =>
            Number(dayMap.get(row.id) || 0),
        ),
    })),
);

const trendOptions = computed(() => ({
    chart: {
        type: "line",
        toolbar: { show: false },
        zoom: { enabled: false },
        animations: {
            enabled: true,
            speed: 240,
        },
    },
    colors: CHART_COLORS,
    stroke: {
        curve: "smooth",
        width: 2,
    },
    markers: {
        size: trendData.value.labels.length > 12 ? 0 : 4,
    },
    xaxis: {
        categories: trendData.value.labels,
        labels: {
            style: {
                colors: "#64748b",
            },
        },
    },
    yaxis: {
        labels: {
            formatter: (value) => Number(value || 0).toFixed(0),
            style: {
                colors: "#94a3b8",
            },
        },
    },
    grid: {
        borderColor: "#e2e8f0",
        strokeDashArray: 4,
    },
    legend: {
        position: "bottom",
        labels: {
            colors: "#334155",
        },
    },
    tooltip: {
        theme: "light",
        y: {
            formatter: (value) => `₱ ${formatCurrency(value)}`,
        },
    },
}));

const resetFilters = () => {
    searchQuery.value = "";
    dateFrom.value = "";
    dateTo.value = "";
};

const loadSales = async () => {
    if (isRefreshing) {
        return;
    }

    isRefreshing = true;

    try {
        const { data } = await api.get("/sales");
        sales.value = data.data ?? [];
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
    <Head title="Product Profit Analysis" />

    <AppLayout title="Product Profit Analysis">
        <section class="sales-report-page">
            <section class="dashboard-surface-card">
            <h2 class="panel-title">Product Filters</h2>
            <div class="sales-report-filters">
                    <div class="sales-report-filters__group">
                        <label
                            class="sales-report-filter sales-report-filter--search"
                        >
                            <span class="sales-report-filter__label"
                                >Product Search</span
                            >
                            <input
                                v-model="searchQuery"
                                type="text"
                                class="input"
                                placeholder="Search product name"
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
                    </div>

                    <div class="sales-report-filters__actions">
                        <p class="sales-report-meta">
                            Showing
                            {{ formatCount(productRows.length) }} product(s)
                        </p>
                        <button
                            type="button"
                            class="btn btn--secondary"
                            @click="resetFilters"
                        >
                            Clear
                        </button>
                    </div>
                </div>
            </section>

                <div class="profit-analysis-summary">
                    <article
                        v-for="metric in metricCards"
                        :key="metric.key"
                        class="profit-analysis-summary__item"
                        :class="{
                            'profit-analysis-summary__item--positive':
                                metric.tone === 'positive',
                        }"
                    >
                        <p class="profit-analysis-summary__label">
                            {{ metric.label }}
                        </p>
                        <p
                            class="profit-analysis-summary__value"
                            :class="{
                                'profit-analysis-summary__value--text':
                                    metric.valueType === 'text',
                            }"
                        >
                            {{ metric.value }}
                        </p>
                    </article>
                </div>

                <p class="profit-analysis-note">
                    Ranked by quantity sold across all products.
                    {{ formatCount(totalOrders) }} sale(s) in this period.
                </p>

                <div class="profit-analysis-charts">
                    <article class="profit-analysis-chart">
                        <header class="profit-analysis-chart__head">
                            <h4 class="profit-analysis-chart__title">
                                Most Bought Products
                            </h4>
                            <p class="profit-analysis-chart__subtitle">
                                Top {{ topBarRows.length || 0 }} by quantity
                                sold
                            </p>
                        </header>

                        <div class="profit-analysis-chart__body">
                            <p
                                v-if="!topBarRows.length"
                                class="profit-analysis-chart__state"
                            >
                                No product sales found for the selected
                                filters.
                            </p>

                            <VueApexCharts
                                v-else
                                type="bar"
                                :height="barChartHeight"
                                :options="barChartOptions"
                                :series="barChartSeries"
                            />
                        </div>
                    </article>

                    <article class="profit-analysis-chart">
                        <header class="profit-analysis-chart__head">
                            <h4 class="profit-analysis-chart__title">
                                Revenue Trend Over Time
                            </h4>
                            <p class="profit-analysis-chart__subtitle">
                                Top {{ trendRows.length || 0 }} products by
                                date
                            </p>
                        </header>

                        <div class="profit-analysis-chart__body">
                            <p
                                v-if="!trendData.labels.length"
                                class="profit-analysis-chart__state"
                            >
                                No trend data available for selected filters.
                            </p>

                            <VueApexCharts
                                v-else
                                type="line"
                                height="280"
                                :options="trendOptions"
                                :series="trendSeries"
                            />
                        </div>
                    </article>
                </div>

                <section class="dashboard-surface-card">
                <h2 class="panel-title">Product Ranking</h2>

                <Table
                    :columns="[
                        'Rank',
                        'Product',
                        'Qty Sold',
                        'Orders',
                        'Avg Price',
                        'Total Sales',
                        'Share',
                    ]"
                >
                    <tr v-if="!productRows.length">
                        <td colspan="7" class="sales-report-empty">
                            No product sales found for this filter.
                        </td>
                    </tr>

                    <tr
                        v-for="row in productRows"
                        :key="row.id"
                        :class="{
                            'profit-analysis-row--top': row.rank === 1,
                        }"
                    >
                        <td>
                            <span
                                class="profit-analysis-rank"
                                :class="{
                                    'profit-analysis-rank--1': row.rank === 1,
                                    'profit-analysis-rank--2': row.rank === 2,
                                    'profit-analysis-rank--3': row.rank === 3,
                                }"
                            >
                                {{ row.rank }}
                            </span>
                        </td>
                        <td>{{ row.productName }}</td>
                        <td class="profit-analysis-qty">
                            {{ row.qtyLabel }}
                        </td>
                        <td>{{ formatCount(row.orderCount) }}</td>
                        <td>₱ {{ formatCurrency(row.averagePrice) }}</td>
                        <td class="profit-analysis-profit">
                            ₱ {{ formatCurrency(row.totalSales) }}
                        </td>
                        <td>
                            <div class="profit-analysis-share">
                                <span>{{ formatPercent(row.share) }}%</span>
                                <span class="profit-analysis-share__track">
                                    <i
                                        class="profit-analysis-share__fill"
                                        :style="{
                                            width: `${Math.min(row.share, 100)}%`,
                                        }"
                                    />
                                </span>
                            </div>
                        </td>
                    </tr>
                </Table>

                <div class="report-total">
                    Most Bought:
                    {{
                        topProduct
                            ? `${topProduct.productName} (${topProduct.qtyLabel})`
                            : "-"
                    }}
                </div>
            </section>
        </section>
    </AppLayout>
</template>
