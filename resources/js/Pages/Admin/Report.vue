<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { usdExact } from '@/utils/money';

const props = defineProps({
    start: String,
    end: String,
    days: Array,
    totals: Object,
    pixelforgeTotal: String,
    pixelforgeError: String,
});

const filter = useForm({ start: props.start, end: props.end });
const apply = () => filter.get(route('admin.report'), { preserveState: true });

// Admins need exact numbers to reconcile with Pixelforge, so show all 9 decimals.
const money = (d) => usdExact(d);
</script>

<template>
    <Head title="Admin report" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold text-slate-900">Spend & profit</h1>
                    <p class="text-sm text-slate-500">
                        By UTC day of completion, matching how Pixelforge bills in <code>GET /v1/usage</code>.
                    </p>
                </div>
                <form class="flex items-end gap-2" @submit.prevent="apply">
                    <label class="text-xs text-slate-500">From<input v-model="filter.start" type="date" class="mt-1 block rounded-md border-slate-300 text-sm" /></label>
                    <label class="text-xs text-slate-500">To<input v-model="filter.end" type="date" class="mt-1 block rounded-md border-slate-300 text-sm" /></label>
                    <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Apply</button>
                </form>
            </div>

            <div class="grid gap-4 sm:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="text-sm text-slate-500">Credit spend (charged to users)</div>
                    <div class="mt-1 font-mono text-lg font-semibold">{{ money(totals.charge) }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="text-sm text-slate-500">Provider cost (our records)</div>
                    <div class="mt-1 font-mono text-lg font-semibold">{{ money(totals.cost) }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="text-sm text-slate-500">Provider cost (Pixelforge)</div>
                    <div
                        class="mt-1 font-mono text-lg font-semibold"
                        :class="pixelforgeTotal === null ? 'text-slate-400' : pixelforgeTotal === totals.cost ? 'text-emerald-700' : 'text-rose-700'"
                    >
                        {{ pixelforgeTotal === null ? 'unavailable' : money(pixelforgeTotal) }}
                    </div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="text-sm text-slate-500">Profit</div>
                    <div class="mt-1 font-mono text-lg font-semibold text-emerald-700">{{ money(totals.profit) }}</div>
                </div>
            </div>

            <div v-if="pixelforgeError" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                Couldn't load Pixelforge usage: {{ pixelforgeError }}
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Day / user</th>
                            <th class="px-4 py-3 text-right">Generations</th>
                            <th class="px-4 py-3 text-right">Credit spend</th>
                            <th class="px-4 py-3 text-right">Provider cost</th>
                            <th class="px-4 py-3 text-right">Profit</th>
                            <th class="px-4 py-3 text-right">Pixelforge /v1/usage</th>
                        </tr>
                    </thead>
                    <tbody v-if="days.length === 0">
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No billed generations in this range.</td></tr>
                    </tbody>
                    <tbody v-for="day in days" :key="day.date" class="border-t border-slate-200">
                        <tr class="bg-slate-50/60 font-medium text-slate-900">
                            <td class="px-4 py-2">{{ day.date }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ day.generations }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ money(day.charge) }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ money(day.cost) }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ money(day.profit) }}</td>
                            <td class="px-4 py-2 text-right font-mono">
                                <template v-if="day.pixelforge">
                                    <span :class="day.pixelforge.matches ? 'text-emerald-700' : 'text-rose-700'">
                                        {{ day.pixelforge.matches ? '✓' : '≠' }} {{ money(day.pixelforge.cost) }} ({{ day.pixelforge.generations }})
                                    </span>
                                </template>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                        </tr>
                        <tr v-for="u in day.users" :key="u.user_id" class="text-slate-600">
                            <td class="py-2 pl-8 pr-4">{{ u.name }} <span class="text-slate-400">{{ u.email }}</span></td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ u.generations }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ money(u.charge) }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ money(u.cost) }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ money(u.profit) }}</td>
                            <td />
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
