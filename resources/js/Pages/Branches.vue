<script setup>
import { Head } from "@inertiajs/vue3";
import {
    ArrowUpDown,
    Building2,
    ChevronLeft,
    ChevronRight,
    Filter,
    Search,
    SquarePen,
    TriangleAlert,
    Trash2,
} from "lucide-vue-next";
import { computed, onMounted, reactive, ref, watch } from "vue";
import AppLayout from "../components/layout/AppLayout.vue";
import Button from "../components/ui/Button.vue";
import Input from "../components/ui/Input.vue";
import Modal from "../components/ui/Modal.vue";
import Table from "../components/ui/Table.vue";
import api from "../services/api";

const branches = ref([]);
const managerOptions = ref([]);
const showModal = ref(false);
const showDeleteModal = ref(false);
const editingId = ref(null);
const pendingDeleteBranch = ref(null);
const searchQuery = ref("");
const selectedStatus = ref("all");
const sortBy = ref("name_asc");
const currentPage = ref(1);
const pageSize = 15;
const isSaving = ref(false);

const form = reactive({
    name: "",
    location: "",
    manager_user_id: "",
    status: "active",
});

const loadBranches = async () => {
    const [branchesRes, managersRes] = await Promise.all([
        api.get("/branches"),
        api.get("/branches/manager-options"),
    ]);
    branches.value = branchesRes.data.data;
    managerOptions.value = managersRes.data.data;
};

const getManagerLabel = (branch) => {
    const user = branch.manager_user;
    if (user) {
        return user.name || user.user_name || user.email || "-";
    }

    return branch.manager || "-";
};

const getManagerOptionLabel = (user) =>
    [user.name || user.user_name, user.email].filter(Boolean).join(" — ");

const statusOptions = computed(() => {
    const statuses = new Set(
        branches.value
            .map((branch) => (branch.status || "").trim().toLowerCase())
            .filter(Boolean),
    );

    return ["all", ...Array.from(statuses).sort((a, b) => a.localeCompare(b))];
});

const filteredBranches = computed(() => {
    let list = [...branches.value];
    const query = searchQuery.value.trim().toLowerCase();

    if (query) {
        list = list.filter((branch) => {
            const haystack = [
                branch.name,
                branch.location,
                getManagerLabel(branch),
                branch.status,
            ]
                .filter(Boolean)
                .join(" ")
                .toLowerCase();

            return haystack.includes(query);
        });
    }

    if (selectedStatus.value !== "all") {
        list = list.filter(
            (branch) =>
                (branch.status || "").toLowerCase() ===
                selectedStatus.value.toLowerCase(),
        );
    }

    switch (sortBy.value) {
        case "name_desc":
            list.sort((a, b) => b.name.localeCompare(a.name));
            break;
        case "location_asc":
            list.sort((a, b) => a.location.localeCompare(b.location));
            break;
        case "location_desc":
            list.sort((a, b) => b.location.localeCompare(a.location));
            break;
        default:
            list.sort((a, b) => a.name.localeCompare(b.name));
            break;
    }

    return list;
});

const totalPages = computed(() =>
    Math.max(1, Math.ceil(filteredBranches.value.length / pageSize)),
);

const paginatedBranches = computed(() => {
    const start = (currentPage.value - 1) * pageSize;
    const end = start + pageSize;
    return filteredBranches.value.slice(start, end);
});

const paginationFrom = computed(() => {
    if (filteredBranches.value.length === 0) return 0;
    return (currentPage.value - 1) * pageSize + 1;
});

const paginationTo = computed(() =>
    Math.min(currentPage.value * pageSize, filteredBranches.value.length),
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

watch([searchQuery, selectedStatus, sortBy], () => {
    currentPage.value = 1;
});

watch(totalPages, (pages) => {
    if (currentPage.value > pages) {
        currentPage.value = pages;
    }
});

const resetForm = () => {
    form.name = "";
    form.location = "";
    form.manager_user_id = "";
    form.status = "active";
    editingId.value = null;
};

const openCreate = () => {
    resetForm();
    showModal.value = true;
};

const openEdit = (branch) => {
    form.name = branch.name;
    form.location = branch.location;
    form.manager_user_id = branch.manager_user_id
        ? String(branch.manager_user_id)
        : "";
    form.status = (branch.status || "active").toLowerCase();
    editingId.value = branch.id;
    showModal.value = true;
};

const saveBranch = async () => {
    if (isSaving.value) {
        return;
    }

    isSaving.value = true;
    const payload = {
        name: form.name.trim(),
        location: form.location.trim(),
        manager_user_id: form.manager_user_id
            ? Number(form.manager_user_id)
            : null,
        status: form.status,
    };

    try {
        if (editingId.value) {
            await api.put(`/branches/${editingId.value}`, payload);
        } else {
            await api.post("/branches", payload);
        }

        showModal.value = false;
        resetForm();
        await loadBranches();
    } finally {
        isSaving.value = false;
    }
};

const deleteBranch = async (id) => {
    await api.delete(`/branches/${id}`);
    await loadBranches();
};

const requestDelete = (branch) => {
    pendingDeleteBranch.value = branch;
    showDeleteModal.value = true;
};

const closeDeleteModal = () => {
    showDeleteModal.value = false;
    pendingDeleteBranch.value = null;
};

const confirmDelete = async () => {
    if (!pendingDeleteBranch.value?.id) {
        closeDeleteModal();
        return;
    }

    await deleteBranch(pendingDeleteBranch.value.id);
    closeDeleteModal();
};

const formatStatus = (status) => {
    if (!status) {
        return "Unknown";
    }

    return status.charAt(0).toUpperCase() + status.slice(1).toLowerCase();
};

onMounted(loadBranches);
</script>

<template>
    <Head title="Branches" />

    <AppLayout title="Branches">
        <div class="products-page branches-page">
            <section class="dashboard-surface-card">
            <h2 class="panel-title">Branches</h2>
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
                                placeholder="Search branch, location, manager, status"
                            />
                        </label>

                        <label class="products-control">
                            <Filter class="products-control-icon" />
                            <select v-model="selectedStatus" class="input">
                                <option value="all">All statuses</option>
                                <option
                                    v-for="status in statusOptions.filter(
                                        (s) => s !== 'all',
                                    )"
                                    :key="status"
                                    :value="status"
                                >
                                    {{ formatStatus(status) }}
                                </option>
                            </select>
                        </label>

                        <label class="products-control">
                            <ArrowUpDown class="products-control-icon" />
                            <select v-model="sortBy" class="input">
                                <option value="name_asc">Name A-Z</option>
                                <option value="name_desc">Name Z-A</option>
                                <option value="location_asc">
                                    Location A-Z
                                </option>
                                <option value="location_desc">
                                    Location Z-A
                                </option>
                            </select>
                        </label>
                    </div>

                    <Button class="products-add-btn" @click="openCreate">
                        <span class="products-add-btn__icon-box">
                            <Building2
                                class="products-btn-icon products-btn-icon--add"
                            />
                        </span>
                        <span>Add Branch</span>
                    </Button>
                </div>

                <Table
                    :columns="[
                        'Branch Name',
                        'Location',
                        'Manager',
                        'Status',
                        'Actions',
                    ]"
                >
                    <tr v-if="filteredBranches.length === 0">
                        <td class="products-empty" colspan="5">
                            No branches match your current search/filters.
                        </td>
                    </tr>

                    <tr v-for="branch in paginatedBranches" :key="branch.id">
                        <td>{{ branch.name }}</td>
                        <td>{{ branch.location }}</td>
                        <td>{{ getManagerLabel(branch) }}</td>
                        <td>
                            <span
                                class="branches-status-badge"
                                :class="[
                                    `branches-status-badge--${(
                                        branch.status || 'active'
                                    ).toLowerCase()}`,
                                ]"
                            >
                                {{ formatStatus(branch.status) }}
                            </span>
                        </td>
                        <td class="actions">
                            <Button
                                variant="outline"
                                class="products-action-btn"
                                @click="openEdit(branch)"
                            >
                                <SquarePen class="products-btn-icon" />
                                <span>Edit</span>
                            </Button>
                            <Button
                                variant="danger"
                                class="products-action-btn products-action-btn--danger"
                                @click="requestDelete(branch)"
                            >
                                <Trash2 class="products-btn-icon" />
                                <span>Delete</span>
                            </Button>
                        </td>
                    </tr>
                </Table>

                <div
                    v-if="filteredBranches.length > 0"
                    class="products-pagination"
                >
                    <p class="products-pagination-info">
                        Showing {{ paginationFrom }}-{{ paginationTo }} of
                        {{ filteredBranches.length }} branches
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
                :title="editingId ? 'Edit Branch' : 'Create Branch'"
                @close="showModal = false"
            >
                <form class="form-grid" @submit.prevent="saveBranch">
                    <div class="products-modal-head">
                        <Building2 class="products-modal-head-icon" />
                        <p class="products-modal-head-text">
                            {{
                                editingId
                                    ? "Update branch details and save changes."
                                    : "Add a new branch to your organization."
                            }}
                        </p>
                    </div>

                    <Input
                        v-model="form.name"
                        label="Branch Name"
                        placeholder="e.g. Naga Main Branch"
                    />
                    <Input
                        v-model="form.location"
                        label="Location"
                        placeholder="e.g. Naga City"
                    />
                    <label class="form-field">
                        <span class="form-field__label">Assigned manager</span>
                        <select v-model="form.manager_user_id" class="input">
                            <option value="">No manager assigned</option>
                            <option
                                v-for="user in managerOptions"
                                :key="user.id"
                                :value="String(user.id)"
                            >
                                {{ getManagerOptionLabel(user) }}
                            </option>
                        </select>
                    </label>
                    <p
                        v-if="managerOptions.length === 0"
                        class="products-readonly-note"
                    >
                        No users with the manager role yet. Create a manager
                        account under Users first.
                    </p>

                    <label class="form-field">
                        <span class="form-field__label">Status</span>
                        <select v-model="form.status" class="input">
                            <option value="active">Active</option>
                            <option value="planned">Planned</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </label>

                    <div class="form-actions">
                        <Button type="submit" :disabled="isSaving">
                            {{ isSaving ? "Saving..." : "Save" }}
                        </Button>
                    </div>
                </form>
            </Modal>

            <Modal
                :open="showDeleteModal"
                title="Delete Branch"
                @close="closeDeleteModal"
            >
                <div class="products-delete-confirm">
                    <div class="products-delete-confirm__head">
                        <TriangleAlert class="products-delete-confirm__icon" />
                        <p class="products-delete-confirm__title">
                            Are you sure you want to delete this branch?
                        </p>
                    </div>

                    <p class="products-delete-confirm__text">
                        Branch:
                        <strong>{{ pendingDeleteBranch?.name || "-" }}</strong>
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
                            Delete Branch
                        </Button>
                    </div>
                </div>
            </Modal>
        </div>
    </AppLayout>
</template>
