@php /** @var \App\Modules\People\Leave\Livewire\Widgets\PendingApprovals $this */ @endphp
<div>
    <x-ui.card>
        <div class="flex items-center justify-between gap-2">
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Leave Approvals') }}</span>
            <x-icon name="heroicon-o-check-badge" class="w-4 h-4 text-muted" />
        </div>
        <p class="mt-2 text-3xl font-medium tracking-tight text-ink tabular-nums">{{ $pendingCount }}</p>
        <p class="mt-1 text-xs text-muted">
            @if($pendingCount === 0)
                {{ __('No leave requests waiting for review.') }}
            @else
                {{ trans_choice('{1} :count request waiting for review.|[2,*] :count requests waiting for review.', $pendingCount, ['count' => $pendingCount]) }}
                @if($earliestStart !== null)
                    {{ __('Earliest starts :date.', ['date' => $earliestStart]) }}
                @endif
            @endif
        </p>
        <div class="mt-3">
            <x-ui.link kind="internal" :href="route('people.leave.approvals')">{{ __('Open approvals') }}</x-ui.link>
        </div>
    </x-ui.card>
</div>
