<script setup>
import { Head } from "@inertiajs/vue3";
import {
    ArrowUpDown,
    ChevronLeft,
    ChevronRight,
    Filter,
    PackagePlus,
    ImagePlus,
    Search,
    SquarePen,
    TriangleAlert,
    Trash2,
} from "lucide-vue-next";
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from "vue";
import AppLayout from "../components/layout/AppLayout.vue";
import Button from "../components/ui/Button.vue";
import Input from "../components/ui/Input.vue";
import Modal from "../components/ui/Modal.vue";
import Table from "../components/ui/Table.vue";
import api from "../services/api";

const products = ref([]);
const showModal = ref(false);
const showDeleteModal = ref(false);
const editingId = ref(null);
const pendingDeleteProduct = ref(null);
const searchQuery = ref("");
const selectedUnit = ref("all");
const sortBy = ref("name_asc");
const currentPage = ref(1);
const pageSize = 15;
const isSaving = ref(false);

const form = reactive({
    name: "",
    category: "",
    unit: "pcs",
    price: "",
    description: "",
    image: null,
});
const imagePreviewUrl = ref("");
const existingImageUrl = ref("");
const isDraggingImage = ref(false);

const displayImageUrl = computed(
    () => imagePreviewUrl.value || existingImageUrl.value,
);

const clearImagePreview = () => {
    if (imagePreviewUrl.value) {
        URL.revokeObjectURL(imagePreviewUrl.value);
        imagePreviewUrl.value = "";
    }
};

const assignImageFile = (file) => {
    if (!file || !String(file.type || "").startsWith("image/")) {
        return;
    }

    form.image = file;
    clearImagePreview();
    imagePreviewUrl.value = URL.createObjectURL(file);
};

const loadProducts = async () => {
    const { data } = await api.get("/products");
    products.value = data.data;
};

const hasDigits = (value) => /\d/.test(value);

const unitOptions = computed(() => {
    const units = new Set(
        products.value
            .map((product) => (product.unit || "").trim())
            .filter((unit) => unit && !hasDigits(unit)),
    );

    return ["all", ...Array.from(units).sort((a, b) => a.localeCompare(b))];
});

const unitChoices = computed(() => {
    const baseUnits = [
        "pcs",
        "piece",
        "set",
        "pair",
        "dozen",
        "g",
        "kg",
        "lb",
        "oz",
        "ton",
        "ml",
        "liter",
        "gal",
        "m",
        "cm",
        "mm",
        "in",
        "ft",
        "bag",
        "sack",
        "cavan",
        "bale",
        "box",
        "carton",
        "case",
        "pack",
        "packet",
        "pouch",
        "sachet",
        "bottle",
        "jar",
        "can",
        "roll",
        "tube",
        "tray",
        "bundle",
        "bunch",
        "crate",
        "pallet",
    ];
    const units = new Set(
        products.value
            .map((product) => (product.unit || "").trim())
            .filter((unit) => unit && !hasDigits(unit)),
    );

    if (form.unit && !hasDigits(form.unit)) {
        units.add(form.unit);
    }

    const extraUnits = Array.from(units).filter(
        (unit) => !baseUnits.includes(unit),
    );
    extraUnits.sort((a, b) => a.localeCompare(b));

    return [...baseUnits, ...extraUnits];
});

const filteredProducts = computed(() => {
    let list = [...products.value];
    const query = searchQuery.value.trim().toLowerCase();

    if (query) {
        list = list.filter((product) => {
            const haystack = [
                product.name,
                product.category,
                product.unit,
                product.description,
                String(product.price ?? ""),
            ]
                .filter(Boolean)
                .join(" ")
                .toLowerCase();

            return haystack.includes(query);
        });
    }

    if (selectedUnit.value !== "all") {
        list = list.filter((product) => product.unit === selectedUnit.value);
    }

    switch (sortBy.value) {
        case "price_asc":
            list.sort((a, b) => Number(a.price) - Number(b.price));
            break;
        case "price_desc":
            list.sort((a, b) => Number(b.price) - Number(a.price));
            break;
        case "name_desc":
            list.sort((a, b) => b.name.localeCompare(a.name));
            break;
        default:
            list.sort((a, b) => a.name.localeCompare(b.name));
            break;
    }

    return list;
});

const totalPages = computed(() =>
    Math.max(1, Math.ceil(filteredProducts.value.length / pageSize)),
);

const paginatedProducts = computed(() => {
    const start = (currentPage.value - 1) * pageSize;
    const end = start + pageSize;
    return filteredProducts.value.slice(start, end);
});

const paginationFrom = computed(() => {
    if (filteredProducts.value.length === 0) return 0;
    return (currentPage.value - 1) * pageSize + 1;
});

const paginationTo = computed(() =>
    Math.min(currentPage.value * pageSize, filteredProducts.value.length),
);

const pageNumbers = computed(() => {
    const pages = [];

    for (let i = 1; i <= totalPages.value; i++) {
        pages.push(i);
    }

    return pages;
});

const goToPage = (page) => {
    currentPage.value = Math.min(Math.max(page, 1), totalPages.value);
};

watch([searchQuery, selectedUnit, sortBy], () => {
    currentPage.value = 1;
});

watch(totalPages, (pages) => {
    if (currentPage.value > pages) {
        currentPage.value = pages;
    }
});

const resetForm = () => {
    form.name = "";
    form.category = "";
    form.unit = "pcs";
    form.price = "";
    form.description = "";
    form.image = null;
    editingId.value = null;
    existingImageUrl.value = "";
    isDraggingImage.value = false;
    clearImagePreview();
};

const openCreate = () => {
    resetForm();
    showModal.value = true;
};

const openEdit = (product) => {
    form.name = product.name;
    form.category = product.category ?? "";
    form.unit = product.unit ?? "pcs";
    form.price = product.price;
    form.description = product.description ?? "";
    form.image = null;
    existingImageUrl.value = product.image || "";
    clearImagePreview();
    editingId.value = product.id;
    showModal.value = true;
};

const onImageChange = (event) => {
    assignImageFile(event.target.files?.[0] ?? null);
    event.target.value = "";
};

const onImageDrop = (event) => {
    isDraggingImage.value = false;
    assignImageFile(event.dataTransfer?.files?.[0] ?? null);
};

const saveProduct = async () => {
    if (isSaving.value) {
        return;
    }

    isSaving.value = true;
    const payload = new FormData();
    payload.append("name", form.name);
    payload.append("category", form.category ?? "");
    payload.append("unit", form.unit);
    payload.append("price", String(Number(form.price)));
    payload.append("description", form.description ?? "");

    if (form.image) {
        payload.append("image", form.image);
    }

    try {
        if (editingId.value) {
            payload.append("_method", "PUT");
            await api.post(`/products/${editingId.value}`, payload);
        } else {
            await api.post("/products", payload);
        }

        showModal.value = false;
        resetForm();
        await loadProducts();
    } finally {
        isSaving.value = false;
    }
};

const deleteProduct = async (id) => {
    await api.delete(`/products/${id}`);
    await loadProducts();
};

const requestDelete = (product) => {
    pendingDeleteProduct.value = product;
    showDeleteModal.value = true;
};

const closeDeleteModal = () => {
    showDeleteModal.value = false;
    pendingDeleteProduct.value = null;
};

const confirmDelete = async () => {
    if (!pendingDeleteProduct.value?.id) {
        closeDeleteModal();
        return;
    }

    await deleteProduct(pendingDeleteProduct.value.id);
    closeDeleteModal();
};

onMounted(loadProducts);

onBeforeUnmount(() => {
    clearImagePreview();
});
</script>

<template>
    <Head title="Products Setup" />

    <AppLayout title="Products Setup">
        <div class="products-page">
            <section class="dashboard-surface-card">
            <h2 class="panel-title">Products Setup</h2>
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
                                placeholder="Search name, category, unit, price, description"
                            />
                        </label>

                        <label class="products-control">
                            <Filter class="products-control-icon" />
                            <select v-model="selectedUnit" class="input">
                                <option value="all">All units</option>
                                <option
                                    v-for="unit in unitOptions.filter(
                                        (u) => u !== 'all',
                                    )"
                                    :key="unit"
                                    :value="unit"
                                >
                                    {{ unit }}
                                </option>
                            </select>
                        </label>

                        <label class="products-control">
                            <ArrowUpDown class="products-control-icon" />
                            <select v-model="sortBy" class="input">
                                <option value="name_asc">Name A-Z</option>
                                <option value="name_desc">Name Z-A</option>
                                <option value="price_asc">
                                    Price Low-High
                                </option>
                                <option value="price_desc">
                                    Price High-Low
                                </option>
                            </select>
                        </label>
                    </div>

                    <Button class="products-add-btn" @click="openCreate">
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
                        'Image',
                        'Name',
                        'Category',
                        'Unit',
                        'Price',
                        'VAT',
                        'Description',
                        'Actions',
                    ]"
                >
                    <tr v-if="filteredProducts.length === 0">
                        <td class="products-empty" colspan="8">
                            No products match your current search/filters.
                        </td>
                    </tr>

                    <tr v-for="product in paginatedProducts" :key="product.id">
                        <td>
                            <img
                                v-if="product.image"
                                :src="product.image"
                                :alt="product.name"
                                class="table-image"
                            />
                            <span v-else>-</span>
                        </td>
                        <td>{{ product.name }}</td>
                        <td>{{ product.category || "-" }}</td>
                        <td>{{ product.unit || "-" }}</td>
                        <td>{{ Number(product.price).toFixed(2) }}</td>
                        <td>
                            <span
                                class="vat-badge"
                                :class="
                                    product.is_vatable
                                        ? 'vat-badge--on'
                                        : 'vat-badge--off'
                                "
                            >
                                {{
                                    product.is_vatable
                                        ? `VAT ${Number(product.vat_rate || 0).toFixed(2)}%`
                                        : "No VAT"
                                }}
                            </span>
                        </td>
                        <td>{{ product.description || "-" }}</td>
                        <td>
                            <div class="actions">
                                <Button
                                    variant="outline"
                                    class="products-action-btn"
                                    @click="openEdit(product)"
                                >
                                    <SquarePen class="products-btn-icon" />
                                    <span>Edit</span>
                                </Button>
                                <Button
                                    variant="danger"
                                    class="products-action-btn products-action-btn--danger"
                                    @click="requestDelete(product)"
                                >
                                    <Trash2 class="products-btn-icon" />
                                    <span>Delete</span>
                                </Button>
                            </div>
                        </td>
                    </tr>
                </Table>

                <div
                    v-if="filteredProducts.length > 0"
                    class="products-pagination"
                >
                    <p class="products-pagination-info">
                        Showing {{ paginationFrom }}-{{ paginationTo }} of
                        {{ filteredProducts.length }} products
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
                            v-for="page in pageNumbers"
                            :key="page"
                            type="button"
                            class="products-page-btn products-page-btn--number"
                            :class="{
                                'products-page-btn--active':
                                    currentPage === page,
                            }"
                            @click="goToPage(page)"
                        >
                            {{ page }}
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
                :title="editingId ? 'Edit Product' : 'Create Product'"
                wide
                @close="showModal = false"
            >
                <form class="products-form" @submit.prevent="saveProduct">
                    <div class="products-modal-head">
                        <PackagePlus class="products-modal-head-icon" />
                        <p class="products-modal-head-text">
                            {{
                                editingId
                                    ? "Update product details and save changes."
                                    : "Add a new product to your inventory catalog."
                            }}
                        </p>
                    </div>

                    <div class="products-form__fields">
                        <Input
                            v-model="form.name"
                            label="Name"
                            placeholder="Product name"
                        />
                        <Input
                            v-model="form.category"
                            label="Category"
                            placeholder="e.g. Seeds, Fertilizer"
                        />
                        <label class="form-field">
                            <span class="form-field__label">Unit</span>
                            <select v-model="form.unit" class="input">
                                <option
                                    v-for="unit in unitChoices"
                                    :key="unit"
                                    :value="unit"
                                >
                                    {{ unit }}
                                </option>
                            </select>
                        </label>
                        <Input
                            v-model="form.price"
                            type="number"
                            label="Price"
                            placeholder="0.00"
                        />
                        <div class="products-form__full">
                            <Input
                                v-model="form.description"
                                label="Description"
                                placeholder="Product description"
                            />
                        </div>
                    </div>

                    <label
                        class="products-dropzone"
                        :class="{
                            'products-dropzone--active': isDraggingImage,
                            'products-dropzone--filled': Boolean(
                                displayImageUrl,
                            ),
                        }"
                        @dragenter.prevent="isDraggingImage = true"
                        @dragover.prevent="isDraggingImage = true"
                        @dragleave.prevent="isDraggingImage = false"
                        @drop.prevent="onImageDrop"
                    >
                        <input
                            class="products-dropzone__input"
                            type="file"
                            accept="image/*"
                            @change="onImageChange"
                        />

                        <img
                            v-if="displayImageUrl"
                            :src="displayImageUrl"
                            alt="Product preview"
                            class="products-dropzone__preview"
                        />

                        <div v-else class="products-dropzone__placeholder">
                            <ImagePlus class="products-dropzone__icon" />
                            <span>Drop product image here</span>
                            <small>or click to browse</small>
                        </div>
                    </label>

                    <p v-if="editingId" class="form-hint products-form__hint">
                        Leave image empty to keep the current product image.
                    </p>
                    <div class="form-actions products-form__actions">
                        <Button type="submit" :disabled="isSaving">
                            {{ isSaving ? "Saving..." : "Save" }}
                        </Button>
                    </div>
                </form>
            </Modal>

            <Modal
                :open="showDeleteModal"
                title="Delete Product"
                @close="closeDeleteModal"
            >
                <div class="products-delete-confirm">
                    <div class="products-delete-confirm__head">
                        <TriangleAlert class="products-delete-confirm__icon" />
                        <p class="products-delete-confirm__title">
                            Are you sure you want to delete this product?
                        </p>
                    </div>

                    <p class="products-delete-confirm__text">
                        Product:
                        <strong>{{ pendingDeleteProduct?.name || "-" }}</strong>
                    </p>
                    <p class="products-delete-confirm__text">
                        This action cannot be undone.
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
                            Delete Product
                        </Button>
                    </div>
                </div>
            </Modal>
        </div>
    </AppLayout>
</template>
