<x-ui.card>
    <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div>
            <h2 class="text-base font-semibold text-ink">{{ __('Overtime Queue') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('Approve submitted requests, reject invalid requests, or queue approved requests into an open payroll run.') }}</p>
        </div>
        <x-ui.input id="attendance-decision-reason" wire:model="decisionReason" label="{{ __('Decision note') }}" placeholder="{{ __('Optional') }}" />
    </div>
    <x-ui.table container="flush" :caption="__('Overtime queue')">

        <x-slot name="head">
                <tr>
                    <x-ui.th>{{ __('Employee') }}</x-ui.th>
                    <x-ui.th>{{ __('Window') }}</x-ui.th>
                    <x-ui.th align="right">{{ __('Requested Hours') }}</x-ui.th>
                    <x-ui.th>{{ __('Reason') }}</x-ui.th>
                    <x-ui.th>{{ __('Status') }}</x-ui.th>
                    @if ($canApprove)
                        <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
                    @endif
                </tr>
            </x-slot>

                @forelse ($overtimeRequests as $request)
                    <tr wire:key="attendance-ot-{{ $request->id }}">
                        <td class="px-table-cell-x py-table-cell-y">{{ $request->employee?->full_name ?? __('Employee #:id', ['id' => $request->employee_id]) }}</td>
                        <td class="px-table-cell-x py-table-cell-y font-mono text-xs">{{ $request->starts_at?->format('Y-m-d H:i') }} - {{ $request->ends_at?->format('H:i') }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-right tabular-nums">{{ number_format($request->requested_minutes / 60, 2) }}</td>
                        <td class="px-table-cell-x py-table-cell-y">{{ $request->reason }}</td>
                        <td class="px-table-cell-x py-table-cell-y"><x-ui.badge>{{ $this->statusLabel($request->status) }}</x-ui.badge></td>
                        @if ($canApprove)
                            <td class="px-table-cell-x py-table-cell-y">
                                <div class="flex justify-end gap-2">
                                    @if ($request->status === 'submitted')
                                        <x-ui.button size="sm" type="button" variant="primary" wire:click="approveOvertime({{ $request->id }})">{{ __('Approve') }}</x-ui.button>
                                        <x-ui.button size="sm" type="button" variant="danger" wire:click="rejectOvertime({{ $request->id }})">{{ __('Reject') }}</x-ui.button>
                                    @elseif ($request->status === 'approved')
                                        <x-ui.button size="sm" type="button" variant="primary" wire:click="queueOvertimePayroll({{ $request->id }})">{{ __('Queue Payroll') }}</x-ui.button>
                                    @endif
                                </div>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $canApprove ? 6 : 5 }}" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No overtime requests are waiting for action.') }}</td>
                    </tr>
                @endforelse

    </x-ui.table>
</x-ui.card>
