<?php

use App\Modules\People\Attendance\Livewire\Approvals;

/** @var Approvals $this */
?>

<div>
    <x-slot name="title">{{ __('Attendance Approvals') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Attendance Approvals')" :subtitle="__('Review overtime and attendance exceptions before they affect payroll.')">
            <x-slot name="help">
                {{ __('Overtime requests stay out of payroll until approved here. Rejected requests record the reason for audit.') }}
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
        @endif

        @if (! $schemaReady)
            <x-ui.alert variant="warning">
                {{ __('Attendance database tables are not installed yet. Run the Attendance migration before using timecards, clock events, overtime, and payroll handoff screens.') }}
            </x-ui.alert>
        @endif

        @include('people-attendance::livewire.people.attendance.partials.approvals-queue')

        <x-ui.card>
            <div>
                <h2 class="text-base font-semibold text-ink">{{ __('Adjustment Queue') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('Approval creates a manual clock event on the employee\'s timecard. Rejected requests record the reason for audit.') }}</p>
            </div>
            <x-ui.table container="flush" :caption="__('Adjustment queue')">

                <x-slot name="head">
                        <tr>
                            <x-ui.th>{{ __('Employee') }}</x-ui.th>
                            <x-ui.th>{{ __('Event') }}</x-ui.th>
                            <x-ui.th>{{ __('Proposed') }}</x-ui.th>
                            <x-ui.th>{{ __('Reason') }}</x-ui.th>
                            <x-ui.th>{{ __('Status') }}</x-ui.th>
                            @if ($canApprove)
                                <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
                            @endif
                        </tr>
                    </x-slot>

                        @forelse ($adjustmentRequests as $request)
                            <tr wire:key="attendance-adj-{{ $request->id }}">
                                <td class="px-table-cell-x py-table-cell-y">{{ $request->employee?->full_name ?? __('Employee #:id', ['id' => $request->employee_id]) }}</td>
                                <td class="px-table-cell-x py-table-cell-y font-mono text-xs">{{ $request->target_event_type }}</td>
                                <td class="px-table-cell-x py-table-cell-y font-mono text-xs">{{ $request->proposed_occurred_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-table-cell-x py-table-cell-y">{{ $request->reason }}</td>
                                <td class="px-table-cell-x py-table-cell-y"><x-ui.badge>{{ $this->statusLabel($request->status) }}</x-ui.badge></td>
                                @if ($canApprove)
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <div class="flex justify-end gap-2">
                                            @if ($request->status === 'submitted')
                                                <x-ui.button size="sm" type="button" variant="primary" wire:click="approveAdjustment({{ $request->id }})">{{ __('Approve') }}</x-ui.button>
                                                <x-ui.button size="sm" type="button" variant="danger" wire:click="rejectAdjustment({{ $request->id }})">{{ __('Reject') }}</x-ui.button>
                                            @endif
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $canApprove ? 6 : 5 }}" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No adjustment requests are waiting for action.') }}</td>
                            </tr>
                        @endforelse

            </x-ui.table>
        </x-ui.card>
    </div>
</div>
