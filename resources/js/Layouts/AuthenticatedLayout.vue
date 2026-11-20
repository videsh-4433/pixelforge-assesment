<script setup>
import { computed, ref } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { usd, usdExact } from '@/utils/money';

const page = usePage();
const showingNavigationDropdown = ref(false);
const user = computed(() => page.props.auth.user);
const credits = computed(() => page.props.credits);
const flash = computed(() => page.props.flash?.success);

const links = computed(() => [
    { name: 'generations.index', label: 'Generate' },
    { name: 'credits.index', label: 'Credits' },
    ...(user.value?.is_admin ? [{ name: 'admin.report', label: 'Admin report' }] : []),
]);
</script>

<template>
    <div class="min-h-screen bg-slate-50">
        <nav class="border-b border-slate-200 bg-white">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 justify-between">
                    <div class="flex">
                        <Link
                            :href="route('generations.index')"
                            class="flex shrink-0 items-center gap-2 font-semibold text-slate-900"
                        >
                            <span class="grid h-8 w-8 place-items-center rounded-lg bg-indigo-600 text-white">P</span>
                            Pixelforge Studio
                        </Link>
                        <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                            <NavLink
                                v-for="l in links"
                                :key="l.name"
                                :href="route(l.name)"
                                :active="route().current(l.name)"
                            >
                                {{ l.label }}
                            </NavLink>
                        </div>
                    </div>

                    <div class="hidden sm:flex sm:items-center sm:gap-3">
                        <Link
                            v-if="credits"
                            :href="route('credits.index')"
                            class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-sm font-medium text-emerald-800 hover:bg-emerald-100"
                            :title="`Balance ${usdExact(credits.balance)} · available ${usdExact(credits.available)}`"
                        >
                            {{ usd(credits.balance) }} credits
                        </Link>
                        <Dropdown align="right" width="48">
                            <template #trigger>
                                <button
                                    type="button"
                                    class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700"
                                >
                                    {{ user.name }}
                                    <svg class="-me-0.5 ms-2 h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                        <path
                                            fill-rule="evenodd"
                                            d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                </button>
                            </template>
                            <template #content>
                                <DropdownLink :href="route('profile.edit')">Profile</DropdownLink>
                                <DropdownLink :href="route('logout')" method="post" as="button">Log Out</DropdownLink>
                            </template>
                        </Dropdown>
                    </div>

                    <div class="-me-2 flex items-center sm:hidden">
                        <button
                            class="rounded-md p-2 text-slate-400 hover:bg-slate-100"
                            @click="showingNavigationDropdown = !showingNavigationDropdown"
                        >
                            <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <div v-show="showingNavigationDropdown" class="sm:hidden">
                <div class="space-y-1 pb-3 pt-2">
                    <ResponsiveNavLink
                        v-for="l in links"
                        :key="l.name"
                        :href="route(l.name)"
                        :active="route().current(l.name)"
                    >
                        {{ l.label }}
                    </ResponsiveNavLink>
                </div>
                <div class="border-t border-slate-200 pb-1 pt-4">
                    <div class="px-4 text-sm text-slate-600">
                        {{ user.name }} · {{ credits ? usd(credits.balance) : '' }} credits
                    </div>
                    <div class="mt-3 space-y-1">
                        <ResponsiveNavLink :href="route('profile.edit')">Profile</ResponsiveNavLink>
                        <ResponsiveNavLink :href="route('logout')" method="post" as="button">Log Out</ResponsiveNavLink>
                    </div>
                </div>
            </div>
        </nav>

        <header v-if="$slots.header" class="bg-white shadow-sm">
            <div class="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
                <slot name="header" />
            </div>
        </header>

        <div v-if="flash" class="mx-auto mt-4 max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
                {{ flash }}
            </div>
        </div>

        <main>
            <slot />
        </main>
    </div>
</template>
