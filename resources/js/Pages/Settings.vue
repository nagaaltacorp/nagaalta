<script setup>
import { Head } from "@inertiajs/vue3";
import { TriangleAlert } from "lucide-vue-next";
import { onMounted, reactive, ref } from "vue";
import { toast } from "vue-sonner";
import AppLayout from "../components/layout/AppLayout.vue";
import Button from "../components/ui/Button.vue";
import Input from "../components/ui/Input.vue";
import Modal from "../components/ui/Modal.vue";
import api from "../services/api";

const form = reactive({
    company_name: "",
    support_email: "",
    timezone: "UTC",
    currency: "USD",
    low_stock_threshold: 10,
});
const categories = ref([]);
const newCategory = ref("");
const savingCategory = ref(false);
const removingCategory = ref(false);
const pendingCategory = ref(null);

const loadSettings = async () => {
    const { data } = await api.get("/settings");
    const settings = data.data;

    form.company_name = settings.company_name ?? "";
    form.support_email = settings.support_email ?? "";
    form.timezone = settings.timezone ?? "UTC";
    form.currency = settings.currency ?? "USD";
    form.low_stock_threshold = settings.low_stock_threshold ?? 10;
};

const saveSettings = async () => {
    await api.put("/settings", {
        ...form,
        low_stock_threshold: Number(form.low_stock_threshold),
    });
    toast.success("Settings saved.");
};

const loadCategories = async () => {
    const { data } = await api.get("/product-categories");
    categories.value = data.data || [];
};

const addCategory = async () => {
    const name = newCategory.value.trim();

    if (!name || savingCategory.value) {
        return;
    }

    savingCategory.value = true;

    try {
        await api.post("/product-categories", { name });
        newCategory.value = "";
        toast.success("Category added.");
        await loadCategories();
    } catch (error) {
        toast.error(
            error?.response?.data?.errors?.name?.[0] ||
                error?.response?.data?.message ||
                "Could not add this category.",
        );
    } finally {
        savingCategory.value = false;
    }
};

const askRemoveCategory = (category) => {
    pendingCategory.value = category;
};

const closeRemoveCategory = () => {
    if (removingCategory.value) {
        return;
    }

    pendingCategory.value = null;
};

const removeCategory = async () => {
    if (!pendingCategory.value || removingCategory.value) {
        return;
    }

    removingCategory.value = true;

    try {
        await api.delete(`/product-categories/${pendingCategory.value.id}`);
        pendingCategory.value = null;
        toast.success("Category removed.");
        await loadCategories();
    } catch (error) {
        toast.error(
            error?.response?.data?.message || "Could not remove this category.",
        );
    } finally {
        removingCategory.value = false;
    }
};

onMounted(() => {
    loadSettings();
    loadCategories();
});
</script>

<template>
    <Head title="Settings" />

    <AppLayout title="Settings">
        <div class="settings-page">
        <section class="dashboard-surface-card">
        <h2 class="panel-title">System Settings</h2>
        <form class="form-grid" @submit.prevent="saveSettings">
                <Input v-model="form.company_name" label="Company Name" />
                <Input
                    v-model="form.support_email"
                    label="Support Email"
                    type="email"
                />
                <Input v-model="form.timezone" label="Timezone" />
                <Input v-model="form.currency" label="Currency" />
                <Input
                    v-model="form.low_stock_threshold"
                    type="number"
                    label="Low Stock Threshold"
                />
                <div class="form-actions">
                    <Button type="submit">Save Settings</Button>
                </div>
            </form>
        </section>

        <section class="dashboard-surface-card">
            <h2 class="panel-title">Product Categories</h2>
            <p class="settings-note">
                Product Setup uses this list. Choose a category from the dropdown instead of typing it.
            </p>
            <form class="settings-category-add" @submit.prevent="addCategory">
                <Input
                    v-model="newCategory"
                    label="New category"
                    placeholder="e.g. Seeds, Fertilizer"
                />
                <Button type="submit" :disabled="savingCategory || !newCategory.trim()">
                    {{ savingCategory ? "Adding..." : "Add category" }}
                </Button>
            </form>
            <ul v-if="categories.length" class="settings-category-list">
                <li
                    v-for="category in categories"
                    :key="category.id"
                    class="settings-category-list__item"
                >
                    <span>{{ category.name }}</span>
                    <Button
                        type="button"
                        variant="outline"
                        @click="askRemoveCategory(category)"
                    >
                        Remove
                    </Button>
                </li>
            </ul>
            <p v-else class="settings-note">No categories yet.</p>
        </section>
        </div>

        <Modal
            :open="pendingCategory !== null"
            title="Remove category"
            @close="closeRemoveCategory"
        >
            <div v-if="pendingCategory" class="products-delete-confirm">
                <div class="products-delete-confirm__head">
                    <TriangleAlert class="products-delete-confirm__icon" />
                    <p class="products-delete-confirm__title">
                        Remove {{ pendingCategory.name }}?
                    </p>
                </div>
                <p class="products-delete-confirm__text">
                    It will no longer appear when creating a product.
                </p>
                <div class="form-actions products-delete-confirm__actions">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="removingCategory"
                        @click="closeRemoveCategory"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="danger"
                        :disabled="removingCategory"
                        @click="removeCategory"
                    >
                        {{ removingCategory ? "Removing..." : "Remove" }}
                    </Button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
