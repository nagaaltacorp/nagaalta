<script setup>
import { Head } from "@inertiajs/vue3";
import { KeyRound, Plus, Trash2, TriangleAlert } from "lucide-vue-next";
import { computed, onMounted, reactive, ref } from "vue";
import { toast } from "vue-sonner";
import AppLayout from "../components/layout/AppLayout.vue";
import Button from "../components/ui/Button.vue";
import Input from "../components/ui/Input.vue";
import Modal from "../components/ui/Modal.vue";
import Table from "../components/ui/Table.vue";
import api from "../services/api";

const quickPercents = [5, 10, 15, 20, 25, 50];
const passwordConfigured = ref(false);
const options = ref([]);
const newPercent = ref("");
const isSavingPassword = ref(false);
const isSavingOption = ref(false);
const showDeleteModal = ref(false);
const pendingDelete = ref(null);
const passwordError = ref("");
const passwordMessage = ref("");

const passwordForm = reactive({
    current_password: "",
    password: "",
    password_confirmation: "",
});

const formatPercent = (value) => {
    const numeric = Number(value);

    if (!Number.isFinite(numeric)) {
        return "0%";
    }

    return `${String(numeric)}%`;
};

const existingPercents = computed(
    () => new Set(options.value.map((option) => Number(option.percent))),
);

const activeCount = computed(
    () => options.value.filter((option) => option.is_active).length,
);

const loadData = async () => {
    const { data } = await api.get("/discount-setup");
    const payload = data.data ?? {};

    passwordConfigured.value = Boolean(payload.password_configured);
    options.value = payload.options ?? [];
};

const savePassword = async () => {
    passwordError.value = "";
    passwordMessage.value = "";

    if (
        passwordForm.password.trim() !==
        passwordForm.password_confirmation.trim()
    ) {
        passwordError.value = "New password and confirm password must match.";
        return;
    }

    isSavingPassword.value = true;

    try {
        await api.put("/discount-setup/password", {
            current_password: passwordConfigured.value
                ? passwordForm.current_password
                : undefined,
            password: passwordForm.password,
            password_confirmation: passwordForm.password_confirmation,
        });

        passwordForm.current_password = "";
        passwordForm.password = "";
        passwordForm.password_confirmation = "";
        passwordConfigured.value = true;
        passwordMessage.value = "Discount password saved.";
        toast.success("Cashiers now need this password to apply a discount.");
    } catch (error) {
        passwordError.value =
            error?.response?.data?.errors?.current_password?.[0] ||
            error?.response?.data?.errors?.password?.[0] ||
            error?.response?.data?.message ||
            "Unable to save discount password.";
    } finally {
        isSavingPassword.value = false;
    }
};

const addPercent = async (rawPercent = newPercent.value) => {
    const percent = Number(rawPercent);

    if (!Number.isFinite(percent) || percent <= 0 || percent > 100) {
        toast.error("Enter a percent from 0.01 to 100.");
        return;
    }

    isSavingOption.value = true;

    try {
        await api.post("/discount-setup/options", { percent });
        newPercent.value = "";
        await loadData();
        toast.success(`${formatPercent(percent)} added.`);
    } catch (error) {
        toast.error(
            error?.response?.data?.errors?.percent?.[0] ||
                error?.response?.data?.message ||
                "Unable to add that percent.",
        );
    } finally {
        isSavingOption.value = false;
    }
};

const toggleOption = async (option) => {
    try {
        await api.put(`/discount-setup/options/${option.id}`, {
            is_active: !option.is_active,
        });
        await loadData();
    } catch (error) {
        toast.error(
            error?.response?.data?.message || "Unable to update that percent.",
        );
    }
};

const openDelete = (option) => {
    pendingDelete.value = option;
    showDeleteModal.value = true;
};

const closeDelete = () => {
    showDeleteModal.value = false;
    pendingDelete.value = null;
};

const confirmDelete = async () => {
    if (!pendingDelete.value?.id) {
        closeDelete();
        return;
    }

    try {
        await api.delete(`/discount-setup/options/${pendingDelete.value.id}`);
        toast.success("Discount percent removed.");
        closeDelete();
        await loadData();
    } catch (error) {
        toast.error(
            error?.response?.data?.message || "Unable to remove that percent.",
        );
    }
};

onMounted(loadData);
</script>

<template>
    <Head title="Discount Setup" />

    <AppLayout title="Discount Setup">
        <div class="products-page vat-page discount-setup">
            <p class="retail-intro">
                Add the discount percents cashiers may use in the Flutter app.
                A discount applies to the
                <strong>whole sale</strong>, not to one product only. Cashiers
                must enter the password below before a discount can be applied.
            </p>

            <div class="vat-summary">
                <div class="vat-summary__item">
                    <span>Password</span>
                    <strong>
                        {{ passwordConfigured ? "Set" : "Not set" }}
                    </strong>
                </div>
                <div class="vat-summary__item">
                    <span>Percents added</span>
                    <strong>{{ options.length }}</strong>
                </div>
                <div class="vat-summary__item">
                    <span>Available to cashiers</span>
                    <strong>{{ activeCount }}</strong>
                </div>
            </div>

            <div class="discount-setup__grid">
                <section class="page-section">
                    <h3 class="page-section__title">Cashier password</h3>
                    <p class="discount-setup__hint">
                        The Flutter cashier screen should not show discount
                        percents until this password is entered. Keep it
                        separate from login passwords.
                    </p>

                    <p
                        v-if="passwordError"
                        class="vat-status vat-status--error"
                    >
                        {{ passwordError }}
                    </p>
                    <p v-else-if="passwordMessage" class="vat-status">
                        {{ passwordMessage }}
                    </p>

                    <form class="form-grid" @submit.prevent="savePassword">
                        <Input
                            v-if="passwordConfigured"
                            v-model="passwordForm.current_password"
                            type="password"
                            label="Current password"
                            placeholder="Enter current password"
                        />
                        <Input
                            v-model="passwordForm.password"
                            type="password"
                            :label="
                                passwordConfigured
                                    ? 'New password'
                                    : 'Password'
                            "
                            placeholder="At least 4 characters"
                        />
                        <Input
                            v-model="passwordForm.password_confirmation"
                            type="password"
                            label="Confirm password"
                            placeholder="Repeat password"
                        />
                        <div class="form-actions">
                            <Button
                                type="submit"
                                :disabled="isSavingPassword"
                            >
                                <KeyRound class="products-btn-icon" />
                                <span>
                                    {{
                                        isSavingPassword
                                            ? "Saving..."
                                            : passwordConfigured
                                              ? "Change password"
                                              : "Save password"
                                    }}
                                </span>
                            </Button>
                        </div>
                    </form>
                </section>

                <section class="page-section">
                    <h3 class="page-section__title">Available percents</h3>
                    <p class="discount-setup__hint">
                        Cashiers can only choose from percents that are
                        enabled. The chosen percent is taken off the whole
                        checkout total.
                    </p>

                    <form
                        class="discount-setup__add"
                        @submit.prevent="addPercent()"
                    >
                        <label class="vat-settings__field">
                            <span>Add percent</span>
                            <input
                                v-model="newPercent"
                                class="input"
                                type="number"
                                min="0.01"
                                max="100"
                                step="0.01"
                                placeholder="10"
                            />
                            <span>%</span>
                        </label>
                        <Button
                            type="submit"
                            :disabled="isSavingOption"
                        >
                            <Plus class="products-btn-icon" />
                            <span>Add</span>
                        </Button>
                    </form>

                    <div class="discount-setup__chips">
                        <button
                            v-for="percent in quickPercents"
                            :key="percent"
                            type="button"
                            class="discount-setup__chip"
                            :disabled="
                                isSavingOption ||
                                existingPercents.has(percent)
                            "
                            @click="addPercent(percent)"
                        >
                            {{ percent }}%
                        </button>
                    </div>

                    <Table
                        :columns="['Percent', 'Cashiers can use', 'Actions']"
                    >
                        <tr v-if="options.length === 0">
                            <td class="products-empty" colspan="3">
                                No percents yet. Add 10% or another amount
                                cashiers may offer.
                            </td>
                        </tr>
                        <tr v-for="option in options" :key="option.id">
                            <td>
                                <strong class="discount-setup__percent">
                                    {{ formatPercent(option.percent) }}
                                </strong>
                            </td>
                            <td>
                                <label class="vat-switch">
                                    <input
                                        type="checkbox"
                                        :checked="option.is_active"
                                        @change="toggleOption(option)"
                                    />
                                    <span class="vat-switch__track" />
                                    <span>
                                        {{
                                            option.is_active
                                                ? "Yes"
                                                : "Hidden"
                                        }}
                                    </span>
                                </label>
                            </td>
                            <td class="actions">
                                <Button
                                    type="button"
                                    variant="outline"
                                    class="products-action-btn"
                                    @click="openDelete(option)"
                                >
                                    <Trash2 class="products-btn-icon" />
                                    <span>Remove</span>
                                </Button>
                            </td>
                        </tr>
                    </Table>
                </section>
            </div>
        </div>

        <Modal
            :open="showDeleteModal"
            title="Remove discount percent"
            @close="closeDelete"
        >
            <div class="products-delete-confirm">
                <div class="products-delete-confirm__head">
                    <TriangleAlert class="products-delete-confirm__icon" />
                    <p class="products-delete-confirm__title">
                        Remove
                        {{ formatPercent(pendingDelete?.percent) }}?
                    </p>
                </div>
                <p class="products-delete-confirm__text">
                    Cashiers will no longer see this percent in the Flutter
                    app. Past sales that already used it stay in the report.
                </p>
                <div class="form-actions">
                    <Button variant="outline" @click="closeDelete">
                        Cancel
                    </Button>
                    <Button @click="confirmDelete">Remove</Button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
