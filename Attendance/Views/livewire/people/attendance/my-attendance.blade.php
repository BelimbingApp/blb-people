<?php

use App\Modules\People\Attendance\Livewire\MyAttendance;

/** @var MyAttendance $this */
?>

<div>
    <x-slot name="title">{{ __('My Attendance') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('My Attendance')" :subtitle="__('Review your timecard and record web clock events where enabled.')">
            <x-slot name="help">
                {{ __('Your clock events become a resolved attendance day once HR finalizes them. Submitted overtime requires approver action before payroll picks it up.') }}
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

        <div class="grid gap-4 md:grid-cols-2">
            <x-ui.card>
                <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('My Attendance Days') }}</div>
                <div class="mt-2 text-3xl font-semibold tabular-nums text-ink">{{ $attendanceDays->count() }}</div>
            </x-ui.card>
            <x-ui.card>
                <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('My Pending OT') }}</div>
                <div class="mt-2 text-3xl font-semibold tabular-nums text-ink">{{ $pendingOvertime->count() }}</div>
            </x-ui.card>
        </div>

        @include('people-attendance::livewire.people.attendance.partials.attendance-days-card')

        <x-ui.card>
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-ink">{{ __('My Adjustment Requests') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Request a missing clock event when the web or device clock failed. HR reviews each request before it becomes an attendance fact.') }}</p>
                </div>
                @if ($canClock)
                    <x-ui.button type="button" variant="secondary" wire:click="openAdjustmentModal">
                        {{ __('Request adjustment') }}
                    </x-ui.button>
                @endif
            </div>
            <div class="mt-4 space-y-2">
                @forelse ($myAdjustments as $adjustment)
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-border-default p-3 text-sm">
                        <div class="flex flex-col">
                            <span class="font-medium text-ink">{{ $eventTypeOptions[$adjustment->target_event_type] ?? $adjustment->target_event_type }} · {{ $adjustment->proposed_occurred_at?->format('Y-m-d H:i') }}</span>
                            <span class="text-xs text-muted">{{ $adjustment->reason }}</span>
                        </div>
                        <x-ui.badge>{{ $this->statusLabel($adjustment->status) }}</x-ui.badge>
                    </div>
                @empty
                    <p class="text-sm text-muted">{{ __('No adjustment requests yet.') }}</p>
                @endforelse
            </div>
        </x-ui.card>

        <x-ui.modal wire:model="showAdjustmentModal" class="max-w-2xl">
            <form wire:submit="submitAdjustmentRequest" class="p-6 space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-ink">{{ __('Request adjustment') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Use this for missing clock-ins/outs. Approval creates a manual clock event on your timecard.') }}</p>
                </div>
                <div class="grid gap-4 md:grid-cols-3">
                    <x-ui.input id="attendance-adj-date" type="date" wire:model="adjustmentDate" label="{{ __('Date') }}" required :error="$errors->first('adjustmentDate')" />
                    <x-ui.input id="attendance-adj-time" type="time" wire:model="adjustmentTime" label="{{ __('Time') }}" required :error="$errors->first('adjustmentTime')" />
                    <x-ui.select id="attendance-adj-event" wire:model="adjustmentEventType" label="{{ __('Event') }}" required :error="$errors->first('adjustmentEventType')">
                        @foreach ($eventTypeOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
                <x-ui.textarea id="attendance-adj-reason" wire:model="adjustmentReason" label="{{ __('Reason') }}" rows="3" required :error="$errors->first('adjustmentReason')" />
                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showAdjustmentModal', false)">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ __('Submit request') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        <x-ui.modal wire:model="showOvertimeModal" class="max-w-2xl">
            <form wire:submit="submitOvertimeRequest" class="p-6 space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-ink">{{ __('Request Overtime') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Submitted overtime stays out of payroll until an approver approves and queues it.') }}</p>
                </div>
                <div class="grid gap-4 md:grid-cols-3">
                    <x-ui.input id="attendance-ot-date" type="date" wire:model="overtimeDate" label="{{ __('Date') }}" required :error="$errors->first('overtimeDate')" />
                    <x-ui.input id="attendance-ot-start" type="time" wire:model="overtimeStartsAt" label="{{ __('Start') }}" required :error="$errors->first('overtimeStartsAt')" />
                    <x-ui.input id="attendance-ot-end" type="time" wire:model="overtimeEndsAt" label="{{ __('End') }}" required :error="$errors->first('overtimeEndsAt')" />
                </div>
                <x-ui.input id="attendance-ot-hours" type="number" step="0.25" min="0.25" max="24" wire:model="overtimeRequestedHours" label="{{ __('Requested Hours') }}" required :error="$errors->first('overtimeRequestedHours')" />
                <x-ui.textarea id="attendance-ot-reason" wire:model="overtimeReason" label="{{ __('Reason') }}" rows="3" :error="$errors->first('overtimeReason')" />
                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showOvertimeModal', false)">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ __('Submit Request') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    </div>
</div>
