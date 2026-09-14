<script setup>
import { Head } from "@inertiajs/vue3";
import { computed, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import VueApexCharts from "vue3-apexcharts";
import AppLayout from "../components/layout/AppLayout.vue";
import api from "../services/api";

const DASHBOARD_CONTAINER_POLL_MS = 10000;

const stats = reactive({
    total_products: 0,
    total_sales: 0,
    total_users: 0,
    inventory_count: 0,
    branch_sales_chart: {
        range: "this_year",
        labels: [],
        series: [],
    },
    monthly_daily_bar_chart: {
        date_from: "",
        date_to: "",
        labels: [],
        series: [],
    },
    top_selling_products_pie: {
        labels: [],
        series: [],
        total_quantity: 0,
    },
    latest_sales: [],
});

const selectedRange = ref("this_year");
const customDateFrom = ref("");
const customDateTo = ref("");
const isLoadingChart = ref(false);
let dashboardPollTimerId = null;
let isRefreshingDashboardContainers = false;

const rangeOptions = [
    { value: "this_year", label: "This year" },
    { value: "last_year", label: "Last year" },
    { value: "30d", label: "Last 30 days" },
    { value: "90d", label: "Last 3 months" },
    { value: "180d", label: "Last 6 months" },
    { value: "custom", label: "Custom range" },
];

const chartSeries = computed(() => stats.branch_sales_chart?.series ?? []);
const monthlyBarSeries = computed(
    () => stats.monthly_daily_bar_chart?.series ?? [],
);
const topSellingPieSeries = computed(
    () => stats.top_selling_products_pie?.series ?? [],
);
const topSellingPieLabels = computed(
    () => stats.top_selling_products_pie?.labels ?? [],
);
const topSellingPieTotal = computed(() =>
    Number(stats.top_selling_products_pie?.total_quantity ?? 0),
);
const latestSales = computed(() => stats.latest_sales ?? []);

const formatCount = (value) => Number(value || 0).toLocaleString();

const formatCurrency = (value) => {
    return Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

const formatSoldAt = (dateValue) => {
    if (!dateValue) {
        return "-";
    }

    const date = new Date(dateValue);

    if (Number.isNaN(date.getTime())) {
        return "-";
    }

    return date.toLocaleString(undefined, {
        month: "short",
        day: "numeric",
        year: "numeric",
        hour: "numeric",
        minute: "2-digit",
    });
};

const metricCards = computed(() => [
    {
        key: "products",
        label: "Total Products",
        value: formatCount(stats.total_products),
        badge: "+ Catalog",
        badgeVariant: "positive",
        headline: "Products ready for selling",
        subline: "Keep your product list complete and updated.",
    },
    {
        key: "sales",
        label: "Total Sales",
        value: `\u20B1 ${formatCurrency(stats.total_sales)}`,
        badge: "+ Revenue",
        badgeVariant: "positive",
        headline: "Revenue captured from all transactions",
        subline: "Sales totals update automatically after each post.",
    },
    {
        key: "users",
        label: "Total Users",
        value: formatCount(stats.total_users),
        badge: "Accounts",
        badgeVariant: "neutral",
        headline: "Users with access to the system",
        subline: "Review roles regularly to keep permissions clean.",
    },
    {
        key: "inventory",
        label: "Inventory Count",
        value: formatCount(stats.inventory_count),
        badge: "Stock",
        badgeVariant: "neutral",
        headline: "Tracked units in current inventory",
        subline: "Sync stock movements to avoid count mismatches.",
    },
]);

const formatHumanDate = (dateValue) => {
    if (!dateValue) {
        return "";
    }

    const [year, month, day] = dateValue.split("-").map(Number);
    const date = new Date(year, month - 1, day);

    return date.toLocaleDateString(undefined, {
        month: "long",
        day: "numeric",
        year: "numeric",
    });
};

const monthlyBarSubtitle = computed(() => {
    const dateFrom = stats.monthly_daily_bar_chart?.date_from;
    const dateTo = stats.monthly_daily_bar_chart?.date_to;

    if (!dateFrom || !dateTo) {
        return "Daily sales for this month";
    }

    return `${formatHumanDate(dateFrom)} - ${formatHumanDate(dateTo)}`;
});

const topSellingPieSubtitle = computed(() => {
    const dateFrom = stats.branch_sales_chart?.date_from;
    const dateTo = stats.branch_sales_chart?.date_to;

    if (!dateFrom || !dateTo) {
        return "Selected period";
    }

    return `${formatHumanDate(dateFrom)} - ${formatHumanDate(dateTo)}`;
});

const chartXAxisLabels = computed(() => {
    const labels = stats.branch_sales_chart?.labels ?? [];
    const lastIndex = labels.length - 1;

    return labels.map((label, index) =>
        index % 10 === 0 || index === lastIndex ? label : "",
    );
});

const chartOptions = computed(() => ({
    chart: {
        id: "sales-by-branch",
        type: "area",
        toolbar: { show: false },
        zoom: { enabled: false },
        redrawOnParentResize: true,
        redrawOnWindowResize: true,
        parentHeightOffset: 0,
        animations: {
            enabled: true,
            easing: "easeinout",
            speed: 220,
            animateGradually: {
                enabled: false,
                delay: 0,
            },
            dynamicAnimation: {
                enabled: false,
                speed: 220,
            },
        },
    },
    colors: ["#16a34a", "#15803d", "#22c55e", "#65a30d", "#4d7c0f"],
    dataLabels: { enabled: false },
    stroke: {
        curve: "smooth",
        width: 2,
        lineCap: "round",
    },
    markers: {
        size: 0,
        strokeWidth: 0,
        hover: {
            size: 6,
            sizeOffset: 2,
        },
    },
    states: {
        hover: {
            filter: {
                type: "lighten",
                value: 0.06,
            },
        },
        active: {
            allowMultipleDataPointsSelection: false,
            filter: {
                type: "darken",
                value: 0.15,
            },
        },
    },
    fill: {
        type: "gradient",
        gradient: {
            shadeIntensity: 1,
            opacityFrom: 0.35,
            opacityTo: 0.08,
            stops: [0, 90, 100],
        },
    },
    xaxis: {
        categories: chartXAxisLabels.value,
        axisBorder: { show: false },
        axisTicks: { show: false },
        labels: {
            style: {
                colors: "#64748b",
                fontSize: "12px",
            },
        },
        crosshairs: {
            show: false,
        },
        tooltip: {
            enabled: false,
        },
    },
    yaxis: {
        labels: {
            formatter: (value) => Number(value).toFixed(0),
            style: {
                colors: "#94a3b8",
                fontSize: "12px",
            },
        },
    },
    grid: {
        borderColor: "#e5e7eb",
        strokeDashArray: 4,
    },
    legend: {
        show: true,
        position: "bottom",
        horizontalAlign: "center",
        labels: {
            colors: "#334155",
        },
        markers: {
            width: 10,
            height: 10,
            radius: 2,
        },
    },
    tooltip: {
        shared: true,
        intersect: false,
        followCursor: true,
        marker: {
            show: true,
        },
        theme: "light",
        style: {
            fontSize: "12px",
        },
        x: {
            formatter: (_, options) => {
                const index = options?.dataPointIndex ?? -1;

                return stats.branch_sales_chart?.labels?.[index] ?? "";
            },
        },
        y: {
            formatter: (value) => Number(value).toFixed(2),
        },
    },
}));

const monthlyBarOptions = computed(() => ({
    chart: {
        id: "sales-this-month-daily",
        type: "bar",
        toolbar: { show: false },
        zoom: { enabled: false },
        redrawOnParentResize: true,
        redrawOnWindowResize: true,
        parentHeightOffset: 0,
        animations: {
            enabled: true,
            easing: "easeinout",
            speed: 200,
            animateGradually: {
                enabled: false,
                delay: 0,
            },
            dynamicAnimation: {
                enabled: false,
                speed: 200,
            },
        },
    },
    colors: ["#86efac", "#22c55e", "#16a34a", "#15803d"],
    plotOptions: {
        bar: {
            borderRadius: 5,
            columnWidth: "64%",
        },
    },
    dataLabels: { enabled: false },
    stroke: {
        show: false,
    },
    xaxis: {
        categories: stats.monthly_daily_bar_chart?.labels ?? [],
        axisBorder: { show: false },
        axisTicks: { show: false },
        labels: {
            rotate: -45,
            trim: false,
            style: {
                colors: "#64748b",
                fontSize: "11px",
            },
        },
    },
    yaxis: {
        labels: {
            formatter: (value) => Number(value).toFixed(0),
            style: {
                colors: "#94a3b8",
                fontSize: "12px",
            },
        },
    },
    grid: {
        borderColor: "#e5e7eb",
        strokeDashArray: 4,
    },
    legend: {
        show: true,
        position: "bottom",
        horizontalAlign: "center",
        labels: {
            colors: "#334155",
        },
    },
    tooltip: {
        shared: true,
        intersect: false,
        marker: {
            show: true,
        },
        theme: "light",
        style: {
            fontSize: "12px",
        },
        y: {
            formatter: (value) => Number(value).toFixed(2),
        },
    },
}));

const topSellingPieOptions = computed(() => ({
    chart: {
        id: "top-selling-products-pie",
        type: "donut",
        toolbar: { show: false },
        redrawOnParentResize: true,
        redrawOnWindowResize: true,
        parentHeightOffset: 0,
        animations: {
            enabled: true,
            easing: "easeinout",
            speed: 220,
            animateGradually: {
                enabled: false,
                delay: 0,
            },
            dynamicAnimation: {
                enabled: false,
                speed: 220,
            },
        },
    },
    labels: topSellingPieLabels.value,
    colors: ["#dcfce7", "#86efac", "#4ade80", "#22c55e", "#15803d"],
    dataLabels: { enabled: false },
    stroke: {
        width: 0,
    },
    legend: {
        show: false,
    },
    plotOptions: {
        pie: {
            donut: {
                size: "66%",
                labels: {
                    show: true,
                    name: {
                        show: true,
                        offsetY: 18,
                        color: "#64748b",
                        fontSize: "14px",
                    },
                    value: {
                        show: true,
                        offsetY: -12,
                        color: "#0f172a",
                        fontSize: "40px",
                        fontWeight: 700,
                        formatter: (value) =>
                            Number(value || 0).toLocaleString(),
                    },
                    total: {
                        show: true,
                        showAlways: true,
                        label: "Items",
                        color: "#64748b",
                        fontSize: "14px",
                        formatter: () =>
                            topSellingPieTotal.value.toLocaleString(),
                    },
                },
            },
        },
    },
    tooltip: {
        theme: "light",
        fillSeriesColor: false,
        marker: {
            show: true,
        },
        style: {
            fontSize: "12px",
        },
        y: {
            formatter: (value) => Number(value || 0).toLocaleString(),
        },
    },
}));

const buildDashboardParams = () => {
    const params = {
        range: selectedRange.value,
    };

    if (selectedRange.value === "custom") {
        if (!customDateFrom.value || !customDateTo.value) {
            return null;
        }

        params.date_from = customDateFrom.value;
        params.date_to = customDateTo.value;
    }

    return params;
};

const loadDashboard = async (showLoading = true) => {
    if (showLoading) {
        isLoadingChart.value = true;
    }

    try {
        const params = buildDashboardParams();

        if (!params) {
            return;
        }

        const { data } = await api.get("/dashboard", {
            params,
        });

        Object.assign(stats, data.data);

        if (stats.branch_sales_chart?.range === "custom") {
            customDateFrom.value =
                stats.branch_sales_chart?.date_from || customDateFrom.value;
            customDateTo.value =
                stats.branch_sales_chart?.date_to || customDateTo.value;
        }
    } finally {
        if (showLoading) {
            isLoadingChart.value = false;
        }
    }
};

const refreshDashboardContainers = async () => {
    if (isRefreshingDashboardContainers) {
        return;
    }

    isRefreshingDashboardContainers = true;

    try {
        await loadDashboard(false);
    } finally {
        isRefreshingDashboardContainers = false;
    }
};

const stopDashboardPolling = () => {
    if (dashboardPollTimerId !== null) {
        window.clearInterval(dashboardPollTimerId);
        dashboardPollTimerId = null;
    }
};

const startDashboardPolling = () => {
    stopDashboardPolling();

    dashboardPollTimerId = window.setInterval(() => {
        if (document.visibilityState === "visible") {
            refreshDashboardContainers();
        }
    }, DASHBOARD_CONTAINER_POLL_MS);
};

const handleDashboardVisibilityChange = () => {
    if (document.visibilityState === "visible") {
        refreshDashboardContainers();
    }
};

const formatDateInput = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");

    return `${year}-${month}-${day}`;
};

const applyCustomRange = () => {
    if (!customDateFrom.value || !customDateTo.value) {
        return;
    }

    loadDashboard();
};

watch(selectedRange, (value) => {
    if (value === "custom") {
        if (!customDateFrom.value || !customDateTo.value) {
            const end = new Date();
            const start = new Date();
            start.setDate(end.getDate() - 29);

            customDateFrom.value = formatDateInput(start);
            customDateTo.value = formatDateInput(end);
        }

        return;
    }

    loadDashboard();
});

onMounted(() => {
    loadDashboard();
    startDashboardPolling();
    document.addEventListener(
        "visibilitychange",
        handleDashboardVisibilityChange,
    );
});

onUnmounted(() => {
    stopDashboardPolling();
    document.removeEventListener(
        "visibilitychange",
        handleDashboardVisibilityChange,
    );
});
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout title="Dashboard" class="app-shell--enter">
        <div class="grid-cards dashboard-metric-cards">
            <article
                v-for="card in metricCards"
                :key="card.key"
                class="dashboard-metric-card"
            >
                <div class="dashboard-metric-card__top">
                    <p class="dashboard-metric-card__label">
                        {{ card.label }}
                    </p>
                    <span
                        class="dashboard-metric-card__badge"
                        :class="{
                            'dashboard-metric-card__badge--positive':
                                card.badgeVariant === 'positive',
                            'dashboard-metric-card__badge--neutral':
                                card.badgeVariant === 'neutral',
                        }"
                    >
                        {{ card.badge }}
                    </span>
                </div>

                <p class="dashboard-metric-card__value">{{ card.value }}</p>
                <p class="dashboard-metric-card__headline">
                    {{ card.headline }}
                </p>
                <p class="dashboard-metric-card__subline">{{ card.subline }}</p>
            </article>
        </div>

        <div class="dashboard-charts-grid">
            <section class="dashboard-chart-card dashboard-surface-card">
                    <div class="dashboard-chart-card__head">
                        <div>
                            <h3 class="dashboard-chart-card__title">
                                Sales chart
                            </h3>
                            <p class="dashboard-chart-card__subtitle">
                                Showing sales by branch for the selected period
                            </p>
                        </div>

                        <div class="dashboard-chart-card__filters">
                            <label class="dashboard-chart-card__range">
                                <select v-model="selectedRange" class="input">
                                    <option
                                        v-for="option in rangeOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>
                            </label>

                            <div
                                v-if="selectedRange === 'custom'"
                                class="dashboard-chart-card__custom"
                            >
                                <label class="dashboard-chart-card__date">
                                    <span>From</span>
                                    <input
                                        v-model="customDateFrom"
                                        type="date"
                                        class="input"
                                    />
                                </label>

                                <label class="dashboard-chart-card__date">
                                    <span>To</span>
                                    <input
                                        v-model="customDateTo"
                                        type="date"
                                        class="input"
                                    />
                                </label>

                                <button
                                    type="button"
                                    class="btn btn--primary"
                                    :disabled="
                                        !customDateFrom ||
                                        !customDateTo ||
                                        isLoadingChart
                                    "
                                    @click="applyCustomRange"
                                >
                                    Apply
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="dashboard-chart-card__body">
                        <p
                            v-if="isLoadingChart"
                            class="dashboard-chart-card__state"
                        >
                            Loading chart data...
                        </p>

                        <p
                            v-else-if="!chartSeries.length"
                            class="dashboard-chart-card__state"
                        >
                            No sales data available for this period.
                        </p>

                        <VueApexCharts
                            v-else
                            type="area"
                            height="300"
                            :options="chartOptions"
                            :series="chartSeries"
                        />
                    </div>
                </section>

            <section class="dashboard-bar-card dashboard-surface-card">
                    <div class="dashboard-bar-card__head">
                        <h3 class="dashboard-bar-card__title">
                            Bar chart - daily
                        </h3>
                        <p class="dashboard-bar-card__subtitle">
                            {{ monthlyBarSubtitle }}
                        </p>
                    </div>

                    <div class="dashboard-bar-card__body">
                        <p
                            v-if="isLoadingChart"
                            class="dashboard-chart-card__state"
                        >
                            Loading chart data...
                        </p>

                        <p
                            v-else-if="!monthlyBarSeries.length"
                            class="dashboard-chart-card__state"
                        >
                            No daily sales data available for this month.
                        </p>

                        <VueApexCharts
                            v-else
                            type="bar"
                            height="300"
                            :options="monthlyBarOptions"
                            :series="monthlyBarSeries"
                        />
                    </div>

                    <p class="dashboard-bar-card__meta">
                        Showing branch totals per day for the current month
                    </p>
                </section>
        </div>

        <div class="dashboard-bottom-grid">
            <section class="dashboard-latest-sales dashboard-surface-card">
                    <div class="dashboard-latest-sales__head">
                        <h3 class="dashboard-latest-sales__title">
                            Latest sales
                        </h3>
                        <p class="dashboard-latest-sales__subtitle">
                            Showing the 5 most recent transactions
                        </p>
                    </div>

                    <div class="dashboard-latest-sales__body">
                        <p
                            v-if="isLoadingChart"
                            class="dashboard-chart-card__state"
                        >
                            Loading latest sales...
                        </p>

                        <p
                            v-else-if="!latestSales.length"
                            class="dashboard-chart-card__state"
                        >
                            No sales found yet.
                        </p>

                        <div
                            v-else
                            class="dashboard-latest-sales__table-wrap table-wrap"
                        >
                            <table class="dashboard-latest-sales__table table">
                                <thead>
                                    <tr>
                                        <th>Sale #</th>
                                        <th>Product</th>
                                        <th>Qty</th>
                                        <th>Total</th>
                                        <th>Processed by</th>
                                        <th>Sold at</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="sale in latestSales"
                                        :key="sale.id"
                                    >
                                        <td
                                            class="dashboard-latest-sales__number"
                                        >
                                            {{ sale.sale_number }}
                                        </td>
                                        <td>{{ sale.product_name }}</td>
                                        <td class="dashboard-latest-sales__qty">
                                            {{ sale.quantity_display || sale.quantity }}
                                        </td>
                                        <td
                                            class="dashboard-latest-sales__amount"
                                        >
                                            {{
                                                formatCurrency(sale.total_price)
                                            }}
                                        </td>
                                        <td>{{ sale.processed_by }}</td>
                                        <td>
                                            {{ formatSoldAt(sale.sold_at) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

            <section class="dashboard-pie-card dashboard-surface-card">
                    <div class="dashboard-pie-card__head">
                        <h3 class="dashboard-pie-card__title">
                            Top selling products
                        </h3>
                        <p class="dashboard-pie-card__subtitle">
                            {{ topSellingPieSubtitle }}
                        </p>
                    </div>

                    <div class="dashboard-pie-card__body">
                        <p
                            v-if="isLoadingChart"
                            class="dashboard-chart-card__state"
                        >
                            Loading top selling products...
                        </p>

                        <p
                            v-else-if="!topSellingPieSeries.length"
                            class="dashboard-chart-card__state"
                        >
                            No top-selling product data for this period.
                        </p>

                        <VueApexCharts
                            v-else
                            type="donut"
                            height="320"
                            :options="topSellingPieOptions"
                            :series="topSellingPieSeries"
                        />
                    </div>

                    <p class="dashboard-pie-card__meta">
                        Based on quantity sold for the selected period
                    </p>
                </section>
        </div>
    </AppLayout>
</template>
