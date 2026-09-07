<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Critical skill backup coverage')"
        :subtitle="__('How many people can currently cover each critical skill, and where that is only one.')"
    />

    <x-ui.card>
        <x-ui.table container="flush" :caption="__('Critical skill coverage')">
            <x-slot name="head">
                <tr>
                    <x-ui.th>{{ __('Skill') }}</x-ui.th>
                    <x-ui.th align="right">{{ __('People covering') }}</x-ui.th>
                    <x-ui.th>{{ __('Resilience') }}</x-ui.th>
                    <x-ui.th>{{ __('Who covers it') }}</x-ui.th>
                </tr>
            </x-slot>
            @forelse ($rows as $row)
                <tr wire:key="coverage-{{ $loop->index }}" data-coverage-count="{{ $row['covered'] }}">
                    <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">{{ $row['skill'] }}</td>
                    <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">{{ $row['covered'] }}</td>
                    <td class="px-table-cell-x py-table-cell-y text-sm">
                        @if ($row['single_point_of_failure'])
                            {{-- The whole point of the page: one person, or none. --}}
                            <x-ui.badge variant="danger">{{ __('Single point of failure') }}</x-ui.badge>
                        @else
                            <span class="text-muted">{{ __('Covered') }}</span>
                        @endif
                    </td>
                    <td class="px-table-cell-x py-table-cell-y text-sm text-muted">
                        {{ $row['holders'] === [] ? __('Nobody currently qualified') : implode(', ', $row['holders']) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-table-cell-x py-10 text-center text-sm text-muted">
                        {{ __('No critical skill requirements are recorded for this company.') }}
                    </td>
                </tr>
            @endforelse
        </x-ui.table>
    </x-ui.card>
</div>
