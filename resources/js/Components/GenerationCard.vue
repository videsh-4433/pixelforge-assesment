<script setup>
import { computed, ref } from 'vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { usd, usdExact } from '@/utils/money';

const props = defineProps({ generation: { type: Object, required: true } });

const imageBroken = ref(false);
const g = computed(() => props.generation);
const inFlight = computed(() => ['pending', 'queued', 'processing'].includes(g.value.status));
const showImage = computed(() => g.value.status === 'succeeded' && g.value.image_url && !g.value.image_expired && !imageBroken.value);

const tokenSummary = computed(() => {
    const u = g.value.usage;
    if (!u) return '';
    return `Tokens — text: ${u.input_text_tokens}, reference images: ${u.input_image_tokens}, output: ${u.output_tokens}, reasoning: ${u.reasoning_tokens}`;
});

const created = computed(() => new Date(g.value.created_at).toLocaleString());
</script>

<template>
    <div class="flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="relative aspect-square bg-slate-100">
            <a v-if="showImage" :href="g.image_url" target="_blank" rel="noopener">
                <img
                    :src="g.image_url"
                    :alt="g.prompt"
                    class="h-full w-full object-contain"
                    loading="lazy"
                    @error="imageBroken = true"
                />
            </a>
            <div v-else class="flex h-full flex-col items-center justify-center gap-2 p-6 text-center text-sm text-slate-500">
                <template v-if="inFlight">
                    <svg class="h-8 w-8 animate-spin text-indigo-500" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                    </svg>
                    <span>{{ g.notice ?? (g.status === 'processing' ? 'Generating your image…' : 'Waiting in queue…') }}</span>
                </template>
                <template v-else-if="g.status === 'failed'">
                    <span class="text-3xl">⚠️</span>
                    <span class="text-rose-700">{{ g.error_message }}</span>
                </template>
                <template v-else>
                    <span class="text-3xl">🖼️</span>
                    <span>The image link has expired (Pixelforge keeps images for 24 hours).</span>
                </template>
            </div>
        </div>

        <div class="flex flex-1 flex-col gap-2 p-4">
            <div class="flex items-center justify-between gap-2">
                <StatusBadge :status="g.status" />
                <span class="text-xs text-slate-400" :title="g.pixelforge_id ?? ''">{{ created }}</span>
            </div>
            <p class="line-clamp-3 text-sm text-slate-800" :title="g.prompt">{{ g.prompt }}</p>
            <p class="text-xs text-slate-500">
                {{ g.size }} · {{ g.quality }} quality<span v-if="g.reference_images.length">
                    · {{ g.reference_images.length }} reference image{{ g.reference_images.length > 1 ? 's' : '' }}</span>
            </p>

            <div class="mt-auto border-t border-slate-100 pt-2 text-sm">
                <div v-if="g.status === 'succeeded'" class="flex items-baseline justify-between" :title="tokenSummary">
                    <span class="text-slate-500">Charged</span>
                    <span class="font-semibold text-slate-900" :title="usdExact(g.charge)">{{ usd(g.charge) }}</span>
                </div>
                <div v-else-if="g.status === 'failed'" class="flex items-baseline justify-between">
                    <span class="text-slate-500">Charged</span>
                    <span class="font-semibold text-emerald-700">$0.00 · free</span>
                </div>
                <div v-else class="flex items-baseline justify-between">
                    <span class="text-slate-500">Held until done</span>
                    <span class="text-slate-700" :title="usdExact(g.max_charge)">up to {{ usd(g.max_charge) }}</span>
                </div>
                <div class="flex items-baseline justify-between text-xs text-slate-400">
                    <span>Estimated</span>
                    <span :title="usdExact(g.estimated_charge)">{{ usd(g.estimated_charge) }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
