<script setup>
import { Head } from "@inertiajs/vue3";
import { Download, Eye } from "lucide-vue-next";
import { onMounted, ref } from "vue";
import { toast } from "vue-sonner";
import AppLayout from "../components/layout/AppLayout.vue";
import Button from "../components/ui/Button.vue";
import Modal from "../components/ui/Modal.vue";
import Table from "../components/ui/Table.vue";
import api from "../services/api";

const reports = ref([]);
const branches = ref([]);
const searchQuery = ref("");
const branchFilter = ref("all");
const dateFilter = ref("");
const showView = ref(false);
const viewing = ref(null);
const downloadingId = ref(null);

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

    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString();
};

const formatDay = (value) => {
    if (!value) {
        return "-";
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? String(value)
        : date.toLocaleDateString(undefined, {
              year: "numeric",
              month: "short",
              day: "numeric",
          });
};

const loadReports = async () => {
    const params = {};

    if (branchFilter.value !== "all") {
        params.branch_id = branchFilter.value;
    }

    if (dateFilter.value) {
        params.date = dateFilter.value;
    }

    if (searchQuery.value.trim()) {
        params.search = searchQuery.value.trim();
    }

    const [{ data: reportPayload }, { data: branchPayload }] =
        await Promise.all([
            api.get("/daily-sales-reports", { params }),
            api.get("/branches"),
        ]);

    reports.value = reportPayload.data ?? [];
    branches.value = branchPayload.data ?? [];
};

const openView = async (report) => {
    const { data } = await api.get(`/daily-sales-reports/${report.id}`);
    viewing.value = data.data;
    showView.value = true;
};

const closeView = () => {
    showView.value = false;
    viewing.value = null;
};

const downloadPdf = async (report) => {
    downloadingId.value = report.id;

    try {
        const { data } = await api.get(
            `/daily-sales-reports/${report.id}/pdf`,
            { responseType: "blob" },
        );
        const url = window.URL.createObjectURL(
            new Blob([data], { type: "application/pdf" }),
        );
        const link = document.createElement("a");
        link.href = url;
        link.download = `${report.report_number}.pdf`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    } catch (error) {
        toast.error(
            error?.response?.data?.message ||
                "Unable to download this report as PDF.",
        );
    } finally {
        downloadingId.value = null;
    }
};

const resetFilters = () => {
    searchQuery.value = "";
    branchFilter.value = "all";
    dateFilter.value = "";
    loadReports();
};

onMounted(loadReports);
</script>

<template>
    <Head title="Daily Cashier Reports" />

    <AppLayout title="Daily Cashier Reports">
        <section class="sales-report-page">
            <p class="retail-intro">
                    Cashiers send their day from the Flutter POS. Reports are
                    grouped by branch and cashier. Open a report to review it,
                    or download a PDF.
                </p>

            <section class="dashboard-surface-card">
                <h2 class="panel-title">Daily Cashier Reports</h2>
                <div class="sales-report-filters">
                    <div class="sales-report-filters__group">
                        <label
                            class="sales-report-filter sales-report-filter--search"
                        >
                            <span class="sales-report-filter__label">
                                Search
                            </span>
                            <input
                                v-model="searchQuery"
                                type="text"
                                class="input"
                                placeholder="Report no., cashier, branch"
                                @keyup.enter="loadReports"
                            />
                        </label>

                        <label class="sales-report-filter">
                            <span class="sales-report-filter__label">
                                Branch
                            </span>
                            <select v-model="branchFilter" class="input">
                                <option value="all">All branches</option>
                                <option
                                    v-for="branch in branches"
                                    :key="branch.id"
                                    :value="String(branch.id)"
                                >
                                    {{ branch.name }}
                                </option>
                            </select>
                        </label>

                        <label class="sales-report-filter">
                            <span class="sales-report-filter__label">
                                Date
                            </span>
                            <input
                                v-model="dateFilter"
                                type="date"
                                class="input"
                            />
                        </label>
                    </div>

                    <div class="sales-report-filters__actions">
                        <p class="sales-report-meta">
                            Showing {{ reports.length }} report(s)
                        </p>
                        <button
                            type="button"
                            class="btn btn--secondary"
                            @click="resetFilters"
                        >
                            Clear
                        </button>
                        <Button type="button" @click="loadReports">
                            Apply
                        </Button>
                    </div>
                </div>

                <Table
                    :columns="[
                        'Report no.',
                        'Date',
                        'Branch',
                        'Cashier',
                        'Receipts',
                        'Total sales',
                        'Sent',
                        'Actions',
                    ]"
                >
                    <tr v-if="reports.length === 0">
                        <td colspan="8" class="sales-report-empty">
                            No cashier reports yet. They appear here after a
                            cashier sends the day from the app.
                        </td>
                    </tr>
                    <tr v-for="report in reports" :key="report.id">
                        <td>
                            <strong>{{ report.report_number }}</strong>
                        </td>
                        <td>{{ formatDay(report.report_date) }}</td>
                        <td>
                            {{ report.branch_name || report.branch?.name }}
                        </td>
                        <td>{{ report.cashier_name }}</td>
                        <td>{{ report.receipt_count }}</td>
                        <td>₱ {{ formatMoney(report.total_sales) }}</td>
                        <td>{{ formatDate(report.submitted_at) }}</td>
                        <td class="actions">
                            <Button
                                type="button"
                                variant="outline"
                                class="products-action-btn"
                                @click="openView(report)"
                            >
                                <Eye class="products-btn-icon" />
                                <span>View</span>
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                class="products-action-btn"
                                :disabled="downloadingId === report.id"
                                @click="downloadPdf(report)"
                            >
                                <Download class="products-btn-icon" />
                                <span>
                                    {{
                                        downloadingId === report.id
                                            ? "..."
                                            : "PDF"
                                    }}
                                </span>
                            </Button>
                        </td>
                    </tr>
                </Table>
            </section>
        </section>

        <Modal
            :open="showView"
            :title="viewing?.report_number || 'Daily report'"
            wide
            @close="closeView"
        >
            <div v-if="viewing" class="daily-report-view">
                <div class="daily-report-view__summary">
                    <div class="daily-report-view__item">
                        <span>Date</span>
                        <strong>{{ formatDay(viewing.report_date) }}</strong>
                    </div>
                    <div class="daily-report-view__item">
                        <span>Branch</span>
                        <strong>
                            {{ viewing.branch_name || viewing.branch?.name }}
                        </strong>
                    </div>
                    <div class="daily-report-view__item">
                        <span>Cashier</span>
                        <strong>{{ viewing.cashier_name }}</strong>
                    </div>
                    <div class="daily-report-view__item">
                        <span>Receipts</span>
                        <strong>{{ viewing.receipt_count }}</strong>
                    </div>
                    <div class="daily-report-view__item">
                        <span>Total sales</span>
                        <strong>
                            ₱ {{ formatMoney(viewing.total_sales) }}
                        </strong>
                    </div>
                    <div class="daily-report-view__item">
                        <span>VAT</span>
                        <strong>₱ {{ formatMoney(viewing.total_vat) }}</strong>
                    </div>
                    <div class="daily-report-view__item">
                        <span>Discounts</span>
                        <strong>
                            ₱ {{ formatMoney(viewing.total_discount) }}
                        </strong>
                    </div>
                    <div class="daily-report-view__item">
                        <span>Replacement extra</span>
                        <strong>
                            ₱ {{ formatMoney(viewing.replacement_extra) }}
                        </strong>
                    </div>
                    <div class="daily-report-view__item">
                        <span>Cash counted</span>
                        <strong>
                            {{
                                viewing.cash_counted === null ||
                                viewing.cash_counted === undefined
                                    ? "—"
                                    : `₱ ${formatMoney(viewing.cash_counted)}`
                            }}
                        </strong>
                    </div>
                </div>

                <section
                    v-if="viewing.notes"
                    class="daily-report-view__section"
                >
                    <h4>Notes</h4>
                    <p>{{ viewing.notes }}</p>
                </section>

                <div class="daily-report-view__panels">
                    <section class="daily-report-view__section">
                        <h4>Payments</h4>
                        <div class="daily-report-view__table-wrap">
                            <table class="daily-report-view__table">
                                <thead>
                                    <tr>
                                        <th>Method</th>
                                        <th>Count</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-if="
                                            !viewing.payment_breakdown ||
                                            viewing.payment_breakdown
                                                .length === 0
                                        "
                                    >
                                        <td colspan="3">No payments</td>
                                    </tr>
                                    <tr
                                        v-for="payment in viewing.payment_breakdown"
                                        :key="payment.method"
                                    >
                                        <td>
                                            {{
                                                String(
                                                    payment.method || "cash",
                                                ).toUpperCase()
                                            }}
                                        </td>
                                        <td>{{ payment.count }}</td>
                                        <td>
                                            ₱
                                            {{ formatMoney(payment.amount) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="daily-report-view__section">
                        <h4>Products</h4>
                        <div class="daily-report-view__table-wrap">
                            <table class="daily-report-view__table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Unit</th>
                                        <th>Qty</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-if="
                                            !viewing.items ||
                                            viewing.items.length === 0
                                        "
                                    >
                                        <td colspan="4">No products</td>
                                    </tr>
                                    <tr
                                        v-for="(item, index) in viewing.items"
                                        :key="index"
                                    >
                                        <td>
                                            {{ item.name }}
                                            <span
                                                v-if="item.is_replacement"
                                                class="sales-report-flag"
                                            >
                                                Replacement
                                            </span>
                                        </td>
                                        <td>{{ item.unit_type || "-" }}</td>
                                        <td>
                                            {{
                                                item.quantity_display ||
                                                item.quantity
                                            }}
                                        </td>
                                        <td>
                                            ₱ {{ formatMoney(item.amount) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <div class="daily-report-view__actions">
                    <Button variant="outline" @click="closeView">Close</Button>
                    <Button @click="downloadPdf(viewing)">
                        <Download class="products-btn-icon" />
                        <span>Download PDF</span>
                    </Button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
