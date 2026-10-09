<script setup>
import { Head } from "@inertiajs/vue3";
import { CircleCheck } from "lucide-vue-next";
import { computed, onMounted, ref, watch } from "vue";
import { toast } from "vue-sonner";
import AppLayout from "../components/layout/AppLayout.vue";
import Button from "../components/ui/Button.vue";
import Modal from "../components/ui/Modal.vue";
import Table from "../components/ui/Table.vue";
import api from "../services/api";

const sales = ref([]);
const statusFilter = ref("unpaid");
const searchQuery = ref("");
const payingNumber = ref("");
const selectedTicket = ref(null);
const pendingTicket = ref(null);
const pinnedPaid = ref([]);

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
                borrower_phone: sale.borrower_phone || "",
                due_date: sale.due_date,
                paid_at: sale.paid_at,
                valid_id_url: sale.valid_id_url || "",
                items: [],
                total: 0,
            });
        }

        const ticket = grouped.get(key);
        ticket.items.push(sale);
        ticket.total += Number(sale.total_price || 0);

        if (!ticket.borrower_phone && sale.borrower_phone) {
            ticket.borrower_phone = sale.borrower_phone;
        }

        if (!ticket.valid_id_url && sale.valid_id_url) {
            ticket.valid_id_url = sale.valid_id_url;
        }

        if (!sale.paid_at) {
            ticket.paid_at = null;
        }
    });

    return Array.from(grouped.values()).filter((ticket) => {
        const unpaid = !ticket.paid_at;

        if (
            statusFilter.value === "unpaid" &&
            !unpaid &&
            !pinnedPaid.value.includes(ticket.sale_number)
        ) {
            return false;
        }

        if (statusFilter.value === "paid" && unpaid) {
            return false;
        }

        if (
            statusFilter.value === "overdue" &&
            !isOverdue(ticket) &&
            !pinnedPaid.value.includes(ticket.sale_number)
        ) {
            return false;
        }

        if (!keyword) {
            return true;
        }

        const haystack = [
            ticket.sale_number,
            ticket.borrower_name,
            ticket.borrower_phone,
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

const openTicket = (ticket) => {
    selectedTicket.value = ticket;
};

const closeTicket = () => {
    selectedTicket.value = null;
};

const askMarkPaid = (ticket) => {
    if (payingNumber.value) {
        return;
    }

    pendingTicket.value = ticket;
};

const closeConfirm = () => {
    if (payingNumber.value) {
        return;
    }

    pendingTicket.value = null;
};

const markPaid = async () => {
    const ticket = pendingTicket.value;

    if (!ticket || payingNumber.value) {
        return;
    }

    payingNumber.value = ticket.sale_number;

    try {
        await api.post("/utang/pay", { sale_number: ticket.sale_number });
        pinnedPaid.value = [...pinnedPaid.value, ticket.sale_number];
        pendingTicket.value = null;
        toast.success("Utang marked as paid.");
        await loadUtang();
    } catch (error) {
        toast.error(error?.response?.data?.message || "Could not mark this utang as paid.");
    } finally {
        payingNumber.value = "";
    }
};

const undoPaid = async (ticket) => {
    if (payingNumber.value) {
        return;
    }

    payingNumber.value = ticket.sale_number;

    try {
        await api.post("/utang/undo", { sale_number: ticket.sale_number });
        pinnedPaid.value = pinnedPaid.value.filter(
            (saleNumber) => saleNumber !== ticket.sale_number,
        );
        toast.success("Utang payment undone.");
        await loadUtang();
    } catch (error) {
        toast.error(error?.response?.data?.message || "Could not undo this payment.");
    } finally {
        payingNumber.value = "";
    }
};

watch(statusFilter, () => {
    pinnedPaid.value = [];
});

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
                    <td>
                        <div>{{ ticket.borrower_name }}</div>
                        <div v-if="ticket.borrower_phone" class="utang-phone">
                            {{ ticket.borrower_phone }}
                        </div>
                    </td>
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
                        <div class="utang-actions">
                            <Button
                                variant="outline"
                                class="products-action-btn"
                                @click="openTicket(ticket)"
                            >
                                Details
                            </Button>
                            <Button
                                v-if="!ticket.paid_at"
                                class="products-action-btn"
                                :disabled="payingNumber === ticket.sale_number"
                                @click="askMarkPaid(ticket)"
                            >
                                Mark paid
                            </Button>
                            <template v-else>
                                <span class="utang-paid-on">{{
                                    formatDate(ticket.paid_at)
                                }}</span>
                                <Button
                                    variant="outline"
                                    class="products-action-btn"
                                    :disabled="payingNumber === ticket.sale_number"
                                    @click="undoPaid(ticket)"
                                >
                                    {{
                                        payingNumber === ticket.sale_number
                                            ? "Saving..."
                                            : "Undo"
                                    }}
                                </Button>
                            </template>
                        </div>
                    </td>
                </tr>
            </Table>
        </section>

        <Modal
            :open="selectedTicket !== null"
            :title="selectedTicket ? `Utang ${selectedTicket.sale_number}` : 'Utang'"
            @close="closeTicket"
        >
            <div v-if="selectedTicket" class="utang-detail">
                <div class="retail-view__grid">
                    <div class="retail-view__item">
                        <span>Borrower</span>
                        <strong>{{ selectedTicket.borrower_name }}</strong>
                    </div>
                    <div class="retail-view__item">
                        <span>Phone</span>
                        <strong>{{ selectedTicket.borrower_phone || "-" }}</strong>
                    </div>
                    <div class="retail-view__item">
                        <span>Due date</span>
                        <strong>{{ formatDate(selectedTicket.due_date) }}</strong>
                    </div>
                    <div class="retail-view__item">
                        <span>Amount</span>
                        <strong>{{ selectedTicket.total.toFixed(2) }}</strong>
                    </div>
                </div>
                <div class="retail-view__item">
                    <span>Valid ID</span>
                    <img
                        v-if="selectedTicket.valid_id_url"
                        :src="selectedTicket.valid_id_url"
                        alt="Borrower valid ID"
                        class="utang-valid-id"
                    />
                </div>
            </div>
        </Modal>

        <Modal
            :open="pendingTicket !== null"
            title="Mark utang as paid"
            @close="closeConfirm"
        >
            <div v-if="pendingTicket" class="products-delete-confirm">
                <div class="products-delete-confirm__head">
                    <CircleCheck class="products-delete-confirm__icon utang-confirm-icon" />
                    <p class="products-delete-confirm__title">
                        Mark this utang as paid?
                    </p>
                </div>
                <p class="products-delete-confirm__text">
                    Receipt
                    <strong>{{ pendingTicket.sale_number }}</strong>
                    for
                    <strong>{{ pendingTicket.borrower_name }}</strong>
                    will be recorded as paid.
                </p>
                <p class="products-delete-confirm__text">
                    Amount
                    <strong>{{ pendingTicket.total.toFixed(2) }}</strong>
                </p>
                <div class="form-actions products-delete-confirm__actions">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="payingNumber === pendingTicket.sale_number"
                        @click="closeConfirm"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        :disabled="payingNumber === pendingTicket.sale_number"
                        @click="markPaid"
                    >
                        {{
                            payingNumber === pendingTicket.sale_number
                                ? "Saving..."
                                : "Confirm"
                        }}
                    </Button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
