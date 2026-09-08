<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Training schedule')"
        :subtitle="__('Schedule connector-owned events and keep the complete event register available even when a provider is offline.')"
    />

    @if (session('status'))
        <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    @endif
    @error('event')
        <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
    @enderror

    @if ($companies === [])
        <x-ui.alert variant="info">{{ __('No authorized workforce company is available.') }}</x-ui.alert>
    @else
        @if (count($companies) > 1)
            <div class="flex flex-wrap gap-2" aria-label="{{ __('Workforce company') }}">
                @foreach ($companies as $entityId => $name)
                    <x-ui.button type="button" wire:click="selectCompany({{ $entityId }})" :variant="$companyEntityId === $entityId ? 'primary' : 'secondary'">
                        {{ $name }}
                    </x-ui.button>
                @endforeach
            </div>
        @endif

        @if ($canManage)
            <x-ui.card>
                <div class="space-y-4">
                    <div>
                        <h2 class="text-lg font-semibold">{{ $editingEventId === null ? __('Schedule an event') : __('Revise scheduled event') }}</h2>
                        <p class="text-sm text-muted">{{ __('Participant attendance and results are recorded separately; this schedule never changes proficiency.') }}</p>
                    </div>
                    <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                        <x-ui.select id="training-event-course" :label="__('Course')" wire:model="courseId" required>
                            <option value="">{{ __('Choose a course') }}</option>
                            @foreach ($courses as $course)<option value="{{ $course->id }}">{{ $course->code }} · {{ $course->title }}</option>@endforeach
                        </x-ui.select>
                        <x-ui.select id="training-event-department" :label="__('Target department')" wire:model="targetDepartmentEntityId">
                            <option value="">{{ __('Company-wide (visible to every HOD)') }}</option>
                            @foreach ($departments as $department)<option value="{{ $department->workforce_entity_id }}">{{ $department->name }}</option>@endforeach
                        </x-ui.select>
                        <x-ui.select id="training-event-organizer" :label="__('Accountable organiser')" wire:model="organizerEmployeeEntityId" required>
                            <option value="">{{ __('Choose one person') }}</option>
                            @foreach ($employees as $employee)<option value="{{ $employee->workforce_entity_id }}">{{ $employee->display_name }}</option>@endforeach
                        </x-ui.select>
                        <x-ui.select id="training-event-trainer" :label="__('Internal trainer')" wire:model="internalTrainerEmployeeEntityId">
                            <option value="">{{ __('Use course trainer or external provider') }}</option>
                            @foreach ($employees as $employee)<option value="{{ $employee->workforce_entity_id }}">{{ $employee->display_name }}</option>@endforeach
                        </x-ui.select>
                        <x-ui.select id="training-event-mode" :label="__('Delivery mode')" wire:model="deliveryMode">
                            <option value="">{{ __('Use course default') }}</option>
                            @foreach (\App\Domains\People\Training\Enums\DeliveryMode::cases() as $mode)<option value="{{ $mode->value }}">{{ $mode->label() }}</option>@endforeach
                        </x-ui.select>
                        <x-ui.input id="training-event-venue" :label="__('Venue / meeting link')" wire:model="venue" />
                        <x-ui.input id="training-event-external-name" :label="__('External trainer / provider')" wire:model="externalTrainerName" />
                        <x-ui.input id="training-event-external-reference" :label="__('Provider-neutral reference')" wire:model="externalTrainerReference" />
                        <x-ui.input id="training-event-capacity" type="number" min="1" :label="__('Capacity')" wire:model="capacity" required />
                        <x-ui.input id="training-event-start" type="datetime-local" :label="__('Starts')" wire:model="startsAt" required />
                        <x-ui.input id="training-event-end" type="datetime-local" :label="__('Ends')" wire:model="endsAt" required />
                    </div>
                    <div class="flex gap-2">
                        <x-ui.button wire:click="save">{{ $editingEventId === null ? __('Schedule event') : __('Save revision') }}</x-ui.button>
                        @if ($editingEventId !== null)<x-ui.button variant="secondary" wire:click="cancelEdit">{{ __('Cancel editing') }}</x-ui.button>@endif
                    </div>
                </div>
            </x-ui.card>
        @endif

        <section class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold">{{ __('Event register') }}</h2>
                <p class="text-sm text-muted">{{ __('Completed and cancelled events remain visible with their audit trail. Participation metrics appear only when the participant record supplies them.') }}</p>
            </div>

            @if ($events->isEmpty())
                <x-ui.alert variant="info">{{ __('No training events are visible for this company and department scope.') }}</x-ui.alert>
            @else
                @foreach ($events as $event)
                    @php($summary = $summaries[$event->id] ?? \App\Domains\People\Training\Data\TrainingParticipationSummary::unavailable())
                    <x-ui.card wire:key="training-event-{{ $event->id }}">
                        <article class="space-y-3">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="font-medium tracking-tight">{{ $event->course_code_snapshot }} · {{ $event->course_title_snapshot }}</h3>
                                    <p class="text-sm text-muted">{{ $event->delivery_mode_snapshot->label() }} · {{ $event->status->label() }}</p>
                                </div>
                                <div class="text-right text-sm tabular-nums">
                                    <x-ui.datetime :value="$event->starts_at" />
                                    <p>{{ __('Capacity :capacity', ['capacity' => $event->capacity]) }}</p>
                                </div>
                            </div>

                            <dl class="grid gap-2 text-sm md:grid-cols-3">
                                <div><dt class="text-muted">{{ __('Department') }}</dt><dd>{{ $event->target_department_entity_id === null ? __('Company-wide') : ($departments->firstWhere('workforce_entity_id', $event->target_department_entity_id)?->name ?? __('Unavailable')) }}</dd></div>
                                <div><dt class="text-muted">{{ __('Organiser') }}</dt><dd>{{ $employees->firstWhere('workforce_entity_id', $event->organizer_employee_entity_id)?->display_name ?? __('Unavailable') }}</dd></div>
                                <div><dt class="text-muted">{{ __('Trainer / provider') }}</dt><dd>{{ $event->external_trainer_name_snapshot ?: ($employees->firstWhere('workforce_entity_id', $event->internal_trainer_employee_entity_id)?->display_name ?? __('Unavailable')) }}</dd></div>
                                <div><dt class="text-muted">{{ __('Venue') }}</dt><dd>{{ $event->venue ?: __('Not specified') }}</dd></div>
                                <div><dt class="text-muted">{{ __('Ends') }}</dt><dd><x-ui.datetime :value="$event->ends_at" /></dd></div>
                                <div data-participation="{{ $event->id }}"><dt class="text-muted">{{ __('Participation') }}</dt><dd>@if ($summary->isAvailable()){{ __(':enrolled enrolled · :attended attended · :completed completed · :passed passed · pass rate :rate', ['enrolled' => $summary->enrolled, 'attended' => $summary->attended, 'completed' => $summary->completed, 'passed' => $summary->passed, 'rate' => $summary->passRate() === null ? __('n/a') : number_format($summary->passRate(), 1).'%']) }} <span class="text-muted">{{ __('as of :time', ['time' => now()->format('Y-m-d H:i')]) }}</span>@else{{ __('Participation unavailable: the participant register could not be read') }}@endif</dd></div>
                            </dl>

                            @if ($event->completion_evidence)<p class="text-sm"><span class="font-medium">{{ __('Completion evidence:') }}</span> {{ $event->completion_evidence }}</p>@endif
                            @if ($event->cancellation_reason)<p class="text-sm"><span class="font-medium">{{ __('Cancellation reason:') }}</span> {{ $event->cancellation_reason }}</p>@endif

                            @php($eventHistory = $history[$event->id] ?? collect())
                            <x-ui.disclosure :title="__('History (:count)', ['count' => $eventHistory->count()])" panel-id="training-event-{{ $event->id }}-history">
                                <ol class="space-y-2 text-sm">
                                    @forelse ($eventHistory as $record)
                                        @php($actorEmployee = $record->actor_employee_entity_id !== null ? $employees->firstWhere('workforce_entity_id', $record->actor_employee_entity_id) : null)
                                        @php($actorUser = $record->actor_user_id !== null ? ($historyActors[$record->actor_user_id] ?? null) : null)
                                        @php($actorLabel = $actorEmployee?->display_name ?? $actorUser?->name ?? ($record->actor_user_id === null && $record->actor_employee_entity_id === null ? __('System') : __('Unavailable')))
                                        <li wire:key="training-event-{{ $event->id }}-history-{{ $record->id }}">
                                            <span class="font-medium">{{ str($record->event_type)->replace('_', ' ')->title() }}</span>
                                            · <x-ui.datetime :value="$record->occurred_at" />
                                            · <span class="text-muted">{{ __('by :actor', ['actor' => $actorLabel]) }}</span>
                                            @if ($record->from_status || $record->to_status)
                                                <p class="text-muted">{{ __('Status :from → :to', ['from' => $record->from_status ?: __('none'), 'to' => $record->to_status ?: __('none')]) }}</p>
                                            @endif
                                            @if ($record->comment)<p>{{ $record->comment }}</p>@endif
                                            @if ($record->evidence)<p class="text-muted">{{ $record->evidence }}</p>@endif
                                        </li>
                                    @empty
                                        <li class="text-muted">{{ __('No history has been recorded for this event yet.') }}</li>
                                    @endforelse
                                </ol>
                            </x-ui.disclosure>

                            @if ($canManage && ! $event->status->isTerminal())
                                <div class="flex flex-wrap gap-2">
                                    @if ($event->status === \App\Domains\People\Training\Enums\TrainingEventStatus::Scheduled)
                                        <x-ui.button wire:click="editEvent({{ $event->id }})">{{ __('Revise') }}</x-ui.button>
                                        <x-ui.button wire:click="start({{ $event->id }})">{{ __('Start') }}</x-ui.button>
                                    @endif
                                </div>
                                @if ($event->status === \App\Domains\People\Training\Enums\TrainingEventStatus::InProgress)
                                    <div class="flex items-end gap-2"><x-ui.input id="training-event-{{ $event->id }}-evidence" :label="__('Completion evidence')" wire:model="evidence.{{ $event->id }}" /><x-ui.button wire:click="complete({{ $event->id }})">{{ __('Complete') }}</x-ui.button></div>
                                @endif
                                <div class="flex items-end gap-2"><x-ui.input id="training-event-{{ $event->id }}-reason" :label="__('Cancellation reason')" wire:model="reason.{{ $event->id }}" /><x-ui.button wire:click="cancel({{ $event->id }})">{{ __('Cancel event') }}</x-ui.button></div>
                            @endif
                            @if ($canExport)
                                <div><x-ui.button type="button" variant="secondary" wire:click="exportAttendance({{ $event->id }})">{{ __('Export attendance CSV') }}</x-ui.button></div>
                            @endif
                            @if ($canManage)
                                <div class="flex items-end gap-2"><x-ui.input id="training-event-{{ $event->id }}-comment" :label="__('Audit note')" wire:model="comment.{{ $event->id }}" /><x-ui.button wire:click="addComment({{ $event->id }})">{{ __('Add note') }}</x-ui.button></div>
                            @endif
                        </article>
                    </x-ui.card>
                @endforeach
            @endif
        </section>
        @if ($canManage && $facts->isNotEmpty())
            <x-ui.card>
                <x-ui.table container="flush" :caption="__('Confirmed participation, as it currently stands')">
                    <x-slot name="head">
                        <tr>
                            <x-ui.th>{{ __('Participant') }}</x-ui.th>
                            <x-ui.th>{{ __('Attendance') }}</x-ui.th>
                            <x-ui.th align="right">{{ __('Minutes') }}</x-ui.th>
                            <x-ui.th>{{ __('Correction') }}</x-ui.th>
                            <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
                        </tr>
                    </x-slot>
                    @foreach ($facts as $fact)
                        <tr wire:key="fact-{{ $fact->id }}">
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $fact->participant }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $fact->attendance->value }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">{{ $fact->actual_minutes }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-muted">
                                @if ($fact->corrected)
                                    <x-ui.badge variant="warning">{{ __('Corrected') }}</x-ui.badge>
                                    <span class="ml-2">{{ $fact->reason }}</span>
                                @else
                                    {{ __('As recorded') }}
                                @endif
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-sm">
                                <x-ui.button type="button" variant="secondary" wire:click="startCorrection({{ $fact->id }})">
                                    {{ __('Correct') }}
                                </x-ui.button>
                            </td>
                        </tr>
                        @if ($correctingFactId === $fact->id)
                            <tr wire:key="fact-{{ $fact->id }}-form">
                                <td colspan="5" class="px-table-cell-x py-table-cell-y">
                                    {{-- A correction is an append, so the reason is not optional:
                                         it is the only record of why the earlier answer was wrong. --}}
                                    <div class="flex flex-wrap items-end gap-2">
                                        <x-ui.input id="correction-{{ $fact->id }}-attendance" :label="__('Attendance')" wire:model="correctionAttendance" />
                                        <x-ui.input id="correction-{{ $fact->id }}-minutes" type="number" :label="__('Minutes')" wire:model="correctionMinutes" />
                                        <x-ui.input id="correction-{{ $fact->id }}-reason" :label="__('Why the confirmed fact was wrong')" wire:model="correctionReason" />
                                        <x-ui.button type="button" wire:click="saveCorrection">{{ __('Append correction') }}</x-ui.button>
                                        <x-ui.button type="button" variant="secondary" wire:click="cancelCorrection">{{ __('Cancel') }}</x-ui.button>
                                    </div>
                                    @error('correctionReason')<p class="mt-2 text-sm text-danger">{{ $message }}</p>@enderror
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif
    @endif
</div>
