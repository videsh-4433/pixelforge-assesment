<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { usd, usdExact } from '@/utils/money';

defineProps({
    packages: { type: Array, required: true },
    transactions: { type: Object, required: true },
    maxBalance: { type: Number, required: true },
});

const page = usePage();
const credits = computed(() => page.props.credits);
const held = computed(() => (Number(credits.value.balance) - Number(credits.value.available)).toFixed(9));
const buying = ref(null);

function buy(amount) {
    buying.value = amount;
    router.post(route('credits.store'), { amount }, { preserveScroll: true, onFinish: () => (buying.value = null) });
}
</script>

<template>
    <Head title="Credits" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="text-sm text-slate-500">Balance</div>
                    <div class="mt-1 text-2xl font-semibold text-slate-900" :title="usdExact(credits.balance)">{{ usd(credits.balance) }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="text-sm text-slate-500">Held for running generations</div>
                    <div class="mt-1 text-2xl font-semibold text-slate-900">{{ usd(held) }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="text-sm text-slate-500">Available to spend</div>
                    <div class="mt-1 text-2xl font-semibold text-emerald-700" :title="usdExact(credits.available)">{{ usd(credits.available) }}</div>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="font-semibold text-slate-900">Buy credits</h2>
                <p class="mt-1 text-sm text-slate-500">
                    1 credit = $1. This is a demo, so no payment is taken. Balances are capped at ${{ maxBalance }}.
                </p>
                <p v-if="page.props.errors.amount" class="mt-2 text-sm text-rose-700">{{ page.props.errors.amount }}</p>
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <button
                        v-for="amount in packages"
                        :key="amount"
                        :disabled="buying !== null"
                        class="rounded-lg border border-slate-300 px-4 py-3 text-center font-semibold text-slate-800 hover:border-indigo-500 hover:bg-indigo-50 disabled:opacity-50"
                        @click="buy(amount)"
                    >
                        {{ buying === amount ? '…' : `$${amount}` }}
                    </button>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-100 px-6 py-4 font-semibold text-slate-900">History</h2>
                <div v-if="transactions.data.length === 0" class="px-6 py-8 text-center text-sm text-slate-500">No transactions yet.</div>
                <table v-else class="w-full text-sm">
                    <tbody>
                        <tr v-for="t in transactions.data" :key="t.id" class="border-t border-slate-100 first:border-0">
                            <td class="px-6 py-3 text-slate-500">{{ new Date(t.created_at).toLocaleString() }}</td>
                            <td class="px-6 py-3 text-slate-800">{{ t.description }}</td>
                            <td
                                class="px-6 py-3 text-right tabular-nums"
                                :class="t.amount.startsWith('-') ? 'text-slate-800' : 'text-emerald-700'"
                                :title="usdExact(t.amount)"
                            >
                                {{ t.amount.startsWith('-') ? '' : '+' }}{{ usd(t.amount) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <nav v-if="transactions.last_page > 1" class="flex justify-center gap-1 border-t border-slate-100 p-3">
                    <Link
                        v-for="link in transactions.links"
                        :key="link.label"
                        :href="link.url ?? ''"
                        class="rounded-md px-3 py-1.5 text-sm"
                        :class="[link.active ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100', !link.url && 'pointer-events-none opacity-40']"
                        v-html="link.label"
                    />
                </nav>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
