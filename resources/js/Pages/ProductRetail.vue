<script setup>
import { Head } from "@inertiajs/vue3";
import { ChevronLeft, ChevronRight, Eye, Filter, Search } from "lucide-vue-next";
import { computed, nextTick, onMounted, ref, watch } from "vue";
import AppLayout from "../components/layout/AppLayout.vue";
import Button from "../components/ui/Button.vue";
import Modal from "../components/ui/Modal.vue";
import Table from "../components/ui/Table.vue";
import api from "../services/api";
import { displayQuantity, parseQuantity } from "../utils/quantity";

const pageSize = 15;
const products = ref([]);
const snapshot = ref({ products: {} });
const searchQuery = ref("");
const retailFilter = ref("all");
const selectedIds = ref([]);
const currentPage = ref(1);
const selectAllCheckbox = ref(null);
const isSaving = ref(false);
const saveMessage = ref("");
const saveError = ref("");
const showViewModal = ref(false);
const viewingProduct = ref(null);

const formatCurrency = (value) =>
    Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

const formatQty = (value) => {
    if (value === null || value === undefined || value === "") {
        return "";
    }

    const numeric = Number(value);

    if (!Number.isFinite(numeric)) {
        return String(value);
    }

    return String(numeric);
};

const retailUnitOf = (product) => product?.retail_unit || "kg";

const retailUnitChoices = computed(() => {
    const baseUnits = [
        "kg",
        "g",
        "pcs",
        "piece",
        "cup",
        "ml",
        "liter",
        "pack",
        "packet",
        "sachet",
        "bottle",
        "can",
        "jar",
        "bag",
        "sack",
        "box",
        "roll",
        "tray",
        "bundle",
        "bunch",
    ];
    const units = new Set(baseUnits);

    products.value.forEach((product) => {
        const wholesale = (product.unit || "").trim();
        const retail = (product.retail_unit || "").trim();

        if (wholesale) {
            units.add(wholesale);
        }

        if (retail) {
            units.add(retail);
        }
    });

    return Array.from(units).sort((a, b) => a.localeCompare(b));
});

const takeSnapshot = () => {
    snapshot.value = {
        products: Object.fromEntries(
            products.value.map((product) => [
                product.id,
                {
                    retail_enabled: Boolean(product.retail_enabled),
                    retail_unit: retailUnitOf(product),
                    retail_qty_per_unit: formatQty(product.retail_qty_per_unit),
                    retail_price: product.retail_price ?? "",
                    retail_allowed_loss: displayQuantity(
                        product.retail_allowed_loss ?? 0,
                    ),
                },
            ]),
        ),
    };
};

const isProductDirty = (product) => {
    const original = snapshot.value.products[product.id];

    if (!original) {
        return true;
    }

    return (
        Boolean(product.retail_enabled) !== original.retail_enabled ||
        retailUnitOf(product) !== original.retail_unit ||
        formatQty(product.retail_qty_per_unit) !==
            original.retail_qty_per_unit ||
        String(product.retail_price ?? "") !==
            String(original.retail_price ?? "") ||
        displayQuantity(product.retail_allowed_loss ?? 0) !==
            original.retail_allowed_loss
    );
};

const dirtyProducts = computed(() =>
    products.value.filter((product) => isProductDirty(product)),
);

const isDirty = computed(() => dirtyProducts.value.length > 0);

const filteredProducts = computed(() => {
    const keyword = searchQuery.value.trim().toLowerCase();

    return products.value.filter((product) => {
        if (retailFilter.value === "enabled" && !product.retail_enabled) {
            return false;
        }

        if (retailFilter.value === "disabled" && product.retail_enabled) {
            return false;
        }

        if (!keyword) {
            return true;
        }

        const haystack = [product.name, product.category, product.unit]
            .filter(Boolean)
            .join(" ")
            .toLowerCase();

        return haystack.includes(keyword);
    });
});

const totalPages = computed(() =>
    Math.max(1, Math.ceil(filteredProducts.value.length / pageSize)),
);

const paginatedProducts = computed(() => {
    const start = (currentPage.value - 1) * pageSize;

    return filteredProducts.value.slice(start, start + pageSize);
});

const paginationFrom = computed(() => {
    if (filteredProducts.value.length === 0) {
        return 0;
    }

    return (currentPage.value - 1) * pageSize + 1;
});

const paginationTo = computed(() =>
    Math.min(currentPage.value * pageSize, filteredProducts.value.length),
);

const pageNumbers = computed(() => {
    const pages = [];

    for (let i = 1; i <= totalPages.value; i += 1) {
        pages.push(i);
    }

    return pages;
});

const selectedCount = computed(() => selectedIds.value.length);

const allFilteredSelected = computed(
    () =>
        filteredProducts.value.length > 0 &&
        filteredProducts.value.every((product) =>
            selectedIds.value.includes(product.id),
        ),
);

const someFilteredSelected = computed(
    () =>
        !allFilteredSelected.value &&
        filteredProducts.value.some((product) =>
            selectedIds.value.includes(product.id),
        ),
);

const enabledCount = computed(
    () => products.value.filter((product) => product.retail_enabled).length,
);

const goToPage = (pageNo) => {
    currentPage.value = Math.min(Math.max(pageNo, 1), totalPages.value);
};

const toggleSelect = (productId) => {
    if (selectedIds.value.includes(productId)) {
        selectedIds.value = selectedIds.value.filter((id) => id !== productId);
        return;
    }

    selectedIds.value = [...selectedIds.value, productId];
};

const toggleSelectAllFiltered = () => {
    if (allFilteredSelected.value) {
        const filteredIds = new Set(
            filteredProducts.value.map((product) => product.id),
        );
        selectedIds.value = selectedIds.value.filter(
            (id) => !filteredIds.has(id),
        );
        return;
    }

    const merged = new Set(selectedIds.value);
    filteredProducts.value.forEach((product) => merged.add(product.id));
    selectedIds.value = Array.from(merged);
};

const selectedProducts = () =>
    products.value.filter((product) => selectedIds.value.includes(product.id));

const enableRetailOnSelected = () => {
    selectedProducts().forEach((product) => {
        product.retail_enabled = true;
        product.retail_unit = retailUnitOf(product);
    });
};

const disableRetailOnSelected = () => {
    selectedProducts().forEach((product) => {
        product.retail_enabled = false;
    });
};

const onRetailToggle = (product, enabled) => {
    product.retail_enabled = enabled;

    if (enabled) {
        product.retail_unit = retailUnitOf(product);
    }
};

const conversionLabel = (product) => {
    if (!product.retail_enabled || !product.retail_qty_per_unit) {
        return "—";
    }

    const wholesale = product.unit || "unit";
    const retail = retailUnitOf(product);

    return `1 ${wholesale} = ${product.retail_qty_per_unit} ${retail}`;
};

const openView = (product) => {
    viewingProduct.value = product;
    showViewModal.value = true;
};

const closeView = () => {
    showViewModal.value = false;
    viewingProduct.value = null;
};

const viewDetails = computed(() => {
    const product = viewingProduct.value;

    if (!product) {
        return null;
    }

    const wholesaleUnit = product.unit || "unit";
    const retailUnit = retailUnitOf(product);
    const qtyPer = Number(product.retail_qty_per_unit) || 0;
    const wholesalePrice = Number(product.price) || 0;
    const retailPrice = Number(product.retail_price) || 0;
    const enabled = Boolean(product.retail_enabled) && qtyPer > 0;

    const inventories = Array.isArray(product.inventories)
        ? product.inventories
        : [];
    const stockRows = inventories.map((item) => {
        const qty = Number(item.quantity) || 0;
        const remainder = Number(item.retail_remainder) || 0;
        const retailTotal = enabled ? qty * qtyPer + remainder : 0;
        const location =
            item.branch?.name ||
            (item.branch_id == null ? "Main Inventory" : "Branch");

        return {
            id: item.id,
            location,
            qty,
            remainder,
            retailTotal,
        };
    });

    const totalWholesale = stockRows.reduce((sum, row) => sum + row.qty, 0);
    const totalRemainder = stockRows.reduce(
        (sum, row) => sum + row.remainder,
        0,
    );
    const totalRetail = enabled
        ? totalWholesale * qtyPer + totalRemainder
        : 0;
    const allowedLoss = enabled
        ? Math.max(0, parseQuantity(product.retail_allowed_loss) ?? 0)
        : 0;
    const sellableRetail = Math.max(0, totalRetail - allowedLoss);
    const equivalentWholesaleValue = enabled
        ? retailPrice * qtyPer
        : 0;

    return {
        product,
        wholesaleUnit,
        retailUnit,
        qtyPer,
        wholesalePrice,
        retailPrice,
        enabled,
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
        equivalentWholesaleValue,
        stockRows,
        totalWholesale,
        totalRemainder,
        totalRetail,
    };
});

const loadData = async () => {
    const { data: productPayload } = await api.get("/products?catalog=1");

    products.value = (productPayload.data ?? []).map((product) => ({
        ...product,
        retail_enabled: Boolean(product.retail_enabled),
        retail_unit: retailUnitOf(product),
        retail_qty_per_unit: product.retail_qty_per_unit ?? "",
        retail_price: product.retail_price ?? "",
        retail_allowed_loss: displayQuantity(product.retail_allowed_loss ?? 0),
    }));
    selectedIds.value = [];
    takeSnapshot();
};

const saveChanges = async () => {
    if (isSaving.value || !isDirty.value) {
        return;
    }

    const invalidLoss = dirtyProducts.value.find((product) => {
        const raw = product.retail_allowed_loss;

        if (raw === null || raw === undefined || String(raw).trim() === "") {
            return false;
        }

        return parseQuantity(raw) === null;
    });

    if (invalidLoss) {
        saveError.value = `Allowed loss for ${invalidLoss.name} must be a number or fraction such as 0.25 or 1/4.`;
        return;
    }

    isSaving.value = true;
    saveMessage.value = "";
    saveError.value = "";

    try {
        await api.put("/product-retail", {
            products: dirtyProducts.value.map((product) => ({
                id: product.id,
                retail_enabled: Boolean(product.retail_enabled),
                retail_unit: retailUnitOf(product).trim().slice(0, 20),
                retail_qty_per_unit: product.retail_qty_per_unit
                    ? Number(product.retail_qty_per_unit)
                    : null,
                retail_price:
                    product.retail_price === "" ||
                    product.retail_price === null ||
                    product.retail_price === undefined
                        ? null
                        : Number(product.retail_price),
                retail_allowed_loss:
                    String(product.retail_allowed_loss ?? "").trim() || "0",
            })),
        });

        products.value.forEach((product) => {
            product.retail_allowed_loss = displayQuantity(
                product.retail_allowed_loss ?? 0,
            );
        });
        takeSnapshot();
        saveMessage.value = "Retail settings saved.";
    } catch (error) {
        saveError.value =
            error?.response?.data?.message ||
            "Unable to save retail settings. Please try again.";
    } finally {
        isSaving.value = false;
    }
};

watch([searchQuery, retailFilter], () => {
    currentPage.value = 1;
});

watch(totalPages, (pages) => {
    if (currentPage.value > pages) {
        currentPage.value = pages;
    }
});

watch([allFilteredSelected, someFilteredSelected], async () => {
    await nextTick();

    if (selectAllCheckbox.value) {
        selectAllCheckbox.value.indeterminate = someFilteredSelected.value;
    }
});

onMounted(loadData);
</script>

<template>
    <Head title="Retail Setup" />

    <AppLayout title="Retail Setup">
        <div class="products-page vat-page retail-page">
            <p class="retail-intro">
                    For wholesale products such as sack or bag, choose the
                    retail unit (kg, pcs, cup, and others) and how many of that
                    unit are in 1 wholesale unit. When a customer buys by the
                    retail unit, that amount is deducted from wholesale stock.
                    Leftover retail quantity stays in inventory. Use
                    <strong>Allowed loss</strong> for display stock that may
                    disappear (for example feeds nibbled by mice). You can
                    enter fractions such as 1/4, 1/2, 0.25, or 1 1/4. That
                    amount is reserved and cannot be sold.
                </p>

                <div class="vat-summary">
                    <div class="vat-summary__item">
                        <span>Retail enabled</span>
                        <strong>{{ enabledCount }}</strong>
                    </div>
                    <div class="vat-summary__item">
                        <span>Selected</span>
                        <strong>{{ selectedCount }}</strong>
                    </div>
                    <div class="vat-summary__item">
                        <span>Products</span>
                        <strong>{{ products.length }}</strong>
                    </div>
                </div>

                <section class="dashboard-surface-card">
                <h2 class="panel-title">Retail Settings</h2>
                <div class="vat-settings">
                    <div class="vat-settings__save">
                        <p
                            v-if="saveError"
                            class="vat-status vat-status--error"
                        >
                            {{ saveError }}
                        </p>
                        <p
                            v-else-if="saveMessage && !isDirty"
                            class="vat-status"
                        >
                            {{ saveMessage }}
                        </p>
                        <p v-else class="vat-status">
                            {{
                                isDirty
                                    ? `${dirtyProducts.length} unsaved change(s)`
                                    : "No unsaved changes"
                            }}
                        </p>
                        <Button
                            :disabled="isSaving || !isDirty"
                            @click="saveChanges"
                        >
                            {{ isSaving ? "Saving..." : "Save Changes" }}
                        </Button>
                    </div>
                </div>

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
                                placeholder="Search name, category, or unit"
                            />
                        </label>

                        <label class="products-control">
                            <Filter class="products-control-icon" />
                            <select v-model="retailFilter" class="input">
                                <option value="all">All products</option>
                                <option value="enabled">Retail enabled</option>
                                <option value="disabled">Wholesale only</option>
                            </select>
                        </label>
                    </div>

                    <div class="vat-actions">
                        <Button
                            variant="outline"
                            :disabled="selectedCount === 0"
                            @click="enableRetailOnSelected"
                        >
                            Enable retail
                        </Button>
                        <Button
                            variant="outline"
                            :disabled="selectedCount === 0"
                            @click="disableRetailOnSelected"
                        >
                            Disable retail
                        </Button>
                    </div>
                </div>

                <Table
                    :columns="[
                        'Select',
                        'Product',
                        'Wholesale unit',
                        'Retail',
                        'Unit for retail',
                        'Retail price',
                        'Allowed loss',
                        'Conversion',
                        'Actions',
                    ]"
                >
                    <tr v-if="filteredProducts.length === 0">
                        <td class="products-empty" colspan="9">
                            No products match your current search/filters.
                        </td>
                    </tr>

                    <tr
                        v-for="product in paginatedProducts"
                        :key="product.id"
                        :class="{
                            'vat-row--selected': selectedIds.includes(
                                product.id,
                            ),
                            'vat-row--dirty': isProductDirty(product),
                        }"
                    >
                        <td>
                            <input
                                class="vat-checkbox"
                                type="checkbox"
                                :checked="selectedIds.includes(product.id)"
                                :aria-label="`Select ${product.name}`"
                                @change="toggleSelect(product.id)"
                            />
                        </td>
                        <td>
                            <div class="retail-product">
                                <span class="retail-product__name">
                                    {{ product.name }}
                                </span>
                                <span
                                    v-if="product.category"
                                    class="retail-product__meta"
                                >
                                    {{ product.category }}
                                </span>
                            </div>
                        </td>
                        <td>{{ product.unit || "-" }}</td>
                        <td>
                            <label class="vat-switch">
                                <input
                                    type="checkbox"
                                    :checked="product.retail_enabled"
                                    @change="
                                        onRetailToggle(
                                            product,
                                            $event.target.checked,
                                        )
                                    "
                                />
                                <span class="vat-switch__track" />
                                <span
                                    class="vat-switch__label"
                                    :class="{
                                        'vat-switch__label--on':
                                            product.retail_enabled,
                                    }"
                                >
                                    {{
                                        product.retail_enabled
                                            ? "Retail"
                                            : "Off"
                                    }}
                                </span>
                            </label>
                        </td>
                        <td>
                            <div class="retail-qty-setup">
                                <input
                                    v-model="product.retail_qty_per_unit"
                                    class="input vat-rate-field__input retail-qty-input"
                                    type="number"
                                    min="1"
                                    step="1"
                                    placeholder="50"
                                    :disabled="!product.retail_enabled"
                                />
                                <div class="retail-unit-wrap">
                                    <select
                                        v-model="product.retail_unit"
                                        class="input retail-unit-select"
                                        :disabled="!product.retail_enabled"
                                    >
                                        <option
                                            v-if="
                                                product.retail_unit &&
                                                !retailUnitChoices.includes(
                                                    product.retail_unit,
                                                )
                                            "
                                            :value="product.retail_unit"
                                        >
                                            {{ product.retail_unit }}
                                        </option>
                                        <option
                                            v-for="unit in retailUnitChoices"
                                            :key="`${product.id}-${unit}`"
                                            :value="unit"
                                        >
                                            {{ unit }}
                                        </option>
                                    </select>
                                </div>
                                <span>
                                    / {{ product.unit || "unit" }}
                                </span>
                            </div>
                        </td>
                        <td>
                            <label class="vat-rate-field">
                                <span>₱</span>
                                <input
                                    v-model="product.retail_price"
                                    class="input vat-rate-field__input retail-price-input"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    placeholder="0.00"
                                    :disabled="!product.retail_enabled"
                                />
                                <span>
                                    / {{ retailUnitOf(product) }}
                                </span>
                            </label>
                        </td>
                        <td>
                            <label class="vat-rate-field">
                                <input
                                    v-model="product.retail_allowed_loss"
                                    class="input vat-rate-field__input retail-loss-input"
                                    type="text"
                                    placeholder="1/4"
                                    title="Examples: 1/4, 1/2, 0.25, 1 1/4"
                                    :disabled="!product.retail_enabled"
                                />
                                <span>
                                    {{ retailUnitOf(product) }}
                                </span>
                            </label>
                        </td>
                        <td>
                            <span class="retail-conversion">
                                {{ conversionLabel(product) }}
                            </span>
                            <span
                                v-if="
                                    product.retail_enabled && product.retail_price
                                "
                                class="retail-conversion retail-conversion--price"
                            >
                                Wholesale ₱{{ formatCurrency(product.price) }} /
                                {{ product.unit || "unit" }}
                            </span>
                        </td>
                        <td class="actions">
                            <Button
                                type="button"
                                variant="outline"
                                class="products-action-btn"
                                :disabled="!product.retail_enabled"
                                @click="openView(product)"
                            >
                                <Eye class="products-btn-icon" />
                                <span>View</span>
                            </Button>
                        </td>
                    </tr>
                </Table>

                <div class="products-pagination">
                    <label class="vat-select-all">
                        <input
                            ref="selectAllCheckbox"
                            class="vat-checkbox"
                            type="checkbox"
                            :checked="allFilteredSelected"
                            @change="toggleSelectAllFiltered"
                        />
                        Select all {{ filteredProducts.length }} product(s)
                    </label>

                    <div
                        v-if="filteredProducts.length > 0"
                        class="products-pagination-controls"
                    >
                        <p class="products-pagination-info">
                            Showing {{ paginationFrom }}-{{ paginationTo }} of
                            {{ filteredProducts.length }} products
                        </p>

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
                :open="showViewModal"
                :title="viewingProduct?.name || 'Product retail'"
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
                        <div v-else class="retail-view__image retail-view__image--empty">
                            No image
                        </div>
                        <div class="retail-view__identity">
                            <p class="retail-view__name">
                                {{ viewDetails.product.name }}
                            </p>
                            <p class="retail-view__meta">
                                {{ viewDetails.product.category || "Uncategorized" }}
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
                        <p
                            v-if="viewDetails.enabled"
                            class="retail-view__reverse"
                        >
                            {{ viewDetails.reverse }}
                        </p>
                        <ul v-if="viewDetails.examples.length" class="retail-view__list">
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
                                formatCurrency(
                                    viewDetails.equivalentWholesaleValue,
                                )
                            }}
                            (wholesale is ₱{{
                                formatCurrency(viewDetails.wholesalePrice)
                            }}
                            / {{ viewDetails.wholesaleUnit }})
                        </p>
                    </section>

                    <section class="retail-view__section">
                        <h4>Stock conversion</h4>
                        <p
                            v-if="viewDetails.stockRows.length === 0"
                            class="retail-view__empty"
                        >
                            This product is not in Main or Branch Inventory yet.
                        </p>
                        <table v-else class="retail-view__stock">
                            <thead>
                                <tr>
                                    <th>Location</th>
                                    <th>Wholesale</th>
                                    <th>Opened leftover</th>
                                    <th>Retail equivalent</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in viewDetails.stockRows"
                                    :key="row.id"
                                >
                                    <td>{{ row.location }}</td>
                                    <td>
                                        {{ row.qty }}
                                        {{ viewDetails.wholesaleUnit }}
                                    </td>
                                    <td>
                                        {{ row.remainder }}
                                        {{ viewDetails.retailUnit }}
                                    </td>
                                    <td>
                                        {{ row.retailTotal }}
                                        {{ viewDetails.retailUnit }}
                                    </td>
                                </tr>
                                <tr class="retail-view__stock-total">
                                    <td>Total</td>
                                    <td>
                                        {{ viewDetails.totalWholesale }}
                                        {{ viewDetails.wholesaleUnit }}
                                    </td>
                                    <td>
                                        {{ viewDetails.totalRemainder }}
                                        {{ viewDetails.retailUnit }}
                                    </td>
                                    <td>
                                        {{ viewDetails.totalRetail }}
                                        {{ viewDetails.retailUnit }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div
                            v-if="viewDetails.enabled"
                            class="retail-view__grid retail-view__grid--follow"
                        >
                            <div class="retail-view__item">
                                <span>Allowed loss (display / mice)</span>
                                <strong>
                                    {{ viewDetails.allowedLoss }}
                                    {{ viewDetails.retailUnit }}
                                </strong>
                            </div>
                            <div class="retail-view__item">
                                <span>Sellable retail</span>
                                <strong>
                                    {{ viewDetails.sellableRetail }}
                                    {{ viewDetails.retailUnit }}
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
        </div>
    </AppLayout>
</template>
