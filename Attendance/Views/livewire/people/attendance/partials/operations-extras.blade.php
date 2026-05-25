<div class="grid gap-4 lg:grid-cols-2">
    <x-ui.card>
        <h2 class="text-base font-semibold text-ink">{{ __('Recent Clock Events') }}</h2>
        <div class="mt-4 space-y-3">
            @forelse ($clockEvents as $event)
                <div class="rounded-lg border border-border-default p-3" wire:key="clock-event-{{ $event->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="font-medium text-ink">{{ $event->employee?->full_name ?? __('Employee #:id', ['id' => $event->employee_id]) }}</div>
                            <div class="font-mono text-xs text-muted">{{ $event->occurred_at?->format('Y-m-d H:i:s') }} / {{ $event->source }} / {{ $event->event_type }}</div>
                        </div>
                        @if ($event->photo_evidence_present)
                            <x-ui.badge variant="info">{{ __('Photo') }}</x-ui.badge>
                        @endif
                    </div>
                    <div class="mt-2 text-xs text-muted">{{ __('IP: :ip / Outlet: :outlet / Geofence: :result', ['ip' => $event->ip_address ?? '-', 'outlet' => $event->outlet_label ?? '-', 'result' => $event->geofence_result ?? '-']) }}</div>
                </div>
            @empty
                <p class="text-sm text-muted">{{ __('No clock events captured yet.') }}</p>
            @endforelse
        </div>
    </x-ui.card>

    <x-ui.card>
        <h2 class="text-base font-semibold text-ink">{{ __('Absenteeism Batches') }}</h2>
        <div class="mt-4 space-y-3">
            @forelse ($absenceBatches as $batch)
                <div class="rounded-lg border border-border-default p-3" wire:key="absence-batch-{{ $batch->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="font-medium text-ink">{{ $batch->reference ?? __('Batch #:id', ['id' => $batch->id]) }}</div>
                            <div class="font-mono text-xs text-muted">{{ $batch->period_starts_on?->format('Y-m-d') }} - {{ $batch->period_ends_on?->format('Y-m-d') }}</div>
                        </div>
                        <x-ui.badge>{{ $this->statusLabel($batch->status) }}</x-ui.badge>
                    </div>
                    <div class="mt-2 text-xs text-muted">{{ trans_choice(':count candidate|:count candidates', $batch->entries_count, ['count' => $batch->entries_count]) }} / {{ __('Lock date: :date', ['date' => $batch->lock_date?->format('Y-m-d') ?? '-']) }}</div>
                </div>
            @empty
                <p class="text-sm text-muted">{{ __('No absenteeism batches generated yet.') }}</p>
            @endforelse
        </div>
    </x-ui.card>
</div>
