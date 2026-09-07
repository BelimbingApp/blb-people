<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Team critical-skill gaps')"
        :subtitle="__('Direct reports below a critical requirement, and whether training already targets the gap.')"
    />

    <x-ui.card>
        <x-ui.table container="flush" :caption="__('Critical-skill gaps for your direct reports')">
            <x-slot name="head">
                <tr>
                    <x-ui.th>{{ __('Employee') }}</x-ui.th>
                    <x-ui.th>{{ __('Skill') }}</x-ui.th>
                    <x-ui.th align="right">{{ __('Required') }}</x-ui.th>
                    <x-ui.th align="right">{{ __('Current') }}</x-ui.th>
                    <x-ui.th>{{ __('Last assessed') }}</x-ui.th>
                    <x-ui.th>{{ __('Targeted') }}</x-ui.th>
                </tr>
            </x-slot>
            @forelse ($rows as $row)
                <tr wire:key="gap-{{ $loop->index }}" data-gap-levels="{{ $row['current_level'] }}/{{ $row['required_level'] }}">
                    <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">{{ $row['employee'] }}</td>
                    <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $row['skill'] }}</td>
                    <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">{{ $row['required_level'] }}</td>
                    <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">{{ $row['current_level'] }}</td>
                    <td class="px-table-cell-x py-table-cell-y text-sm text-muted">
                        @if ($row['assessed_at'] !== null)
                            <x-ui.datetime :value="$row['assessed_at']" format="date" />
                        @else
                            {{ __('Not assessed') }}
                        @endif
                    </td>
                    <td class="px-table-cell-x py-table-cell-y text-sm">
                        @if ($row['planned'])
                            <span class="text-muted">{{ __('Training requested') }}</span>
                        @else
                            {{-- Unplanned is the actionable state; it is the reason this page exists. --}}
                            <span class="font-medium text-ink">{{ __('Not yet targeted') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-table-cell-x py-10 text-center text-sm text-muted">
                        {{ __('No direct report is below a critical requirement.') }}
                    </td>
                </tr>
            @endforelse
        </x-ui.table>
    </x-ui.card>
</div>
