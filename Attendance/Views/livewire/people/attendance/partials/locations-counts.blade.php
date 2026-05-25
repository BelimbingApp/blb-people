<x-ui.card>
    <h2 class="text-base font-semibold text-ink">{{ __('Locations') }}</h2>
    <div class="mt-4 grid gap-4 md:grid-cols-2">
        <div class="rounded-2xl border border-border-default p-4">
            <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Geofences') }}</div>
            <div class="mt-2 text-2xl font-semibold tabular-nums text-ink">{{ $geofences->count() }}</div>
        </div>
        <div class="rounded-2xl border border-border-default p-4">
            <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Geofence Groups') }}</div>
            <div class="mt-2 text-2xl font-semibold tabular-nums text-ink">{{ $geofenceGroups->count() }}</div>
        </div>
    </div>
</x-ui.card>
