<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Training budget')"
        :subtitle="__('Approved and pending training spend per department for :year, against the annual allocation.', ['year' => $year])"
    />

    @if (session('training-budget-status'))
        <x-ui.alert variant="success">{{ session('training-budget-status') }}</x-ui.alert>
    @endif
    @error('budget')
        <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
    @enderror

    @if ($rows === [])
        <x-ui.alert variant="info">{{ __('No department has a training budget or a costed request for this year yet.') }}</x-ui.alert>
    @else
        <x-ui.card>
            <x-ui.table :caption="__('Training budget by department')">
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('Department') }}</x-ui.th>
                        <x-ui.th>{{ __('Budget') }}</x-ui.th>
                        <x-ui.th>{{ __('Approved') }}</x-ui.th>
                        <x-ui.th>{{ __('Pending') }}</x-ui.th>
                        <x-ui.th>{{ __('Remaining') }}</x-ui.th>
                        @if ($mayManage)
                            <x-ui.th>{{ __('Set allocation') }}</x-ui.th>
                        @endif
                    </tr>
                </x-slot:head>

                @foreach ($rows as $row)
                    <tr wire:key="budget-{{ $row->departmentEntityId }}">
                        <td class="px-table-cell-x text-ink">{{ $row->departmentName }}</td>
                        <td class="px-table-cell-x">
                            @if ($row->budget === null)
                                {{-- Not allocated is not nought: say so rather than print a zero. --}}
                                <span class="text-muted">{{ __('Not set') }}</span>
                            @else
                                {{ $row->budget }}
                            @endif
                        </td>
                        <td class="px-table-cell-x">{{ $row->approved }}</td>
                        <td class="px-table-cell-x text-muted">{{ $row->pending }}</td>
                        <td class="px-table-cell-x">
                            @if ($row->remaining === null)
                                <span class="text-muted">{{ __('Not set') }}</span>
                            @else
                                <x-ui.badge :variant="bccomp($row->remaining, '0', 4) < 0 ? 'danger' : 'neutral'">
                                    {{ $row->remaining }}
                                </x-ui.badge>
                            @endif
                        </td>
                        @if ($mayManage)
                            <td class="px-table-cell-x">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-ui.input
                                        type="text"
                                        wire:model="amount.{{ $row->departmentEntityId }}"
                                        :placeholder="__('Amount')"
                                    />
                                    <x-ui.input
                                        type="text"
                                        wire:model="reason.{{ $row->departmentEntityId }}"
                                        :placeholder="__('Reason')"
                                    />
                                    <x-ui.button type="button" wire:click="save({{ $row->departmentEntityId }})">
                                        {{ __('Save') }}
                                    </x-ui.button>
                                </div>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif
</div>
