<script setup>
import { Head, Link, usePage } from "@inertiajs/vue3";
import {
    Check,
    ChevronLeft,
    ChevronRight,
    Filter,
    PackagePlus,
    Search,
    SquarePen,
    TriangleAlert,
    Trash2,
} from "lucide-vue-next";
import { computed, nextTick, onMounted, reactive, ref, watch } from "vue";
import { toast } from "vue-sonner";
import AppLayout from "../components/layout/AppLayout.vue";
import Button from "../components/ui/Button.vue";
import Input from "../components/ui/Input.vue";
import Modal from "../components/ui/Modal.vue";
import Table from "../components/ui/Table.vue";
import api from "../services/api";
import { displayQuantity, parseQuantity } from "../utils/quantity";

const page = usePage();
const canAdjustInventory = computed(() => {
    const role = page.props.auth?.user?.role ?? "staff";
    return role === "admin" || role === "manager";
});

const items = ref([]);
const setupProducts = ref([]);
const searchQuery = ref("");
const statusFilter = ref("all");
const currentPage = ref(1);
const pageSize = 15;
const showModal = ref(false);
const showDeleteModal = ref(false);
const editingId = ref(null);
const pendingDeleteItem = ref(null);
const isSaving = ref(false);
const productSearchQuery = ref("");
const productSearchInput = ref(null);

const form = reactive({
    product_id: "",
    quantity: "",
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

const formatMoney = (value) => Number(value || 0).toFixed(2);

const addedProductIds = computed(
    () => new Set(items.value.map((item) => Number(item.product_id))),
);

const isAlreadyInMain = (product) =>
    addedProductIds.value.has(Number(product.id));

const availableSetupProducts = computed(() => {
    const list = setupProducts.value.filter((product) => {
        if (editingId.value) {
            const current = items.value.find(
                (item) => item.id === editingId.value,
            );

            return Number(product.id) === Number(current?.product_id);
        }

        return true;
    });

    return [...list].sort((a, b) => {
        const addedCmp = Number(isAlreadyInMain(a)) - Number(isAlreadyInMain(b));

        if (addedCmp !== 0) {
            return addedCmp;
        }

        const nameCmp = String(a.name || "").localeCompare(
            String(b.name || ""),
        );

        if (nameCmp !== 0) {
            return nameCmp;
        }

        return String(a.unit || "").localeCompare(String(b.unit || ""));
    });
});

const filteredSetupProducts = computed(() => {
    const query = productSearchQuery.value.trim().toLowerCase();

    if (!query) {
        return availableSetupProducts.value;
    }

    return availableSetupProducts.value.filter((product) => {
        const haystack = [product.name, product.category, product.unit]
            .filter(Boolean)
            .join(" ")
            .toLowerCase();

        return haystack.includes(query);
    });
});

const selectedProduct = computed(() => {
    if (editingId.value) {
        const current = items.value.find((item) => item.id === editingId.value);

        return current?.product ?? null;
    }

    return (
        setupProducts.value.find(
            (product) => String(product.id) === String(form.product_id),
        ) ?? null
    );
});

const filteredRows = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();

    return items.value.filter((item) => {
        if (statusFilter.value !== "all" && item.status !== statusFilter.value) {
            return false;
        }

        if (!query) {
            return true;
        }

        const product = item.product ?? {};
        const haystack = [
            product.name,
            product.category,
            product.unit,
            item.status,
        ]
            .filter(Boolean)
            .join(" ")
            .toLowerCase();

        return haystack.includes(query);
    });
});

const totalPages = computed(() =>
    Math.max(1, Math.ceil(filteredRows.value.length / pageSize)),
);

const paginatedRows = computed(() => {
    const start = (currentPage.value - 1) * pageSize;

    return filteredRows.value.slice(start, start + pageSize);
});

const paginationFrom = computed(() => {
    if (filteredRows.value.length === 0) {
        return 0;
    }

    return (currentPage.value - 1) * pageSize + 1;
});

const paginationTo = computed(() =>
    Math.min(currentPage.value * pageSize, filteredRows.value.length),
);

const pageNumbers = computed(() => {
    const pages = [];

    for (let i = 1; i <= totalPages.value; i += 1) {
        pages.push(i);
    }

    return pages;
});

const goToPage = (pageNo) => {
    currentPage.value = Math.min(Math.max(pageNo, 1), totalPages.value);
};

const loadData = async () => {
    const [inventoryRes, productsRes] = await Promise.all([
        api.get("/inventories/main"),
        api.get("/products", { params: { catalog: 1 } }),
    ]);

    items.value = inventoryRes.data.data ?? [];
    setupProducts.value = productsRes.data.data ?? [];
};

const resetForm = () => {
    form.product_id = "";
    form.quantity = "";
    editingId.value = null;
    productSearchQuery.value = "";
};

const selectProduct = (product) => {
    if (isAlreadyInMain(product)) {
        return;
    }

    form.product_id = String(product.id);
};

const selectFirstMatch = () => {
    const firstAvailable = filteredSetupProducts.value.find(
        (product) => !isAlreadyInMain(product),
    );

    if (firstAvailable) {
        selectProduct(firstAvailable);
    }
};

const openCreate = () => {
    resetForm();
    showModal.value = true;
    nextTick(() => productSearchInput.value?.focus());
};

const openEdit = (item) => {
    editingId.value = item.id;
    form.product_id = item.product_id ? String(item.product_id) : "";
    form.quantity = String(item.quantity ?? "");
    showModal.value = true;
};

const saveItem = async () => {
    if (isSaving.value) {
        return;
    }

    if (!editingId.value && !form.product_id) {
        toast.error("Select a product first.");
        return;
    }

    isSaving.value = true;

    try {
        if (editingId.value) {
            await api.put(`/inventories/main/${editingId.value}`, {
                quantity: Number(form.quantity),
            });
            toast.success("Main Inventory updated.");
        } else {
            await api.post("/inventories/main", {
                product_id: Number(form.product_id),
                quantity: Number(form.quantity),
            });
            toast.success("Product added to Main Inventory.");
        }

        showModal.value = false;
        resetForm();
        await loadData();
    } catch (error) {
        toast.error(
            error?.response?.data?.message ||
                "Unable to save Main Inventory. Please try again.",
        );
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
        await api.delete(`/inventories/main/${pendingDeleteItem.value.id}`);
        toast.success("Product removed from Main Inventory.");
        closeDeleteModal();
        await loadData();
    } catch (error) {
        toast.error(
            error?.response?.data?.message ||
                "Unable to remove this product from Main Inventory.",
        );
    }
};

watch([searchQuery, statusFilter], () => {
    currentPage.value = 1;
});

watch([() => form.product_id, filteredSetupProducts], () => {
    if (!form.product_id) {
        return;
    }

    nextTick(() => {
        document
            .querySelector(".product-picker__item--selected")
            ?.scrollIntoView({ block: "nearest" });
    });
});

watch(totalPages, (pages) => {
    if (currentPage.value > pages) {
        currentPage.value = pages;
    }
});

onMounted(loadData);
</script>

<template>
    <Head title="Main Inventory" />

    <AppLayout title="Main Inventory">
        <div class="products-page inventories-page">
            <section class="dashboard-surface-card">
            <h2 class="panel-title">Main Inventory</h2>
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
                                placeholder="Search product, category, unit"
                            />
                        </label>

                        <label class="products-control">
                            <Filter class="products-control-icon" />
                            <select v-model="statusFilter" class="input">
                                <option value="all">All statuses</option>
                                <option value="in_stock">In Stock</option>
                                <option value="out_of_stock">
                                    Out of Stock
                                </option>
                            </select>
                        </label>
                    </div>

                    <Button
                        v-if="canAdjustInventory"
                        class="products-add-btn"
                        @click="openCreate"
                    >
                        <span class="products-add-btn__icon-box">
                            <PackagePlus
                                class="products-btn-icon products-btn-icon--add"
                            />
                        </span>
                        <span>Add Product</span>
                    </Button>
                </div>

                <Table
                    :columns="[
                        'Product',
                        'Category',
                        'Unit',
                        'Price',
                        'Quantity',
                        'Status',
                        'Actions',
                    ]"
                >
                    <tr v-if="filteredRows.length === 0">
                        <td class="products-empty" colspan="7">
                            No products in Main Inventory yet. Add products from
                            Products Setup first.
                        </td>
                    </tr>

                    <tr v-for="item in paginatedRows" :key="item.id">
                        <td>
                            <div class="inventory-product">
                                <img
                                    v-if="item.product?.image"
                                    :src="item.product.image"
                                    :alt="item.product?.name"
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
                        <td>{{ formatMoney(item.product?.price) }}</td>
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
                        <td>
                            <div v-if="canAdjustInventory" class="actions">
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
                            </div>
                            <span v-else>-</span>
                        </td>
                    </tr>
                </Table>

                <div
                    v-if="filteredRows.length > 0"
                    class="products-pagination"
                >
                    <p class="products-pagination-info">
                        Showing {{ paginationFrom }}-{{ paginationTo }} of
                        {{ filteredRows.length }} products
                    </p>

                    <div class="products-pagination-controls">
                        <button
                            type="button"
                            class="products-page-btn"
                            :disabled="currentPage === 1"
                            @click="goToPage(currentPage - 1)"
                        >
                            <ChevronLeft class="products-btn-icon" />
                            <span>Prev</span>
                        </button>

                        <button
                            v-for="pageNo in pageNumbers"
                            :key="pageNo"
                            type="button"
                            class="products-page-btn products-page-btn--number"
                            :class="{
                                'products-page-btn--active':
                                    currentPage === pageNo,
                            }"
                            @click="goToPage(pageNo)"
                        >
                            {{ pageNo }}
                        </button>

                        <button
                            type="button"
                            class="products-page-btn"
                            :disabled="currentPage === totalPages"
                            @click="goToPage(currentPage + 1)"
                        >
                            <span>Next</span>
                            <ChevronRight class="products-btn-icon" />
                        </button>
                    </div>
                </div>

            </section>
            <Modal
                :open="showModal"
                :title="
                    editingId
                        ? 'Edit Main Inventory'
                        : 'Add to Main Inventory'
                "
                landscape
                @close="showModal = false"
            >
                <form class="inventory-landscape" @submit.prevent="saveItem">
                    <div class="inventory-landscape__body">
                    <div class="form-field product-picker-field">
                        <span class="form-field__label">
                            Product (Products Setup)
                        </span>

                        <div
                            v-if="editingId"
                            class="product-picker__locked"
                        >
                            {{ selectedProduct?.name || "-" }}
                            ({{ selectedProduct?.unit || "-" }})
                        </div>

                        <div v-else class="product-picker">
                            <label class="product-picker__search">
                                <Search class="products-control-icon" />
                                <input
                                    ref="productSearchInput"
                                    v-model="productSearchQuery"
                                    class="input"
                                    type="text"
                                    placeholder="Search product by name, category, or unit"
                                    autocomplete="off"
                                    :disabled="
                                        availableSetupProducts.length === 0
                                    "
                                    @keydown.enter.prevent="selectFirstMatch"
                                />
                            </label>

                            <p
                                v-if="availableSetupProducts.length === 0"
                                class="product-picker__empty"
                            >
                                No products available from Products Setup
                            </p>

                            <div
                                v-else
                                class="product-picker__list"
                                role="listbox"
                                aria-label="Products from Products Setup"
                            >
                                <button
                                    v-for="product in filteredSetupProducts"
                                    :key="product.id"
                                    type="button"
                                    class="product-picker__item"
                                    :class="{
                                        'product-picker__item--selected':
                                            String(form.product_id) ===
                                            String(product.id),
                                        'product-picker__item--added':
                                            isAlreadyInMain(product),
                                    }"
                                    role="option"
                                    :aria-selected="
                                        String(form.product_id) ===
                                        String(product.id)
                                    "
                                    :disabled="isAlreadyInMain(product)"
                                    @click="selectProduct(product)"
                                >
                                    <img
                                        v-if="product.image"
                                        :src="product.image"
                                        :alt="product.name"
                                        class="product-picker__thumb"
                                    />
                                    <span
                                        v-else
                                        class="product-picker__thumb product-picker__thumb--empty"
                                    >
                                        -
                                    </span>
                                    <span class="product-picker__meta">
                                        <strong>{{ product.name }}</strong>
                                        <small>
                                            {{ product.category || "No category" }}
                                            · {{ product.unit || "-" }}
                                        </small>
                                    </span>
                                    <span
                                        v-if="isAlreadyInMain(product)"
                                        class="product-picker__badge"
                                    >
                                        Already added
                                    </span>
                                    <Check
                                        v-else-if="
                                            String(form.product_id) ===
                                            String(product.id)
                                        "
                                        class="product-picker__check"
                                    />
                                </button>

                                <p
                                    v-if="filteredSetupProducts.length === 0"
                                    class="product-picker__empty"
                                >
                                    No products match “{{ productSearchQuery }}”
                                </p>
                            </div>
                        </div>

                        <p v-if="!editingId" class="form-hint">
                            Create the product in
                            <Link href="/products">Products Setup</Link>
                            first, then add it here and set the quantity.
                        </p>
                    </div>

                    <div class="inventory-landscape__side">
                    <div
                        v-if="selectedProduct"
                        class="inventory-pick"
                    >
                        <img
                            v-if="selectedProduct.image"
                            :src="selectedProduct.image"
                            :alt="selectedProduct.name"
                            class="inventory-pick__image"
                        />
                        <div v-else class="inventory-pick__placeholder">
                            No image
                        </div>
                        <div class="inventory-pick__details">
                            <strong>{{ selectedProduct.name }}</strong>
                            <p>
                                Category:
                                {{ selectedProduct.category || "-" }}
                            </p>
                            <p>Unit: {{ selectedProduct.unit || "-" }}</p>
                            <p>
                                Price: ₱
                                {{ formatMoney(selectedProduct.price) }}
                            </p>
                            <p v-if="selectedProduct.description">
                                {{ selectedProduct.description }}
                            </p>
                        </div>
                    </div>
                    <div v-else class="inventory-pick inventory-pick--empty">
                        <div class="inventory-pick__placeholder">
                            No image
                        </div>
                        <div class="inventory-pick__details">
                            <strong>No product selected</strong>
                            <p>
                                Search and click a product on the left to see
                                details.
                            </p>
                        </div>
                    </div>

                    <Input
                        v-model="form.quantity"
                        type="number"
                        label="Quantity"
                        placeholder="0"
                    />

                    <div class="form-actions inventory-landscape__actions">
                        <Button
                            type="submit"
                            :disabled="
                                isSaving ||
                                (!editingId &&
                                    availableSetupProducts.length === 0)
                            "
                        >
                            {{ isSaving ? "Saving..." : "Save" }}
                        </Button>
                    </div>
                    </div>
                    </div>
                </form>
            </Modal>

            <Modal
                :open="showDeleteModal"
                title="Remove from Main Inventory"
                @close="closeDeleteModal"
            >
                <div class="products-delete-confirm">
                    <div class="products-delete-confirm__head">
                        <TriangleAlert class="products-delete-confirm__icon" />
                        <p class="products-delete-confirm__title">
                            Remove this product from Main Inventory?
                        </p>
                    </div>

                    <p class="products-delete-confirm__text">
                        Product:
                        <strong>
                            {{ pendingDeleteItem?.product?.name || "-" }}
                        </strong>
                    </p>
                    <p class="products-delete-confirm__text">
                        The product will stay in Products Setup. This only
                        removes it from Main Inventory.
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
                            Remove
                        </Button>
                    </div>
                </div>
            </Modal>
        </div>
    </AppLayout>
</template>
