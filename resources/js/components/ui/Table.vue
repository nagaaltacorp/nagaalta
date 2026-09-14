<script setup>
defineProps({
    columns: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <div class="scrollbar-page w-full min-w-0 overflow-auto">
        <table
            class="w-full min-w-[700px] table-auto border-collapse text-left text-sm text-green-900 [&_td]:border-b [&_td]:border-gray-200 [&_td]:px-3.5 [&_td]:py-2.5 [&_td]:align-middle [&_tbody_tr:nth-child(even)_td]:bg-green-50/40 [&_tbody_tr:hover_td]:bg-green-50"
        >
            <thead>
                <tr>
                    <th
                        v-for="column in columns"
                        :key="column"
                        class="bg-green-900 px-3.5 py-2.5 text-[11px] font-extrabold uppercase tracking-wider text-white"
                    >
                        {{ column }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <slot />
            </tbody>
        </table>
    </div>
</template>
