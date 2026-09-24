<script setup>
import { Head } from "@inertiajs/vue3";
import { computed, onMounted, ref } from "vue";
import { toast } from "vue-sonner";
import AppLayout from "../components/layout/AppLayout.vue";
import Button from "../components/ui/Button.vue";
import Table from "../components/ui/Table.vue";
import api from "../services/api";

const sales = ref([]);
const statusFilter = ref("unpaid");
const searchQuery = ref("");
const payingNumber = ref("");

const formatDate = (value) => {
    if (!value) {
        return "-";
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return String(value).slice(0, 10);
    }

    return date.toLocaleDateString();
};

const isOverdue = (ticket) => {
    if (ticket.paid_at || !ticket.due_date) {
        return false;
    }

    const due = new Date(`${String(ticket.due_date).slice(0, 10)}T23:59:59`);

    return !Number.isNaN(due.getTime()) && due < new Date();
};

const tickets = computed(() => {
    const keyword = searchQuery.value.trim().toLowerCase();
    const grouped = new Map();

    sales.value.forEach((sale) => {
        const key = sale.sale_number || `line-${sale.id}`;

        if (!grouped.has(key)) {
            grouped.set(key, {
                sale_number: sale.sale_number || "-",
                borrower_name: sale.borrower_name || "-",
                due_date: sale.due_date,
                paid_at: sale.paid_at,
                items: [],
                total: 0,
            });
        }

        const ticket = grouped.get(key);
        ticket.items.push(sale);
        ticket.total += Number(sale.total_price || 0);

        if (!sale.paid_at) {
            ticket.paid_at = null;
        }
    });

    return Array.from(grouped.values()).filter((ticket) => {
        const unpaid = !ticket.paid_at;

        if (statusFilter.value === "unpaid" && !unpaid) {
            return false;
        }

        if (statusFilter.value === "paid" && unpaid) {
            return false;
        }

        if (statusFilter.value === "overdue" && !isOverdue(ticket)) {
            return false;
        }

        if (!keyword) {
            return true;
        }

        const haystack = [
            ticket.sale_number,
            ticket.borrower_name,
            ...ticket.items.map((item) => item.product?.name || ""),
        ]
            .join(" ")
            .toLowerCase();

        return haystack.includes(keyword);
    });
});

const loadUtang = async () => {
    const { data } = await api.get("/utang");
    sales.value = data.data || [];
};

const markPaid = async (ticket) => {
    if (payingNumber.value) {
        return;
    }

    payingNumber.value = ticket.sale_number;

    try {
        await api.post("/utang/pay", { sale_number: ticket.sale_number });
        toast.success("Utang marked as paid.");
        await loadUtang();
    } catch (error) {
        toast.error(error?.response?.data?.message || "Could not mark this utang as paid.");
    } finally {
        payingNumber.value = "";
    }
};

onMounted(loadUtang);
</script>

<template>
    <Head title="Utang" />

    <AppLayout title="Utang">
        <section class="sales-report-page">
            <div class="sales-report-filters">
                <div class="sales-report-filters__group">
                    <label class="sales-report-filter sales-report-filter--search">
                        <span class="sales-report-filter__label">Search</span>
                        <input
                            v-model="searchQuery"
                            class="input"
                            type="text"
                            placeholder="Borrower, receipt, or product"
                        />
                    </label>
                    <label class="sales-report-filter">
                        <span class="sales-report-filter__label">Status</span>
                        <select v-model="statusFilter" class="input">
                            <option value="unpaid">Unpaid</option>
                            <option value="overdue">Overdue</option>
                            <option value="paid">Paid</option>
                            <option value="all">All</option>
                        </select>
                    </label>
                </div>
                <p class="sales-report-meta">
                    {{ tickets.length }} receipt{{ tickets.length === 1 ? "" : "s" }}
                </p>
            </div>

            <Table
                :columns="[
                    'Receipt',
                    'Borrower',
                    'Due date',
                    'Items',
                    'Amount',
                    'Status',
                    'Action',
                ]"
            >
                <tr v-if="tickets.length === 0">
                    <td colspan="7" class="sales-report-empty">
                        No utang records match this filter.
                    </td>
                </tr>
                <tr v-for="ticket in tickets" :key="ticket.sale_number">
                    <td>{{ ticket.sale_number }}</td>
                    <td>{{ ticket.borrower_name }}</td>
                    <td>
                        {{ formatDate(ticket.due_date) }}
                        <span
                            v-if="isOverdue(ticket)"
                            class="sales-report-flag sales-report-flag--muted"
                        >
                            Overdue
                        </span>
                    </td>
                    <td>
                        <div v-for="item in ticket.items" :key="item.id">
                            {{ item.product?.name || "Product" }}
                            × {{ item.quantity_display || item.quantity }}
                            {{ item.unit_type || "" }}
                        </div>
                    </td>
                    <td>{{ ticket.total.toFixed(2) }}</td>
                    <td>{{ ticket.paid_at ? "Paid" : "Unpaid" }}</td>
                    <td>
                        <Button
                            v-if="!ticket.paid_at"
                            class="products-action-btn"
                            :disabled="payingNumber === ticket.sale_number"
                            @click="markPaid(ticket)"
                        >
                            {{
                                payingNumber === ticket.sale_number
                                    ? "Saving..."
                                    : "Mark paid"
                            }}
                        </Button>
                        <span v-else>{{ formatDate(ticket.paid_at) }}</span>
                    </td>
                </tr>
            </Table>
        </section>
    </AppLayout>
</template>
