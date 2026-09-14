<script setup>
import { Head } from "@inertiajs/vue3";
import { onMounted, reactive } from "vue";
import AppLayout from "../components/layout/AppLayout.vue";
import Button from "../components/ui/Button.vue";
import Input from "../components/ui/Input.vue";
import api from "../services/api";

const form = reactive({
    company_name: "",
    support_email: "",
    timezone: "UTC",
    currency: "USD",
    low_stock_threshold: 10,
});

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
};

onMounted(loadSettings);
</script>

<template>
    <Head title="Settings" />

    <AppLayout title="Settings">
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
    </AppLayout>
</template>
