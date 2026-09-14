<script setup>
import { Head } from "@inertiajs/vue3";
import {
    ArrowUpDown,
    ChevronLeft,
    ChevronRight,
    Filter,
    Search,
    SquarePen,
    Trash2,
    TriangleAlert,
    User,
} from "lucide-vue-next";
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from "vue";
import AppLayout from "../components/layout/AppLayout.vue";
import Button from "../components/ui/Button.vue";
import Input from "../components/ui/Input.vue";
import Modal from "../components/ui/Modal.vue";
import Table from "../components/ui/Table.vue";
import api from "../services/api";
import { toast } from "vue-sonner";

const employees = ref([]);
const branches = ref([]);
const showModal = ref(false);
const showDeleteModal = ref(false);
const editingId = ref(null);
const pendingDeleteEmployee = ref(null);
const searchQuery = ref("");
const photoFilter = ref("all");
const sortBy = ref("name_asc");
const currentPage = ref(1);
const pageSize = 15;
const isSaving = ref(false);
const profilePreviewUrl = ref("");

const form = reactive({
    branch_id: "",
    first_name: "",
    last_name: "",
    contact_number: "",
    contact_email: "",
    address: "",
    profile_picture: null,
});

const loadEmployees = async () => {
    const { data } = await api.get("/employees");
    employees.value = data.data;
};

const loadBranches = async () => {
    const { data } = await api.get("/branches");
    branches.value = data.data;
};

const branchOptions = computed(() =>
    [...branches.value].sort((a, b) => a.name.localeCompare(b.name)),
);

const getEmployeeName = (employee) =>
    [employee.first_name ?? "", employee.last_name ?? ""].join(" ").trim();

const filteredEmployees = computed(() => {
    let list = [...employees.value];
    const query = searchQuery.value.trim().toLowerCase();

    if (query) {
        list = list.filter((employee) => {
            const haystack = [
                employee.first_name,
                employee.last_name,
                String(employee.contact_number ?? ""),
                employee.contact_email,
                employee.address,
                employee.branch?.name,
                employee.user?.user_name,
            ]
                .filter(Boolean)
                .join(" ")
                .toLowerCase();

            return haystack.includes(query);
        });
    }

    if (photoFilter.value === "with_photo") {
        list = list.filter((employee) => Boolean(employee.profile_picture));
    }

    if (photoFilter.value === "without_photo") {
        list = list.filter((employee) => !employee.profile_picture);
    }

    switch (sortBy.value) {
        case "name_desc":
            list.sort((a, b) =>
                getEmployeeName(b).localeCompare(getEmployeeName(a)),
            );
            break;
        case "oldest":
            list.sort(
                (a, b) =>
                    new Date(a.created_at).getTime() -
                    new Date(b.created_at).getTime(),
            );
            break;
        case "newest":
            list.sort(
                (a, b) =>
                    new Date(b.created_at).getTime() -
                    new Date(a.created_at).getTime(),
            );
            break;
        default:
            list.sort((a, b) =>
                getEmployeeName(a).localeCompare(getEmployeeName(b)),
            );
            break;
    }

    return list;
});

const totalPages = computed(() =>
    Math.max(1, Math.ceil(filteredEmployees.value.length / pageSize)),
);

const paginatedEmployees = computed(() => {
    const start = (currentPage.value - 1) * pageSize;
    const end = start + pageSize;
    return filteredEmployees.value.slice(start, end);
});

const paginationFrom = computed(() => {
    if (filteredEmployees.value.length === 0) return 0;
    return (currentPage.value - 1) * pageSize + 1;
});

const paginationTo = computed(() =>
    Math.min(currentPage.value * pageSize, filteredEmployees.value.length),
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

watch([searchQuery, photoFilter, sortBy], () => {
    currentPage.value = 1;
});

watch(totalPages, (pages) => {
    if (currentPage.value > pages) {
        currentPage.value = pages;
    }
});

const clearProfilePreview = () => {
    if (profilePreviewUrl.value) {
        URL.revokeObjectURL(profilePreviewUrl.value);
        profilePreviewUrl.value = "";
    }
};

const resetForm = () => {
    form.branch_id = "";
    form.first_name = "";
    form.last_name = "";
    form.contact_number = "";
    form.contact_email = "";
    form.address = "";
    form.profile_picture = null;
    editingId.value = null;
    clearProfilePreview();
};

const openCreate = () => {
    resetForm();
    showModal.value = true;
};

const openEdit = (employee) => {
    clearProfilePreview();
    form.branch_id = employee.branch_id ? String(employee.branch_id) : "";
    form.first_name = employee.first_name ?? "";
    form.last_name = employee.last_name ?? "";
    form.contact_number = employee.contact_number ?? "";
    form.contact_email = employee.contact_email ?? "";
    form.address = employee.address ?? "";
    form.profile_picture = null;
    editingId.value = employee.id;
    showModal.value = true;
};

const onProfilePictureChange = (event) => {
    const file = event.target.files?.[0] ?? null;
    form.profile_picture = file;
    clearProfilePreview();

    if (file) {
        profilePreviewUrl.value = URL.createObjectURL(file);
    }
};

const getApiErrorMessage = (error, fallback) => {
    return error?.response?.data?.message || fallback;
};

const saveEmployee = async () => {
    if (isSaving.value) {
        return;
    }

    isSaving.value = true;
    const isEditing = Boolean(editingId.value);
    const payload = new FormData();
    payload.append("branch_id", form.branch_id);
    payload.append("first_name", form.first_name);
    payload.append("last_name", form.last_name ?? "");
    payload.append("contact_number", String(form.contact_number));
    payload.append("contact_email", form.contact_email);
    payload.append("address", form.address);

    if (form.profile_picture) {
        payload.append("profile_picture", form.profile_picture);
    }

    try {
        let response;

        if (isEditing) {
            payload.append("_method", "PUT");
            response = await api.post(`/employees/${editingId.value}`, payload);
        } else {
            response = await api.post("/employees", payload);
        }

        showModal.value = false;
        resetForm();
        await loadEmployees();

        if (isEditing) {
            toast.success(
                response?.data?.message || "Employee updated successfully.",
            );
            return;
        }

        if (response?.data?.credentials_sent === false) {
            toast.error(
                response?.data?.message ||
                    "Employee was created, but credentials email could not be sent.",
            );
            return;
        }

        toast.success(
            response?.data?.message || "Employee created successfully.",
        );
    } catch (error) {
        toast.error(
            getApiErrorMessage(
                error,
                isEditing
                    ? "Failed to update employee."
                    : "Failed to create employee.",
            ),
        );
    } finally {
        isSaving.value = false;
    }
};

const deleteEmployee = async (id) => {
    await api.delete(`/employees/${id}`);
    await loadEmployees();
};

const requestDelete = (employee) => {
    pendingDeleteEmployee.value = employee;
    showDeleteModal.value = true;
};

const closeDeleteModal = () => {
    showDeleteModal.value = false;
    pendingDeleteEmployee.value = null;
};

const confirmDelete = async () => {
    if (!pendingDeleteEmployee.value?.id) {
        closeDeleteModal();
        return;
    }

    try {
        await deleteEmployee(pendingDeleteEmployee.value.id);
        closeDeleteModal();
        toast.success("Employee deleted successfully.");
    } catch (error) {
        toast.error(getApiErrorMessage(error, "Failed to delete employee."));
    }
};

const formatEmployeeName = (employee) => getEmployeeName(employee) || "-";

onBeforeUnmount(() => {
    clearProfilePreview();
});

onMounted(async () => {
    await Promise.all([loadEmployees(), loadBranches()]);
});
</script>

<template>
    <Head title="Employees" />

    <AppLayout title="Employees">
        <div class="products-page employees-page">
            <section class="dashboard-surface-card">
            <h2 class="panel-title">Employees</h2>
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
                                placeholder="Search name, branch, username, email"
                            />
                        </label>

                        <label class="products-control">
                            <Filter class="products-control-icon" />
                            <select v-model="photoFilter" class="input">
                                <option value="all">All photos</option>
                                <option value="with_photo">With photo</option>
                                <option value="without_photo">
                                    Without photo
                                </option>
                            </select>
                        </label>

                        <label class="products-control">
                            <ArrowUpDown class="products-control-icon" />
                            <select v-model="sortBy" class="input">
                                <option value="name_asc">Name A-Z</option>
                                <option value="name_desc">Name Z-A</option>
                                <option value="newest">Newest</option>
                                <option value="oldest">Oldest</option>
                            </select>
                        </label>
                    </div>

                    <Button class="products-add-btn" @click="openCreate">
                        <span class="products-add-btn__icon-box">
                            <User
                                class="products-btn-icon products-btn-icon--add"
                            />
                        </span>
                        <span>Add Employee</span>
                    </Button>
                </div>

                <Table
                    :columns="[
                        'Profile',
                        'Name',
                        'Branch',
                        'Username',
                        'Contact Number',
                        'Email',
                        'Address',
                        'Actions',
                    ]"
                >
                    <tr v-if="filteredEmployees.length === 0">
                        <td class="products-empty" colspan="8">
                            No employees match your current search/filters.
                        </td>
                    </tr>

                    <tr
                        v-for="employee in paginatedEmployees"
                        :key="employee.id"
                    >
                        <td>
                            <img
                                v-if="employee.profile_picture"
                                :src="employee.profile_picture"
                                :alt="formatEmployeeName(employee)"
                                class="table-avatar"
                            />
                            <span v-else>-</span>
                        </td>
                        <td>{{ formatEmployeeName(employee) }}</td>
                        <td>{{ employee.branch?.name || "-" }}</td>
                        <td>{{ employee.user?.user_name || "-" }}</td>
                        <td>{{ employee.contact_number || "-" }}</td>
                        <td>{{ employee.contact_email || "-" }}</td>
                        <td>{{ employee.address || "-" }}</td>
                        <td class="actions">
                            <Button
                                variant="outline"
                                class="products-action-btn"
                                @click="openEdit(employee)"
                            >
                                <SquarePen class="products-btn-icon" />
                                <span>Edit</span>
                            </Button>
                            <Button
                                variant="danger"
                                class="products-action-btn products-action-btn--danger"
                                @click="requestDelete(employee)"
                            >
                                <Trash2 class="products-btn-icon" />
                                <span>Delete</span>
                            </Button>
                        </td>
                    </tr>
                </Table>

                <div
                    v-if="filteredEmployees.length > 0"
                    class="products-pagination"
                >
                    <p class="products-pagination-info">
                        Showing {{ paginationFrom }}-{{ paginationTo }} of
                        {{ filteredEmployees.length }} employees
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
                :title="editingId ? 'Edit Employee' : 'Create Employee'"
                class="employees-modal"
                @close="showModal = false"
            >
                <form
                    class="form-grid employees-form"
                    @submit.prevent="saveEmployee"
                >
                    <div class="products-modal-head">
                        <User class="products-modal-head-icon" />
                        <p class="products-modal-head-text">
                            {{
                                editingId
                                    ? "Update employee details and save changes."
                                    : "Add a new employee to your team roster."
                            }}
                        </p>
                    </div>

                    <label class="form-field employees-form__full">
                        <span class="form-field__label">Branch</span>
                        <select v-model="form.branch_id" class="input" required>
                            <option value="">Select branch</option>
                            <option
                                v-for="branch in branchOptions"
                                :key="branch.id"
                                :value="String(branch.id)"
                            >
                                {{ branch.name }} - {{ branch.location }}
                            </option>
                        </select>
                    </label>

                    <Input v-model="form.first_name" label="First Name" />
                    <Input v-model="form.last_name" label="Last Name" />
                    <Input
                        v-model="form.contact_number"
                        type="text"
                        label="Contact Number"
                    />
                    <Input
                        v-model="form.contact_email"
                        type="email"
                        label="Contact Email"
                    />
                    <Input
                        v-model="form.address"
                        label="Address"
                        class="employees-form__full"
                    />
                    <label class="form-field employees-form__full">
                        <span class="form-field__label">Profile Picture</span>
                        <div class="users-upload">
                            <input
                                class="input users-upload__input"
                                type="file"
                                accept="image/*"
                                @change="onProfilePictureChange"
                            />
                        </div>
                        <div
                            v-if="profilePreviewUrl"
                            class="users-upload-preview"
                        >
                            <img
                                :src="profilePreviewUrl"
                                alt="Profile preview"
                                class="users-upload-preview__image"
                            />
                        </div>
                    </label>
                    <p v-if="editingId" class="form-hint">
                        Leave image empty to keep the current profile picture.
                    </p>
                    <div class="form-actions">
                        <Button type="submit" :disabled="isSaving">
                            {{ isSaving ? "Saving..." : "Save" }}
                        </Button>
                    </div>
                </form>
            </Modal>

            <Modal
                :open="showDeleteModal"
                title="Delete Employee"
                @close="closeDeleteModal"
            >
                <div class="products-delete-confirm">
                    <div class="products-delete-confirm__head">
                        <TriangleAlert class="products-delete-confirm__icon" />
                        <p class="products-delete-confirm__title">
                            Are you sure you want to delete this employee?
                        </p>
                    </div>

                    <p class="products-delete-confirm__text">
                        Employee:
                        <strong>{{
                            pendingDeleteEmployee
                                ? formatEmployeeName(pendingDeleteEmployee)
                                : "-"
                        }}</strong>
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
                            Delete Employee
                        </Button>
                    </div>
                </div>
            </Modal>
        </div>
    </AppLayout>
</template>
