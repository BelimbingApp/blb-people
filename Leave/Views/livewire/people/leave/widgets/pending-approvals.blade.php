@php /** @var \App\Modules\People\Leave\Livewire\Widgets\PendingApprovals $this */ @endphp
<div>
    <x-ui.card>
        <x-ui.widget-header :title="__('Leave Approvals')" :href="route('people.leave.approvals')" :openLabel="__('Open approvals')" class="mb-0" />
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
    </x-ui.card>
</div>
