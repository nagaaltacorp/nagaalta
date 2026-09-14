<script setup>
import { Head, Link, usePage } from "@inertiajs/vue3";
import { Eye, Filter, Search, SquarePen, Trash2, TriangleAlert } from "lucide-vue-next";
import { computed, onBeforeUnmount, onMounted, reactive, ref } from "vue";
import { toast } from "vue-sonner";
import AppLayout from "../components/layout/AppLayout.vue";
import Button from "../components/ui/Button.vue";
import Modal from "../components/ui/Modal.vue";
import Table from "../components/ui/Table.vue";
import api from "../services/api";
import { displayQuantity, parseQuantity } from "../utils/quantity";

const page = usePage();

const canAdjustInventory = computed(() => {
    const role = page.props.auth?.user?.role ?? "staff";
    return role === "admin" || role === "manager";
});

const inventories = ref([]);
const revenueLogs = ref([]);
const mainStock = ref([]);
const branches = ref([]);
const showModal = ref(false);
const showDeleteModal = ref(false);
const showViewModal = ref(false);
const pendingDeleteItem = ref(null);
const viewingItem = ref(null);
const searchQuery = ref("");
const branchFilter = ref("all");
const statusFilter = ref("all");
const showRevenueLogs = ref(false);
const editingId = ref(null);
const isSaving = ref(false);
const isPolling = ref(false);
const pollIntervalMs = 15000;
let pollTimer = null;

const form = reactive({
    branch_id: "",
    product_id: "",
    quantity: "",
});

const loadData = async () => {
    const [inventoriesRes, logsRes, productsRes, branchesRes] =
        await Promise.all([
            api.get("/inventories"),
            api.get("/inventories/revenue-logs"),
            api.get("/inventories/main"),
            api.get("/branches"),
        ]);

    inventories.value = inventoriesRes.data.data;
    revenueLogs.value = logsRes.data.data;
    mainStock.value = productsRes.data.data ?? [];
    branches.value = branchesRes.data.data;
};

const refreshInventories = async () => {
    if (isPolling.value) {
        return;
    }

    isPolling.value = true;

    try {
        const requests = [api.get("/inventories"), api.get("/inventories/main")];

        if (showRevenueLogs.value) {
            requests.push(api.get("/inventories/revenue-logs"));
        }

        const [inventoriesRes, mainRes, logsRes] = await Promise.all(requests);

        inventories.value = inventoriesRes.data.data;
        mainStock.value = mainRes.data.data ?? [];

        if (logsRes?.data?.data) {
            revenueLogs.value = logsRes.data.data;
        }
    } finally {
        isPolling.value = false;
    }
};

const catalogProducts = computed(() =>
    [...mainStock.value]
        .filter((item) => item.product)
        .sort((a, b) =>
            String(a.product.name || "").localeCompare(
                String(b.product.name || ""),
            ),
        )
        .map((item) => ({
            id: item.product.id,
            name: item.product.name,
            unit: item.product.unit,
            available_quantity: Number(item.quantity || 0),
        })),
);

const availableMainQuantity = computed(() => {
    const productId = Number(form.product_id);

    if (!productId) {
        return 0;
    }

    const mainItem = mainStock.value.find(
        (item) => Number(item.product_id) === productId,
    );
    let available = Number(mainItem?.quantity || 0);

    if (editingId.value) {
        const current = inventories.value.find(
            (item) => item.id === editingId.value,
        );
        const currentProductId = Number(
            current?.product?.id ?? current?.product_id,
        );

        if (current && currentProductId === productId) {
            available += Number(current.quantity || 0);
        }
    }

    return available;
});

const branchOptions = computed(() =>
    [...branches.value].sort((a, b) => a.name.localeCompare(b.name)),
);

const statusOptions = computed(() => {
    const options = new Set();

    inventories.value.forEach((item) => {
        if (item.status) {
            options.add(item.status);
        }
    });

    return Array.from(options).sort((a, b) => a.localeCompare(b));
});

const formatStatusLabel = (status) =>
    String(status)
        .replace(/_/g, " ")
        .replace(/\b\w/g, (match) => match.toUpperCase());

const quantityNotes = (item) => {
    if (!item.product?.retail_enabled) {
        return [];
    }

    const retailUnit = item.product?.retail_unit || "kg";
    const leftover = Number(item.retail_remainder || 0);
    const cannotSell = parseQuantity(item.product?.retail_allowed_loss) ?? 0;
    const notes = [];

    if (leftover > 0) {
        notes.push({
            key: "leftover",
            text: `Leftover ${displayQuantity(leftover)} ${retailUnit}`,
        });
    }

    if (cannotSell > 0) {
        notes.push({
            key: "loss",
            text: `Cannot sell ${displayQuantity(cannotSell)} ${retailUnit}`,
        });
    }

    return notes;
};

const formatCurrency = (value) =>
    Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

const openView = (item) => {
    viewingItem.value = item;
    showViewModal.value = true;
};

const closeView = () => {
    showViewModal.value = false;
    viewingItem.value = null;
};

const viewDetails = computed(() => {
    const item = viewingItem.value;
    const product = item?.product;

    if (!item || !product) {
        return null;
    }

    const wholesaleUnit = product.unit || "unit";
    const retailUnit = product.retail_unit || "kg";
    const qtyPer = Number(product.retail_qty_per_unit) || 0;
    const wholesalePrice = Number(product.price) || 0;
    const retailPrice = Number(product.retail_price) || 0;
    const enabled = Boolean(product.retail_enabled) && qtyPer > 0;
    const qty = Number(item.quantity) || 0;
    const remainder = Number(item.retail_remainder) || 0;
    const retailTotal = enabled ? qty * qtyPer + remainder : 0;
    const allowedLoss = enabled
        ? Math.max(0, parseQuantity(product.retail_allowed_loss) ?? 0)
        : 0;
    const sellableRetail = Math.max(0, retailTotal - allowedLoss);

    return {
        item,
        product,
        wholesaleUnit,
        retailUnit,
        qtyPer,
        wholesalePrice,
        retailPrice,
        enabled,
        qty,
        remainder,
        retailTotal,
        allowedLoss: displayQuantity(allowedLoss),
        sellableRetail: displayQuantity(sellableRetail),
        conversion: enabled ? `1 ${wholesaleUnit} = ${qtyPer} ${retailUnit}` : "—",
        reverse: enabled
            ? `1 ${retailUnit} = ${(1 / qtyPer).toFixed(4)} ${wholesaleUnit}`
            : "—",
        examples: enabled
            ? [
                  `1 ${wholesaleUnit} = ${qtyPer} ${retailUnit}`,
                  `2 ${wholesaleUnit} = ${qtyPer * 2} ${retailUnit}`,
                  `5 ${wholesaleUnit} = ${qtyPer * 5} ${retailUnit}`,
                  `1 ${retailUnit} deducts 1/${qtyPer} ${wholesaleUnit}`,
              ]
            : [],
        equivalentWholesaleValue: enabled ? retailPrice * qtyPer : 0,
    };
});

const formatActionLabel = (action) => {
    if (!action) {
        return "-";
    }

    return action.charAt(0).toUpperCase() + action.slice(1);
};

const formatLogDate = (value) => {
    if (!value) {
        return "-";
    }

    return new Date(value).toLocaleString();
};

const formatRevenueLogOption = (log) => {
    if (!log) {
        return "";
    }

    const date = formatLogDate(log.created_at);
    const branch = log.branch?.name || "Unassigned";
    const batch = log.batch_number || "No batch";
    const product = log.product?.name || "Product";
    const expected = formatMoney(log.expected_revenue);

    return `${date} | ${branch} | ${batch} | ${product} | Expected ₱${expected}`;
};

const filteredInventories = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();

    return inventories.value.filter((item) => {
        const branchId = String(item.branch?.id ?? "");
        const matchesBranch =
            branchFilter.value === "all" || branchId === branchFilter.value;
        const matchesStatus =
            statusFilter.value === "all" || item.status === statusFilter.value;

        if (!matchesBranch || !matchesStatus) {
            return false;
        }

        if (!query) {
            return true;
        }

        const haystack = [
            item.branch?.name,
            item.branch?.location,
            item.product?.name,
            item.batch_number,
            item.status,
        ]
            .filter(Boolean)
            .join(" ")
            .toLowerCase();

        return haystack.includes(query);
    });
});

const inventoryColumns = [
    "Product",
    "Category",
    "Unit",
    "Batch",
    "Price",
    "Quantity",
    "Status",
    "Actions",
];

const groupedInventories = computed(() => {
    const groups = new Map();

    filteredInventories.value.forEach((item) => {
        const branchId = item.branch?.id;
        const key = branchId ? `branch-${branchId}` : "unassigned";

        if (!groups.has(key)) {
            groups.set(key, {
                key,
                name: item.branch?.name || "Unassigned",
                location: item.branch?.location || "",
                sortOrder: branchId ? 1 : 2,
                items: [],
            });
        }

        groups.get(key).items.push(item);
    });

    return Array.from(groups.values())
        .map((group) => {
            group.items.sort((a, b) =>
                (a.product?.name || "").localeCompare(b.product?.name || ""),
            );
            return group;
        })
        .sort((a, b) => {
            if (a.sortOrder !== b.sortOrder) {
                return a.sortOrder - b.sortOrder;
            }

            return a.name.localeCompare(b.name);
        });
});

const formatMoney = (value) => {
    const amount = Number(value);

    if (!Number.isFinite(amount)) {
        return "0.00";
    }

    return amount.toFixed(2);
};

const filteredRevenueLogs = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();

    return revenueLogs.value.filter((log) => {
        const branchId = String(log.branch?.id ?? "");
        const matchesBranch =
            branchFilter.value === "all" || branchId === branchFilter.value;

        if (!matchesBranch) {
            return false;
        }

        if (!query) {
            return true;
        }

        const haystack = [
            log.branch?.name,
            log.branch?.location,
            log.product?.name,
            log.batch_number,
            log.action,
        ]
            .filter(Boolean)
            .join(" ")
            .toLowerCase();

        return haystack.includes(query);
    });
});

const revenueLogOptions = computed(() => {
    return [...filteredRevenueLogs.value].sort((a, b) => {
        const aTime = a.created_at ? Date.parse(a.created_at) : 0;
        const bTime = b.created_at ? Date.parse(b.created_at) : 0;

        return bTime - aTime;
    });
});

const resetForm = () => {
    form.branch_id = "";
    form.product_id = "";
    form.quantity = "";
    editingId.value = null;
};

const openCreate = () => {
    resetForm();

    if (branchFilter.value !== "all") {
        form.branch_id = branchFilter.value;
    }

    showModal.value = true;
};

const openEdit = (item) => {
    form.branch_id = item.branch?.id ? String(item.branch.id) : "";
    form.product_id = item.product?.id ? String(item.product.id) : "";
    form.quantity = item.quantity ?? "";
    editingId.value = item.id;
    showModal.value = true;
};

const saveInventory = async () => {
    if (isSaving.value) {
        return;
    }

    isSaving.value = true;
    const wasEditing = Boolean(editingId.value);
    const payload = {
        branch_id: Number(form.branch_id),
        product_id: Number(form.product_id),
        quantity: Number(form.quantity),
    };

    try {
        if (wasEditing) {
            await api.put(`/inventories/${editingId.value}`, payload);
        } else {
            await api.post("/inventories", payload);
        }

        showModal.value = false;
        resetForm();
        await loadData();
        toast.success(
            wasEditing
                ? "Inventory updated. Main Inventory stock adjusted."
                : "Stock transferred from Main Inventory.",
        );
    } catch (err) {
        toast.error(err.response?.data?.message ?? "Failed to save inventory.");
    } finally {
        isSaving.value = false;
    }
};

const requestDelete = (item) => {
    pendingDeleteItem.value = item;
    showDeleteModal.value = true;
};

const closeDeleteModal = () => {
    showDeleteModal.value = false;
    pendingDeleteItem.value = null;
};

const confirmDelete = async () => {
    if (!pendingDeleteItem.value?.id) {
        closeDeleteModal();
        return;
    }

    try {
        await api.delete(`/inventories/${pendingDeleteItem.value.id}`);
        closeDeleteModal();
        await loadData();
        toast.success(
            "Inventory deleted. Quantity returned to Main Inventory.",
        );
    } catch (err) {
        toast.error(
            err.response?.data?.message ?? "Failed to delete inventory.",
        );
    }
};

onMounted(() => {
    loadData();
    pollTimer = window.setInterval(refreshInventories, pollIntervalMs);
});

onBeforeUnmount(() => {
    if (pollTimer) {
        window.clearInterval(pollTimer);
        pollTimer = null;
    }
});
</script>

<template>
    <Head title="Branch Inventory" />

    <AppLayout title="Branch Inventory">
        <div class="products-page inventories-page">
            <section class="dashboard-surface-card">
            <h2 class="panel-title">Branch Inventory</h2>
            <div class="products-toolbar">
                    <div class="products-controls">
                        <label
                            class="products-control products-control--search"
                        >
                            <Search class="products-control-icon" />
                            <input
                                v-model="searchQuery"
                                class="input"
                                type="text"
                                placeholder="Search product, batch, status"
                            />
                        </label>

                        <label class="products-control">
                            <Filter class="products-control-icon" />
                            <select v-model="branchFilter" class="input">
                                <option value="all">All branches</option>
                                <option
                                    v-for="branch in branchOptions"
                                    :key="branch.id"
                                    :value="String(branch.id)"
                                >
                                    {{ branch.name }}
                                </option>
                            </select>
                        </label>

                        <label class="products-control">
                            <Filter class="products-control-icon" />
                            <select v-model="statusFilter" class="input">
                                <option value="all">All statuses</option>
                                <option
                                    v-for="status in statusOptions"
                                    :key="status"
                                    :value="status"
                                >
                                    {{ formatStatusLabel(status) }}
                                </option>
                            </select>
                        </label>
                    </div>

                    <div class="products-toolbar-actions">
                        <Button
                            variant="outline"
                            class="revenue-logs-btn"
                            @click="showRevenueLogs = true"
                        >
                            Expected Revenue Logs
                            <span class="revenue-logs-btn__count">
                                {{ revenueLogOptions.length }}
                            </span>
                        </Button>
                        <Button
                            v-if="canAdjustInventory"
                            class="products-add-btn"
                            @click="openCreate"
                        >
                            Add stock
                        </Button>
                    </div>
                </div>

                <Table
                    v-if="groupedInventories.length === 0"
                    :columns="inventoryColumns"
                >
                    <tr>
                        <td class="products-empty" colspan="8">
                            No inventory matches your search/filters.
                        </td>
                    </tr>
                </Table>

                <section
                    v-for="group in groupedInventories"
                    :key="group.key"
                    class="inventory-branch"
                >
                    <header class="inventory-branch__head">
                        <div>
                            <h4 class="inventory-branch__title">
                                {{ group.name }}
                            </h4>
                            <p
                                v-if="group.location"
                                class="inventory-branch__meta"
                            >
                                {{ group.location }}
                            </p>
                        </div>
                        <span class="inventory-branch__count">
                            {{ group.items.length }} products
                        </span>
                    </header>

                    <Table :columns="inventoryColumns">
                        <tr v-for="item in group.items" :key="item.id">
                            <td>
                                <div class="inventory-product">
                                    <img
                                        v-if="item.product?.image"
                                        :src="item.product.image"
                                        :alt="
                                            item.product?.name ||
                                            'Product image'
                                        "
                                        class="inventory-product__image"
                                    />
                                    <span
                                        v-else
                                        class="inventory-product__placeholder"
                                    >
                                        -
                                    </span>
                                    <span class="inventory-product__name">
                                        {{ item.product?.name || "-" }}
                                    </span>
                                </div>
                            </td>
                            <td>
                                <span
                                    v-if="item.product?.category"
                                    class="category-tag"
                                >
                                    {{ item.product.category }}
                                </span>
                                <span v-else>-</span>
                            </td>
                            <td>{{ item.product?.unit || "-" }}</td>
                            <td>{{ item.batch_number || "-" }}</td>
                            <td>{{ item.product?.price ?? "-" }}</td>
                            <td>
                                <div class="inventory-qty">
                                    <span class="inventory-qty__main">
                                        {{ item.quantity }}
                                    </span>
                                    <span
                                        v-for="note in quantityNotes(item)"
                                        :key="note.key"
                                        class="inventory-qty__note"
                                        :class="{
                                            'inventory-qty__note--loss':
                                                note.key === 'loss',
                                        }"
                                    >
                                        {{ note.text }}
                                    </span>
                                </div>
                            </td>
                            <td>{{ formatStatusLabel(item.status) }}</td>
                            <td class="actions">
                                <Button
                                    variant="outline"
                                    class="products-action-btn"
                                    @click="openView(item)"
                                >
                                    <Eye class="products-btn-icon" />
                                    <span>View</span>
                                </Button>
                                <Button
                                    variant="outline"
                                    class="products-action-btn"
                                    @click="openEdit(item)"
                                >
                                    <SquarePen class="products-btn-icon" />
                                    <span>Edit</span>
                                </Button>
                                <Button
                                    variant="danger"
                                    class="products-action-btn products-action-btn--danger"
                                    @click="requestDelete(item)"
                                >
                                    <Trash2 class="products-btn-icon" />
                                    <span>Delete</span>
                                </Button>
                            </td>
                        </tr>
                    </Table>
                </section>

            </section>
            <Modal
                :open="showModal"
                :title="editingId ? 'Edit Inventory' : 'Add stock'"
                @close="showModal = false"
            >
                <form class="form-grid" @submit.prevent="saveInventory">
                    <label class="form-field">
                        <span class="form-field__label">Branch</span>
                        <select v-model="form.branch_id" class="input" required>
                            <option value="">Select branch</option>
                            <option
                                v-for="branch in branchOptions"
                                :key="branch.id"
                                :value="branch.id"
                            >
                                {{ branch.name }} - {{ branch.location }}
                            </option>
                        </select>
                    </label>
                    <label class="form-field">
                        <span class="form-field__label">
                            Product (Main Inventory)
                        </span>
                        <select
                            v-model="form.product_id"
                            class="input"
                            required
                            :disabled="catalogProducts.length === 0"
                        >
                            <option value="">
                                {{
                                    catalogProducts.length === 0
                                        ? "No products in Main Inventory"
                                        : "Select a product from Main Inventory"
                                }}
                            </option>
                            <option
                                v-for="product in catalogProducts"
                                :key="product.id"
                                :value="product.id"
                            >
                                {{ product.name }}
                                <template v-if="product.unit">
                                    ({{ product.unit }})
                                </template>
                                — {{ product.available_quantity }} available
                            </option>
                        </select>
                        <p class="form-hint">
                            Quantity added here is deducted from
                            <Link href="/main-inventory">Main Inventory</Link>.
                        </p>
                    </label>
                    <label class="form-field">
                        <span class="form-field__label">Quantity</span>
                        <input
                            v-model="form.quantity"
                            class="input"
                            type="number"
                            min="0"
                            :max="availableMainQuantity"
                            placeholder="0"
                            required
                        />
                        <p v-if="form.product_id" class="form-hint">
                            Available in Main Inventory:
                            {{ availableMainQuantity }}
                        </p>
                    </label>
                    <div class="form-actions">
                        <Button
                            type="submit"
                            :disabled="
                                isSaving ||
                                catalogProducts.length === 0 ||
                                Number(form.quantity) >
                                    availableMainQuantity
                            "
                        >
                            {{ isSaving ? "Saving..." : "Save" }}
                        </Button>
                    </div>
                </form>
            </Modal>

            <Modal
                :open="showDeleteModal"
                title="Delete Inventory"
                @close="closeDeleteModal"
            >
                <div class="products-delete-confirm">
                    <div class="products-delete-confirm__head">
                        <TriangleAlert class="products-delete-confirm__icon" />
                        <p class="products-delete-confirm__title">
                            Delete this inventory record?
                        </p>
                    </div>

                    <p class="products-delete-confirm__text">
                        Product:
                        <strong>
                            {{ pendingDeleteItem?.product?.name || "-" }}
                        </strong>
                    </p>
                    <p class="products-delete-confirm__text">
                        Quantity
                        <strong>
                            {{ pendingDeleteItem?.quantity ?? 0 }}
                        </strong>
                        will be returned to Main Inventory. This action cannot
                        be undone.
                    </p>

                    <div class="form-actions products-delete-confirm__actions">
                        <Button
                            type="button"
                            variant="outline"
                            @click="closeDeleteModal"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="danger"
                            @click="confirmDelete"
                        >
                            Delete
                        </Button>
                    </div>
                </div>
            </Modal>
        </div>

        <Modal
            :open="showViewModal"
            :title="viewingItem?.product?.name || 'Product details'"
            @close="closeView"
        >
            <div v-if="viewDetails" class="retail-view">
                <div class="retail-view__hero">
                    <img
                        v-if="viewDetails.product.image"
                        :src="viewDetails.product.image"
                        :alt="viewDetails.product.name"
                        class="retail-view__image"
                    />
                    <div
                        v-else
                        class="retail-view__image retail-view__image--empty"
                    >
                        No image
                    </div>
                    <div class="retail-view__identity">
                        <p class="retail-view__name">
                            {{ viewDetails.product.name }}
                        </p>
                        <p class="retail-view__meta">
                            {{ viewDetails.product.category || "Uncategorized" }}
                            · {{ viewingItem.branch?.name || "Unassigned" }}
                        </p>
                        <p
                            class="retail-view__status"
                            :class="{
                                'retail-view__status--on': viewDetails.enabled,
                            }"
                        >
                            {{
                                viewDetails.enabled
                                    ? "Retail enabled"
                                    : "Retail not set up"
                            }}
                        </p>
                    </div>
                </div>

                <div class="retail-view__grid">
                    <div class="retail-view__item">
                        <span>Wholesale unit</span>
                        <strong>{{ viewDetails.wholesaleUnit }}</strong>
                    </div>
                    <div class="retail-view__item">
                        <span>Wholesale price</span>
                        <strong>
                            ₱{{ formatCurrency(viewDetails.wholesalePrice) }}
                            / {{ viewDetails.wholesaleUnit }}
                        </strong>
                    </div>
                    <div class="retail-view__item">
                        <span>Batch</span>
                        <strong>{{ viewingItem.batch_number || "-" }}</strong>
                    </div>
                    <div class="retail-view__item">
                        <span>Status</span>
                        <strong>
                            {{ formatStatusLabel(viewingItem.status) }}
                        </strong>
                    </div>
                    <div class="retail-view__item">
                        <span>Retail unit</span>
                        <strong>{{ viewDetails.retailUnit }}</strong>
                    </div>
                    <div class="retail-view__item">
                        <span>Retail price</span>
                        <strong>
                            ₱{{ formatCurrency(viewDetails.retailPrice) }}
                            / {{ viewDetails.retailUnit }}
                        </strong>
                    </div>
                </div>

                <section class="retail-view__section">
                    <h4>Actual conversion</h4>
                    <p class="retail-view__conversion">
                        {{ viewDetails.conversion }}
                    </p>
                    <p v-if="viewDetails.enabled" class="retail-view__reverse">
                        {{ viewDetails.reverse }}
                    </p>
                    <ul
                        v-if="viewDetails.examples.length"
                        class="retail-view__list"
                    >
                        <li
                            v-for="example in viewDetails.examples"
                            :key="example"
                        >
                            {{ example }}
                        </li>
                    </ul>
                    <p
                        v-if="viewDetails.enabled && viewDetails.retailPrice"
                        class="retail-view__note"
                    >
                        {{ viewDetails.qtyPer }}
                        {{ viewDetails.retailUnit }} at ₱{{
                            formatCurrency(viewDetails.retailPrice)
                        }}
                        each = ₱{{
                            formatCurrency(viewDetails.equivalentWholesaleValue)
                        }}
                        (wholesale is ₱{{
                            formatCurrency(viewDetails.wholesalePrice)
                        }}
                        / {{ viewDetails.wholesaleUnit }})
                    </p>
                    <p v-else-if="!viewDetails.enabled" class="retail-view__empty">
                        Set this product up in Retail Setup to see unit
                        conversion.
                    </p>
                </section>

                <section class="retail-view__section">
                    <h4>This branch stock</h4>
                    <div class="retail-view__grid">
                        <div class="retail-view__item">
                            <span>Wholesale quantity</span>
                            <strong>
                                {{ viewDetails.qty }}
                                {{ viewDetails.wholesaleUnit }}
                            </strong>
                        </div>
                        <div class="retail-view__item">
                            <span>Leftover from opened sack</span>
                            <strong>
                                {{ displayQuantity(viewDetails.remainder) }}
                                {{ viewDetails.retailUnit }}
                            </strong>
                        </div>
                        <div class="retail-view__item">
                            <span>Total in retail units</span>
                            <strong>
                                {{
                                    viewDetails.enabled
                                        ? `${viewDetails.retailTotal} ${viewDetails.retailUnit}`
                                        : "—"
                                }}
                            </strong>
                        </div>
                        <div class="retail-view__item">
                            <span>Cannot sell (display / mice)</span>
                            <strong>
                                {{
                                    viewDetails.enabled
                                        ? `${viewDetails.allowedLoss} ${viewDetails.retailUnit}`
                                        : "—"
                                }}
                            </strong>
                        </div>
                        <div class="retail-view__item">
                            <span>Can sell</span>
                            <strong>
                                {{
                                    viewDetails.enabled
                                        ? `${viewDetails.sellableRetail} ${viewDetails.retailUnit}`
                                        : "—"
                                }}
                            </strong>
                        </div>
                    </div>
                </section>

                <div class="form-actions">
                    <Button type="button" variant="outline" @click="closeView">
                        Close
                    </Button>
                </div>
            </div>
        </Modal>

        <Modal
            :open="showRevenueLogs"
            title="Expected Revenue Logs"
            @close="showRevenueLogs = false"
        >
            <div class="revenue-logs-list">
                <p
                    v-if="revenueLogOptions.length === 0"
                    class="revenue-logs-empty"
                >
                    No revenue logs found.
                </p>
                <div
                    v-for="log in revenueLogOptions"
                    :key="log.id"
                    class="revenue-log-item"
                >
                    <div class="revenue-log-item__top">
                        <span class="revenue-log-item__product">
                            {{ log.product?.name || "Product" }}
                        </span>
                        <span class="revenue-log-item__revenue">
                            ₱{{ formatMoney(log.expected_revenue) }}
                        </span>
                    </div>
                    <div class="revenue-log-item__bottom">
                        <span>{{ log.branch?.name || "Unassigned" }}</span>
                        <span>Batch: {{ log.batch_number || "-" }}</span>
                        <span>Qty: {{ log.quantity }}</span>
                        <span>{{ formatLogDate(log.created_at) }}</span>
                    </div>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
