<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Organisation explorer')"
        :subtitle="__('Company structure with headcount indicators.')"
    />

    <livewire:people-shared.as-of-date-picker :date="$asOfLabel" />

    @if ($historical)
        <x-ui.alert variant="info">
            {{ __('Organisation data is not available as of the selected date.') }}
        </x-ui.alert>
    @elseif ($tree === [])
        <x-ui.alert variant="info">
            {{ __('No organisation units are visible to you.') }}
        </x-ui.alert>
    @else
        <ul class="space-y-2">
            @foreach ($tree as $branch)
                @include('people::livewire.explorer.node', ['branch' => $branch])
            @endforeach
        </ul>
    @endif
</div>
