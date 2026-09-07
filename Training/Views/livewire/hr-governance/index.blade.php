<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('HR governance')"
        :subtitle="__('Everything awaiting HR in this company: requirement publication, training requests, plan approvals, skill reassessments and evidence submissions. Each action runs the owning workflow and its own checks.')"
    />

    @if ($companies === [])
        <x-ui.alert variant="info">{{ __('No company is attributed to your HR role.') }}</x-ui.alert>
    @else
        @if (count($companies) > 1)
            <div class="flex flex-wrap gap-2 text-sm">
                @foreach ($companies as $entityId => $name)
                    <x-ui.button type="button" wire:click="selectCompany({{ $entityId }})" :variant="$companyEntityId === $entityId ? 'primary' : 'secondary'">
                        {{ $name }}
                    </x-ui.button>
                @endforeach
            </div>
        @endif

        <section class="space-y-4">
            <h2 class="text-lg font-semibold">{{ __('Requirement profiles') }}</h2>
            @if ($profiles->isEmpty())
                <p class="text-sm text-muted">{{ __('No requirement profile awaits HR review or publication.') }}</p>
            @else
                <x-ui.table :caption="__('Requirement profiles awaiting HR review')">
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('Profile') }}</x-ui.th>
                            <x-ui.th>{{ __('Version') }}</x-ui.th>
                            <x-ui.th>{{ __('State') }}</x-ui.th>
                            <x-ui.th>{{ __('Decision') }}</x-ui.th>
                        </tr>
                    </x-slot:head>
                    <x-slot:body>
                        @foreach ($profiles as $profile)
                            <tr wire:key="hr-profile-{{ $profile->id }}">
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                                    <span class="font-medium">{{ $profile->name }}</span>
                                    <span class="block text-muted">{{ $profile->code }}</span>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink tabular-nums">v{{ $profile->version }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $profile->status->value }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm space-y-2">
                                    @if ($profile->status === \App\Domains\People\Skills\Enums\RequirementProfileStatus::PendingHrReview)
                                        <x-ui.input type="text" wire:model="profileComment.{{ $profile->id }}" :placeholder="__('Decision comment (required)')" />
                                        <div class="flex gap-2">
                                            <x-ui.button type="button" variant="primary" wire:click="approveProfile({{ $profile->id }})">{{ __('Approve') }}</x-ui.button>
                                            <x-ui.button type="button" variant="secondary" wire:click="returnProfile({{ $profile->id }})">{{ __('Return to draft') }}</x-ui.button>
                                        </div>
                                    @else
                                        <x-ui.button type="button" variant="primary" wire:click="publishProfile({{ $profile->id }})">{{ __('Publish') }}</x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-slot:body>
                </x-ui.table>
            @endif
        </section>

        <section class="space-y-4">
            <h2 class="text-lg font-semibold">{{ __('Training requests') }}</h2>
            @if ($requests->isEmpty())
                <p class="text-sm text-muted">{{ __('No training request awaits HR review.') }}</p>
            @else
                <x-ui.table :caption="__('Training requests awaiting HR review')">
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('Need') }}</x-ui.th>
                            <x-ui.th>{{ __('Priority') }}</x-ui.th>
                            <x-ui.th>{{ __('Decision') }}</x-ui.th>
                        </tr>
                    </x-slot:head>
                    <x-slot:body>
                        @foreach ($requests as $request)
                            <tr wire:key="hr-request-{{ $request->id }}">
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                                    <span class="font-medium">{{ $request->need }}</span>
                                    <span class="block text-muted">{{ $request->learning_objective }}</span>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $request->priority->value }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm space-y-2">
                                    <x-ui.input type="text" wire:model="requestNotes.{{ $request->id }}" :placeholder="__('Notes (required to reject)')" />
                                    <div class="flex gap-2">
                                        <x-ui.button type="button" variant="primary" wire:click="reviewRequest({{ $request->id }})">{{ __('Review and forward') }}</x-ui.button>
                                        <x-ui.button type="button" variant="secondary" wire:click="rejectRequest({{ $request->id }})">{{ __('Reject') }}</x-ui.button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </x-slot:body>
                </x-ui.table>
            @endif
        </section>

        <section class="space-y-4">
            <h2 class="text-lg font-semibold">{{ __('Approved requests not yet linked to an event') }}</h2>
            @if ($approvedUnlinked->isEmpty())
                <p class="text-sm text-muted">{{ __('Every approved training request is linked to an event.') }}</p>
            @else
                <x-ui.table :caption="__('Approved training requests awaiting an event')">
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('Need') }}</x-ui.th>
                            <x-ui.th>{{ __('Priority') }}</x-ui.th>
                            <x-ui.th>{{ __('Event') }}</x-ui.th>
                        </tr>
                    </x-slot:head>
                    <x-slot:body>
                        @foreach ($approvedUnlinked as $request)
                            <tr wire:key="hr-unlinked-{{ $request->id }}" data-approved-unlinked="{{ $request->id }}">
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                                    <span class="font-medium">{{ $request->need }}</span>
                                    <span class="block text-muted">{{ $request->learning_objective }}</span>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $request->priority->value }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm space-y-2">
                                    @if ($linkableEvents === [])
                                        <span class="text-muted">{{ __('No scheduled event to link.') }}</span>
                                    @else
                                        <x-ui.select wire:model="linkEventId.{{ $request->id }}">
                                            <option value="">{{ __('Choose an event') }}</option>
                                            @foreach ($linkableEvents as $eventId => $label)
                                                <option value="{{ $eventId }}">{{ $label }}</option>
                                            @endforeach
                                        </x-ui.select>
                                        @error('link.'.$request->id)<p class="text-sm text-danger">{{ $message }}</p>@enderror
                                        <x-ui.button type="button" variant="primary" wire:click="linkEvent({{ $request->id }})">{{ __('Link') }}</x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-slot:body>
                </x-ui.table>
            @endif
            @if ($approvedLinked->isNotEmpty())
                <x-ui.table :caption="__('Approved training requests linked to an event')">
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('Need') }}</x-ui.th>
                            <x-ui.th>{{ __('Event') }}</x-ui.th>
                            <x-ui.th>{{ __('Linked') }}</x-ui.th>
                            <x-ui.th>{{ __('Action') }}</x-ui.th>
                        </tr>
                    </x-slot:head>
                    <x-slot:body>
                        @foreach ($approvedLinked as $request)
                            <tr wire:key="hr-linked-{{ $request->id }}" data-approved-linked="{{ $request->id }}">
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $request->need }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $eventTitles[(int) $request->training_event_id] ?? __('Event :id', ['id' => $request->training_event_id]) }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink tabular-nums">{{ $request->linked_at?->toDateString() }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm space-y-2">
                                    <x-ui.input type="text" wire:model="requestNotes.{{ $request->id }}" :placeholder="__('Reason (optional)')" />
                                    <x-ui.button type="button" variant="secondary" wire:click="unlinkEvent({{ $request->id }})">{{ __('Unlink') }}</x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </x-slot:body>
                </x-ui.table>
            @endif
        </section>

        <section class="space-y-4">
            <h2 class="text-lg font-semibold">{{ __('Training plans') }}</h2>
            @if ($plans->isEmpty())
                <p class="text-sm text-muted">{{ __('No submitted training plan awaits approval.') }}</p>
            @else
                <x-ui.table :caption="__('Training plans awaiting approval')">
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('Plan') }}</x-ui.th>
                            <x-ui.th>{{ __('Period') }}</x-ui.th>
                            <x-ui.th>{{ __('Decision') }}</x-ui.th>
                        </tr>
                    </x-slot:head>
                    <x-slot:body>
                        @foreach ($plans as $plan)
                            <tr wire:key="hr-plan-{{ $plan->id }}">
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                                    <span class="font-medium">{{ __('Plan :key v:version', ['key' => $plan->plan_key, 'version' => $plan->version]) }}</span>
                                    <span class="block text-muted">{{ $plan->objectives }}</span>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink tabular-nums">{{ $plan->period_start->format('Y-m-d') }} – {{ $plan->period_end->format('Y-m-d') }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm">
                                    <x-ui.button type="button" variant="primary" wire:click="approvePlan({{ $plan->id }})">{{ __('Approve plan') }}</x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </x-slot:body>
                </x-ui.table>
            @endif
        </section>

        <section class="space-y-4">
            <h2 class="text-lg font-semibold">{{ __('Skill reassessments') }}</h2>
            @if ($reassessments->isEmpty())
                <p class="text-sm text-muted">{{ __('No skill reassessment awaits HR decision.') }}</p>
            @else
                <x-ui.table :caption="__('Skill reassessments awaiting HR decision')">
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('Employee') }}</x-ui.th>
                            <x-ui.th>{{ __('Skill') }}</x-ui.th>
                            <x-ui.th>{{ __('Reason') }}</x-ui.th>
                            <x-ui.th>{{ __('Record new level') }}</x-ui.th>
                        </tr>
                    </x-slot:head>
                    <x-slot:body>
                        @foreach ($reassessments as $reassessment)
                            <tr wire:key="hr-reassessment-{{ $reassessment->id }}">
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $reassessmentEmployees[$reassessment->employee_entity_id] ?? __('Unknown employee') }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $reassessmentSkills[$reassessment->skill_id] ?? __('Unknown skill') }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                                    <span class="font-medium">{{ $reassessment->reason }}</span>
                                    <span class="block text-muted">{{ $reassessmentSources[$reassessment->id] ?? __('From head of department') }}</span>
                                    <span class="block text-muted">{{ __('Due :date', ['date' => $reassessment->due_at->format('d M Y')]) }}</span>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-sm space-y-2">
                                    @error('reassessment.'.$reassessment->id)
                                        <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
                                    @enderror
                                    <x-ui.input type="number" min="0" max="5" wire:model="reassessmentLevels.{{ $reassessment->id }}" :placeholder="__('New level 0–5')" />
                                    <x-ui.input type="date" wire:model="reassessmentDates.{{ $reassessment->id }}" />
                                    <x-ui.input type="text" wire:model="reassessmentNotes.{{ $reassessment->id }}" :placeholder="__('Assessor note (required)')" />
                                    <div class="flex gap-2">
                                        <x-ui.button type="button" variant="primary" wire:click="performReassessment({{ $reassessment->id }})">{{ __('Record and close') }}</x-ui.button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </x-slot:body>
                </x-ui.table>
            @endif
        </section>

        <section class="space-y-4">
            <h2 class="text-lg font-semibold">{{ __('Evidence submissions') }}</h2>
            @if ($evidenceSubmissions->isEmpty())
                <p class="text-sm text-muted">{{ __('No evidence submission awaits HR decision.') }}</p>
            @else
                <x-ui.table :caption="__('Evidence submissions awaiting HR decision')">
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('Employee') }}</x-ui.th>
                            <x-ui.th>{{ __('Reflection') }}</x-ui.th>
                            <x-ui.th>{{ __('Decide') }}</x-ui.th>
                        </tr>
                    </x-slot:head>
                    <x-slot:body>
                        @foreach ($evidenceSubmissions as $submission)
                            <tr wire:key="hr-evidence-{{ $submission->id }}">
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $evidenceEmployees[$submission->participant_id] ?? __('Unknown employee') }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                                    <span class="font-medium">{{ $submission->reflection }}</span>
                                    @if ($submission->certificate_number !== null)
                                        <span class="block text-muted">{{ __('Certificate :number', ['number' => $submission->certificate_number]) }}</span>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-sm space-y-2">
                                    @error('evidence.'.$submission->id)
                                        <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
                                    @enderror
                                    <x-ui.input type="text" wire:model="evidenceReturnNotes.{{ $submission->id }}" :placeholder="__('Return note (required to return)')" />
                                    <div class="flex gap-2">
                                        <x-ui.button type="button" variant="primary" wire:click="confirmEvidence({{ $submission->id }})">{{ __('Confirm') }}</x-ui.button>
                                        <x-ui.button type="button" variant="secondary" wire:click="returnEvidence({{ $submission->id }})">{{ __('Return') }}</x-ui.button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </x-slot:body>
                </x-ui.table>
            @endif
        </section>

        <section class="space-y-4">
            <h2 class="text-lg font-semibold">{{ __('Escalated reviews') }}</h2>
            @if ($escalations->isEmpty())
                <p class="text-sm text-muted">{{ __('No performance review has outlasted two weekly reminders.') }}</p>
            @else
                {{-- Listed, not actioned: the review stays the manager's to
                     finish, and HR reading it is the whole point. --}}
                <x-ui.table :caption="__('Escalated performance reviews')">
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('Review') }}</x-ui.th>
                            <x-ui.th>{{ __('Manager') }}</x-ui.th>
                            <x-ui.th>{{ __('Escalated to') }}</x-ui.th>
                            <x-ui.th>{{ __('Raised') }}</x-ui.th>
                        </tr>
                    </x-slot:head>
                    <x-slot:body>
                        @foreach ($escalations as $escalation)
                            <tr wire:key="escalation-{{ $escalation->id }}">
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink tabular-nums">#{{ $escalation->review_id }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink tabular-nums">#{{ $escalation->manager_user_id }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm">
                                    @if ($escalation->escalated_to_user_id === null)
                                        <x-ui.badge variant="warning">{{ $escalation->audience->label() }}</x-ui.badge>
                                    @else
                                        <span class="text-ink tabular-nums">#{{ $escalation->escalated_to_user_id }}</span>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted">
                                    <x-ui.datetime :value="$escalation->notified_at" />
                                </td>
                            </tr>
                        @endforeach
                    </x-slot:body>
                </x-ui.table>
            @endif
        </section>

        <section class="space-y-4" data-failed-deliveries-section>
            <h2 class="text-lg font-semibold">{{ __('Failed reminder deliveries') }}</h2>
            @if ($failedDeliveries->isEmpty())
                <p class="text-sm text-muted">{{ __('Every skill reminder claimed this period reached its recipient.') }}</p>
            @else
                {{-- Read-only: the retry is people:reminders-send --retry, run by
                     an operator, so a failure is visible here without giving the
                     page a way to resend. --}}
                <x-ui.table :caption="__('Skill reminder deliveries that failed')">
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('Rule') }}</x-ui.th>
                            <x-ui.th>{{ __('Employee') }}</x-ui.th>
                            <x-ui.th>{{ __('Skill') }}</x-ui.th>
                            <x-ui.th>{{ __('Recipient') }}</x-ui.th>
                            <x-ui.th>{{ __('Period') }}</x-ui.th>
                            <x-ui.th>{{ __('Failure') }}</x-ui.th>
                            <x-ui.th>{{ __('Attempted') }}</x-ui.th>
                        </tr>
                    </x-slot:head>
                    <x-slot:body>
                        @foreach ($failedDeliveries as $delivery)
                            <tr wire:key="failed-delivery-{{ $delivery->id }}">
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $delivery->rule->value }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink tabular-nums">#{{ $delivery->employee_entity_id }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink tabular-nums">#{{ $delivery->skill_id }}@if ($delivery->developmentActionId() !== null) <span class="text-muted">{{ __('action') }} #{{ $delivery->developmentActionId() }}</span>@endif</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink tabular-nums">#{{ $delivery->recipient_user_id }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted tabular-nums">{{ $delivery->period_key }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $delivery->failure }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted">
                                    <x-ui.datetime :value="$delivery->attempted_at" />
                                </td>
                            </tr>
                        @endforeach
                    </x-slot:body>
                </x-ui.table>
            @endif
        </section>

        <section class="space-y-4" data-passport-section>
            <h2 class="text-lg font-semibold">{{ __('Training passports') }}</h2>
            <p class="text-sm text-muted">{{ __('Generate a stored, watermarked PDF of an employee\'s completed events, certificates and current skill levels. Copies are kept for :days days and every generation and download is recorded.', ['days' => \App\Domains\People\Training\Services\TrainingPassportDocumentStore::RETENTION_DAYS]) }}</p>
            @if ($passportEmployees === [])
                <p class="text-sm text-muted">{{ __('No active employee is listed for this company.') }}</p>
            @else
                <x-ui.table :caption="__('Training passports by employee')">
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('Employee') }}</x-ui.th>
                            <x-ui.th>{{ __('Latest copy') }}</x-ui.th>
                            <x-ui.th>{{ __('Passport') }}</x-ui.th>
                        </tr>
                    </x-slot:head>
                    <x-slot:body>
                        @foreach ($passportEmployees as $employeeId => $name)
                            @php($document = $passportDocuments[$employeeId] ?? null)
                            <tr wire:key="passport-employee-{{ $employeeId }}">
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $name }} <span class="text-muted tabular-nums">#{{ $employeeId }}</span></td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted">
                                    @if ($document === null)
                                        {{ __('None retained') }}
                                    @else
                                        <a href="{{ route('people.training.passport.document', ['documentId' => $document->id]) }}" class="font-medium text-accent underline underline-offset-2" data-passport-document-download="{{ $document->id }}"><x-ui.datetime :value="$document->generated_at" format="datetime" /></a>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    <x-ui.button type="button" variant="secondary" wire:click="generatePassportPdf({{ $employeeId }})" wire:loading.attr="disabled">{{ __('Generate PDF') }}</x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </x-slot:body>
                </x-ui.table>
            @endif
        </section>
    @endif
</div>
