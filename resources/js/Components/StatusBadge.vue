<script setup>
import { computed } from 'vue';

const props = defineProps({ status: { type: String, required: true } });

const styles = {
    pending: { label: 'Submitting', class: 'bg-slate-100 text-slate-700 ring-slate-200', spin: true },
    queued: { label: 'Queued', class: 'bg-amber-50 text-amber-800 ring-amber-200', spin: true },
    processing: { label: 'Processing', class: 'bg-indigo-50 text-indigo-700 ring-indigo-200', spin: true },
    succeeded: { label: 'Succeeded', class: 'bg-emerald-50 text-emerald-700 ring-emerald-200', spin: false },
    failed: { label: 'Failed', class: 'bg-rose-50 text-rose-700 ring-rose-200', spin: false },
};

const style = computed(() => styles[props.status] ?? styles.pending);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
        :class="style.class"
    >
        <svg v-if="style.spin" class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
        </svg>
        {{ style.label }}
    </span>
</template>
