@php
    $node = $branch['node'];
    $type = $node->subject->type->value;
    $stableId = $node->subject->stableId;
    $expanded = in_array($branch['key'], $this->expanded, true);
    $showingDetail = $branch['detail'] instanceof \App\Domains\People\Organisation\Data\OrganisationDrillThrough;
@endphp
<li>
    <div class="flex items-center gap-2 text-sm">
        @if ($branch['children'] !== [] || ! $expanded)
            <button
                type="button"
                wire:click="toggle('{{ $type }}', '{{ $stableId }}')"
                aria-expanded="{{ $expanded ? 'true' : 'false' }}"
                class="font-medium text-ink"
            >{{ $expanded ? __('Collapse') : __('Expand') }}</button>
        @endif
        <span>{{ $node->label }}</span>
        @if ($branch['badge'] !== null)
            <x-ui.badge variant="info">{{ __(':count people', ['count' => $branch['badge']]) }}</x-ui.badge>
        @endif
        @if ($showingDetail)
            <button
                type="button"
                wire:click="hideDetail('{{ $type }}', '{{ $stableId }}')"
                class="text-muted underline"
            >{{ __('Hide people') }}</button>
        @else
            <button
                type="button"
                wire:click="showDetail('{{ $type }}', '{{ $stableId }}')"
                class="text-muted underline"
            >{{ __('Show people') }}</button>
        @endif
    </div>

    @if ($showingDetail && $branch['detail']->nodes !== [])
        <ul class="ml-6 mt-1 space-y-1 text-sm text-muted">
            @foreach ($branch['detail']->nodes as $person)
                <li>{{ $person->label }}</li>
            @endforeach
        </ul>
    @endif

    @if ($expanded && $branch['children'] !== [])
        <ul class="ml-6 mt-1 space-y-2">
            @foreach ($branch['children'] as $child)
                @include('people::livewire.explorer.node', ['branch' => $child])
            @endforeach
        </ul>
    @endif
</li>
