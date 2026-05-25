<div class="grid gap-4 md:grid-cols-4">
    <x-ui.card>
        <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Attendance Days') }}</div>
        <div class="mt-2 text-3xl font-semibold tabular-nums text-ink">{{ $attendanceDays->count() }}</div>
    </x-ui.card>
    <x-ui.card>
        <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Pending OT') }}</div>
        <div class="mt-2 text-3xl font-semibold tabular-nums text-ink">{{ $pendingOvertime->count() }}</div>
    </x-ui.card>
    <x-ui.card>
        <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Clock Events') }}</div>
        <div class="mt-2 text-3xl font-semibold tabular-nums text-ink">{{ $clockEvents->count() }}</div>
    </x-ui.card>
    <x-ui.card>
        <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Policy Groups') }}</div>
        <div class="mt-2 text-3xl font-semibold tabular-nums text-ink">{{ $policyGroups->count() }}</div>
    </x-ui.card>
</div>
