<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GenerationCard from '@/Components/GenerationCard.vue';
import InputError from '@/Components/InputError.vue';
import { usd, usdExact } from '@/utils/money';

const props = defineProps({
    options: { type: Object, required: true },
    generations: { type: Object, required: true },
    hasInFlight: { type: Boolean, required: true },
});

const page = usePage();

const form = useForm({
    prompt: '',
    size: props.options.sizes[0],
    quality: 'medium',
    reference_images: [''],
});

// Unicode code points, matching how Pixelforge (and our server, mb_strlen) count characters.
const characters = computed(() => [...form.prompt].length);
const referenceCount = computed(() => form.reference_images.filter((u) => u.trim() !== '').length);

const sizeLabels = { '1024x1024': 'Square', '1536x1024': 'Landscape', '1024x1536': 'Portrait' };

// ---- Live price estimate (computed server-side so there's one source of truth for pricing) ----
const estimate = ref(null);
const estimateError = ref(false);
let estimateTimer = null;
let estimateController = null;

async function refreshEstimate() {
    estimateController?.abort();
    estimateController = new AbortController();
    const params = new URLSearchParams({
        characters: characters.value,
        size: form.size,
        quality: form.quality,
        references: Math.min(referenceCount.value, props.options.maxReferenceImages),
    });
    try {
        const res = await fetch(`${route('generations.estimate')}?${params}`, {
            headers: { Accept: 'application/json' },
            signal: estimateController.signal,
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        estimate.value = await res.json();
        estimateError.value = false;
    } catch (e) {
        if (e.name !== 'AbortError') estimateError.value = true;
    }
}

watch(
    [characters, () => form.size, () => form.quality, referenceCount, () => page.props.credits?.available],
    () => {
        clearTimeout(estimateTimer);
        estimateTimer = setTimeout(refreshEstimate, 250);
    },
    { immediate: true },
);
onBeforeUnmount(() => clearTimeout(estimateTimer));

const tokenRows = computed(() => {
    if (!estimate.value) return [];
    const t = estimate.value.tokens;
    const range = (r) => (r.min === r.max ? r.max.toLocaleString() : `${r.min.toLocaleString()}–${r.max.toLocaleString()}`);
    return [
        { label: 'Prompt text', value: `≈ ${range(t.input_text_tokens)}`, note: 'exact count known after generation' },
        { label: 'Reference images', value: range(t.input_image_tokens), note: '765 per image' },
        { label: 'Image output', value: range(t.output_tokens), note: 'fixed for size & quality' },
        { label: 'Reasoning', value: range(t.reasoning_tokens), note: 'varies per generation' },
    ];
});

// ---- Reference images ----
function addReference() {
    if (form.reference_images.length < props.options.maxReferenceImages) form.reference_images.push('');
}
function removeReference(i) {
    form.reference_images.splice(i, 1);
    if (form.reference_images.length === 0) form.reference_images.push('');
}

const canSubmit = computed(() => form.prompt.trim() !== '' && !form.processing && estimate.value?.affordable !== false);

function submit() {
    form.post(route('generations.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('prompt', 'reference_images'),
    });
}

// ---- Live status: poll while anything is in flight ----
const { start, stop } = usePoll(2000, { only: ['generations', 'hasInFlight', 'credits'] }, { autoStart: false });
watch(() => props.hasInFlight, (inFlight) => (inFlight ? start() : stop()), { immediate: true });

const referenceErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('reference_images.'))
        .map(([, message]) => message),
);
</script>

<template>
    <Head title="Generate" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="grid gap-6 lg:grid-cols-3">
                <!-- Form -->
                <form class="space-y-5 rounded-xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2" @submit.prevent="submit">
                    <div>
                        <div class="flex items-baseline justify-between">
                            <label for="prompt" class="text-sm font-medium text-slate-900">Prompt</label>
                            <span class="text-xs" :class="characters > options.maxPromptLength ? 'text-rose-600' : 'text-slate-400'">
                                {{ characters.toLocaleString() }} / {{ options.maxPromptLength.toLocaleString() }}
                            </span>
                        </div>
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            rows="4"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            placeholder="A lighthouse on a cliff at dawn, oil painting"
                            @keydown.meta.enter="submit"
                            @keydown.ctrl.enter="submit"
                        />
                        <InputError :message="form.errors.prompt" class="mt-1" />
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <fieldset>
                            <legend class="text-sm font-medium text-slate-900">Size</legend>
                            <div class="mt-2 grid grid-cols-3 gap-2">
                                <label
                                    v-for="size in options.sizes"
                                    :key="size"
                                    class="cursor-pointer rounded-lg border px-2 py-2 text-center text-xs transition"
                                    :class="form.size === size ? 'border-indigo-600 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-600' : 'border-slate-300 text-slate-700 hover:border-slate-400'"
                                >
                                    <input v-model="form.size" type="radio" :value="size" class="sr-only" />
                                    <span class="block font-medium">{{ sizeLabels[size] ?? size }}</span>
                                    <span class="text-slate-500">{{ size }}</span>
                                </label>
                            </div>
                            <InputError :message="form.errors.size" class="mt-1" />
                        </fieldset>

                        <fieldset>
                            <legend class="text-sm font-medium text-slate-900">Quality</legend>
                            <div class="mt-2 grid grid-cols-3 gap-2">
                                <label
                                    v-for="quality in options.qualities"
                                    :key="quality"
                                    class="cursor-pointer rounded-lg border px-2 py-2 text-center text-xs capitalize transition"
                                    :class="form.quality === quality ? 'border-indigo-600 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-600' : 'border-slate-300 text-slate-700 hover:border-slate-400'"
                                >
                                    <input v-model="form.quality" type="radio" :value="quality" class="sr-only" />
                                    <span class="block py-2 font-medium">{{ quality }}</span>
                                </label>
                            </div>
                            <InputError :message="form.errors.quality" class="mt-1" />
                        </fieldset>
                    </div>

                    <div>
                        <div class="flex items-baseline justify-between">
                            <span class="text-sm font-medium text-slate-900">Reference images <span class="font-normal text-slate-400">(optional)</span></span>
                            <span class="text-xs text-slate-400">up to {{ options.maxReferenceImages }} URLs</span>
                        </div>
                        <div class="mt-2 space-y-2">
                            <div v-for="(url, i) in form.reference_images" :key="i" class="flex gap-2">
                                <input
                                    v-model="form.reference_images[i]"
                                    type="url"
                                    placeholder="https://example.com/style.png"
                                    class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                />
                                <button
                                    type="button"
                                    class="rounded-lg border border-slate-300 px-3 text-slate-500 hover:bg-slate-50"
                                    aria-label="Remove reference image"
                                    @click="removeReference(i)"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>
                        <button
                            v-if="form.reference_images.length < options.maxReferenceImages"
                            type="button"
                            class="mt-2 text-sm font-medium text-indigo-600 hover:text-indigo-500"
                            @click="addReference"
                        >
                            + Add another URL
                        </button>
                        <InputError :message="form.errors.reference_images" class="mt-1" />
                        <InputError v-for="(message, i) in referenceErrors" :key="i" :message="message" class="mt-1" />
                    </div>

                    <div v-if="form.errors.limit" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        {{ form.errors.limit }}
                    </div>

                    <div v-if="form.errors.credits" class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
                        {{ form.errors.credits }}
                        <Link :href="route('credits.index')" class="font-medium underline">Buy credits</Link>
                    </div>

                    <div class="flex items-center justify-end gap-3">
                        <span class="hidden text-xs text-slate-400 sm:inline">Ctrl/⌘ + Enter</span>
                        <button
                            type="submit"
                            :disabled="!canSubmit"
                            class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ form.processing ? 'Submitting…' : `Generate · ~${estimate ? usd(estimate.charge.expected) : '…'}` }}
                        </button>
                    </div>
                </form>

                <!-- Price -->
                <aside class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-sm font-medium text-slate-900">What it will cost</h2>

                    <div v-if="estimateError" class="text-sm text-rose-700">Couldn't load pricing. Please refresh.</div>
                    <template v-else-if="estimate">
                        <div>
                            <div class="text-3xl font-semibold tracking-tight text-slate-900" :title="usdExact(estimate.charge.expected)">
                                ~{{ usd(estimate.charge.expected) }}
                            </div>
                            <div class="mt-1 text-sm text-slate-500">
                                between {{ usd(estimate.charge.min) }} and {{ usd(estimate.charge.max) }}
                            </div>
                        </div>

                        <table class="w-full text-xs">
                            <tbody>
                                <tr v-for="row in tokenRows" :key="row.label" class="border-t border-slate-100">
                                    <td class="py-1.5 text-slate-600">{{ row.label }}<div class="text-slate-400">{{ row.note }}</div></td>
                                    <td class="py-1.5 text-right tabular-nums text-slate-800">{{ row.value }} tokens</td>
                                </tr>
                            </tbody>
                        </table>

                        <p class="text-xs leading-relaxed text-slate-500">
                            Some tokens are only known once the image is ready, so you pay the <strong>actual</strong> cost
                            afterwards: Pixelforge's price plus {{ options.markupPercent }}%. Up to {{ usd(estimate.charge.max) }}
                            is held while it runs. <strong>Failed generations are free.</strong>
                        </p>

                        <div
                            class="rounded-lg p-3 text-sm"
                            :class="estimate.affordable ? 'bg-slate-50 text-slate-600' : 'border border-amber-200 bg-amber-50 text-amber-900'"
                        >
                            <template v-if="estimate.affordable">
                                Available: <strong :title="usdExact(estimate.available)">{{ usd(estimate.available) }}</strong>
                            </template>
                            <template v-else>
                                You have {{ usd(estimate.available) }} available — not enough for the worst case of
                                {{ usd(estimate.charge.max) }}.
                                <Link :href="route('credits.index')" class="font-medium underline">Buy credits</Link>
                            </template>
                        </div>
                    </template>
                    <div v-else class="h-24 animate-pulse rounded-lg bg-slate-100" />
                </aside>
            </div>

            <!-- History -->
            <section class="mt-10">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-lg font-semibold text-slate-900">Your generations</h2>
                    <span v-if="hasInFlight" class="text-xs text-slate-400">Updating live…</span>
                </div>

                <div v-if="generations.data.length === 0" class="mt-4 rounded-xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500">
                    Nothing yet. Write a prompt above to create your first image.
                </div>

                <div v-else class="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    <GenerationCard v-for="g in generations.data" :key="g.id" :generation="g" />
                </div>

                <nav v-if="generations.last_page > 1" class="mt-6 flex flex-wrap justify-center gap-1">
                    <Link
                        v-for="link in generations.links"
                        :key="link.label"
                        :href="link.url ?? ''"
                        preserve-scroll
                        class="rounded-md px-3 py-1.5 text-sm"
                        :class="[link.active ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100', !link.url && 'pointer-events-none opacity-40']"
                        v-html="link.label"
                    />
                </nav>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
