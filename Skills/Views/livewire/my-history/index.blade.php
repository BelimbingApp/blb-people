<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('My skill history')"
        :subtitle="__('Your released assessment scores, newest first. The current effective level is highlighted; expired scores stay visible for the record.')"
    />

    @if ($groups === [])
        <x-ui.alert variant="info">{{ __('No released scores yet.') }}</x-ui.alert>
    @else
        @foreach ($groups as $group)
            <x-ui.card>
                <h2 class="text-lg font-semibold">{{ $group['skill'] }}</h2>
                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('Assessed') }}</x-ui.th>
                            <x-ui.th>{{ __('Level') }}</x-ui.th>
                            <x-ui.th>{{ __('Assessor') }}</x-ui.th>
                            <x-ui.th>{{ __('Valid until') }}</x-ui.th>
                            <x-ui.th>{{ __('State') }}</x-ui.th>
                        </tr>
                    </x-slot:head>
                    <x-slot:body>
                        @foreach ($group['entries'] as $entry)
                            <tr wire:key="my-history-{{ $entry['id'] }}">
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $entry['assessedAt']->format('d M Y') }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $entry['level'] }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $entry['assessor'] }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $entry['validUntil'] === null ? __('No expiry') : $entry['validUntil']->format('d M Y') }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                                    @if ($entry['current'])
                                        <span class="font-semibold">{{ __('Current') }}</span>
                                    @elseif ($entry['expired'])
                                        <span>{{ __('Expired') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-slot:body>
                </x-ui.table>
            </x-ui.card>
        @endforeach
    @endif
</div>
