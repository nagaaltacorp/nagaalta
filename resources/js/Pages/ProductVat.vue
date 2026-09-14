<script setup>
import { Head } from "@inertiajs/vue3";
import { ChevronLeft, ChevronRight, Filter, Search } from "lucide-vue-next";
import { computed, nextTick, onMounted, ref, watch } from "vue";
import AppLayout from "../components/layout/AppLayout.vue";
import Button from "../components/ui/Button.vue";
import Table from "../components/ui/Table.vue";
import api from "../services/api";

const pageSize = 15;
const products = ref([]);
const defaultVatRate = ref("12.00");
const snapshot = ref({
    defaultVatRate: "12.00",
    products: {},
});
const searchQuery = ref("");
const vatFilter = ref("all");
const selectedIds = ref([]);
const currentPage = ref(1);
const selectAllCheckbox = ref(null);
const isSaving = ref(false);
const saveMessage = ref("");
const saveError = ref("");

const formatCurrency = (value) =>
    Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

const formatRate = (value) => Number(value || 0).toFixed(2);

const vatAmountFor = (product) => {
    if (!product.is_vatable) {
        return 0;
    }

    return Number(product.price || 0) * (Number(product.vat_rate || 0) / 100);
};

const priceWithVatFor = (product) =>
    Number(product.price || 0) + vatAmountFor(product);

const takeSnapshot = () => {
    snapshot.value = {
        defaultVatRate: formatRate(defaultVatRate.value),
        products: Object.fromEntries(
            products.value.map((product) => [
                product.id,
                {
                    is_vatable: Boolean(product.is_vatable),
                    vat_rate: formatRate(product.vat_rate),
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
        Boolean(product.is_vatable) !== original.is_vatable ||
        formatRate(product.vat_rate) !== original.vat_rate
    );
};

const dirtyProducts = computed(() =>
    products.value.filter((product) => isProductDirty(product)),
);

const isDirty = computed(
    () =>
        formatRate(defaultVatRate.value) !== snapshot.value.defaultVatRate ||
        dirtyProducts.value.length > 0,
);

const filteredProducts = computed(() => {
    const keyword = searchQuery.value.trim().toLowerCase();

    return products.value.filter((product) => {
        if (vatFilter.value === "vatable" && !product.is_vatable) {
            return false;
        }

        if (vatFilter.value === "none" && product.is_vatable) {
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

const vatableCount = computed(
    () => products.value.filter((product) => product.is_vatable).length,
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

const enableVatOnSelected = () => {
    const rate = formatRate(defaultVatRate.value);

    selectedProducts().forEach((product) => {
        product.is_vatable = true;

        if (Number(product.vat_rate) <= 0) {
            product.vat_rate = rate;
        }
    });
};

const disableVatOnSelected = () => {
    selectedProducts().forEach((product) => {
        product.is_vatable = false;
    });
};

const applyRateToSelected = () => {
    const rate = formatRate(defaultVatRate.value);

    selectedProducts().forEach((product) => {
        product.is_vatable = true;
        product.vat_rate = rate;
    });
};

const onVatToggle = (product, enabled) => {
    product.is_vatable = enabled;

    if (enabled && Number(product.vat_rate) <= 0) {
        product.vat_rate = formatRate(defaultVatRate.value);
    }
};

const loadData = async () => {
    const [{ data: productPayload }, { data: settingsPayload }] =
        await Promise.all([api.get("/products"), api.get("/settings")]);

    defaultVatRate.value = formatRate(
        settingsPayload.data?.default_vat_rate ?? 12,
    );
    products.value = (productPayload.data ?? []).map((product) => ({
        ...product,
        is_vatable: Boolean(product.is_vatable),
        vat_rate: formatRate(product.vat_rate ?? 12),
    }));
    selectedIds.value = [];
    takeSnapshot();
};

const saveChanges = async () => {
    if (isSaving.value || !isDirty.value) {
        return;
    }

    isSaving.value = true;
    saveMessage.value = "";
    saveError.value = "";

    try {
        await api.put("/product-vat", {
            default_vat_rate: Number(defaultVatRate.value),
            products: dirtyProducts.value.map((product) => ({
                id: product.id,
                is_vatable: Boolean(product.is_vatable),
                vat_rate: Number(product.vat_rate),
            })),
        });

        takeSnapshot();
        saveMessage.value = "VAT settings saved.";
    } catch (error) {
        saveError.value =
            error?.response?.data?.message ||
            "Unable to save VAT settings. Please try again.";
    } finally {
        isSaving.value = false;
    }
};

watch([searchQuery, vatFilter], () => {
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
    <Head title="Product VAT" />

    <AppLayout title="Product VAT">
        <div class="products-page vat-page">
            <div class="grid content-start items-start grid-cols-1 gap-4 sm:grid-cols-3">
                    <div
                        class="flex h-max flex-col gap-1.5 self-start border border-gray-300 bg-white px-4 py-3.5"
                    >
                        <span
                            class="text-[11px] font-extrabold uppercase tracking-wider text-slate-500"
                            >VATable items</span
                        >
                        <strong
                            class="text-3xl font-extrabold leading-tight text-green-900"
                            >{{ vatableCount }}</strong
                        >
                    </div>
                    <div
                        class="flex h-max flex-col gap-1.5 self-start border border-gray-300 bg-white px-4 py-3.5"
                    >
                        <span
                            class="text-[11px] font-extrabold uppercase tracking-wider text-slate-500"
                            >Selected</span
                        >
                        <strong
                            class="text-3xl font-extrabold leading-tight text-green-900"
                            >{{ selectedCount }}</strong
                        >
                    </div>
                    <div
                        class="flex h-max flex-col gap-1.5 self-start border border-gray-300 bg-white px-4 py-3.5"
                    >
                        <span
                            class="text-[11px] font-extrabold uppercase tracking-wider text-slate-500"
                            >Products</span
                        >
                        <strong
                            class="text-3xl font-extrabold leading-tight text-green-900"
                            >{{ products.length }}</strong
                        >
                    </div>
                </div>

                <section class="dashboard-surface-card">
                <h2 class="panel-title">VAT Settings</h2>
                <div class="vat-settings">
                    <label class="vat-settings__field">
                        <span>Default VAT rate (%)</span>
                        <input
                            v-model="defaultVatRate"
                            class="input"
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                        />
                    </label>

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
                                placeholder="Search name or category"
                            />
                        </label>

                        <label class="products-control">
                            <Filter class="products-control-icon" />
                            <select v-model="vatFilter" class="input">
                                <option value="all">All products</option>
                                <option value="vatable">VATable only</option>
                                <option value="none">Exempt only</option>
                            </select>
                        </label>
                    </div>

                    <div class="vat-actions">
                        <Button
                            variant="outline"
                            :disabled="selectedCount === 0"
                            @click="enableVatOnSelected"
                        >
                            Mark VATable
                        </Button>
                        <Button
                            variant="outline"
                            :disabled="selectedCount === 0"
                            @click="applyRateToSelected"
                        >
                            Apply {{ formatRate(defaultVatRate) }}%
                        </Button>
                        <Button
                            variant="outline"
                            :disabled="selectedCount === 0"
                            @click="disableVatOnSelected"
                        >
                            Mark Exempt
                        </Button>
                    </div>
                </div>

                <Table
                    :columns="[
                        'Select',
                        'Product',
                        'Category',
                        'Price',
                        'Status',
                        'Rate',
                        'VAT Amount',
                        'Price with VAT',
                    ]"
                >
                    <tr v-if="filteredProducts.length === 0">
                        <td class="products-empty" colspan="8">
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
                        <td>{{ product.name }}</td>
                        <td>{{ product.category || "-" }}</td>
                        <td>₱ {{ formatCurrency(product.price) }}</td>
                        <td>
                            <label class="vat-switch">
                                <input
                                    type="checkbox"
                                    :checked="product.is_vatable"
                                    @change="
                                        onVatToggle(
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
                                            product.is_vatable,
                                    }"
                                >
                                    {{
                                        product.is_vatable
                                            ? "VATable"
                                            : "Exempt"
                                    }}
                                </span>
                            </label>
                        </td>
                        <td>
                            <label class="vat-rate-field">
                                <input
                                    v-model="product.vat_rate"
                                    class="input vat-rate-field__input"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    :disabled="!product.is_vatable"
                                />
                                <span>%</span>
                            </label>
                        </td>
                        <td>₱ {{ formatCurrency(vatAmountFor(product)) }}</td>
                        <td>₱ {{ formatCurrency(priceWithVatFor(product)) }}</td>
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
        </div>
    </AppLayout>
</template>
