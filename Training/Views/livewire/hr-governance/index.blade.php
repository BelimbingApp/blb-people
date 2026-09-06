<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('HR governance')"
        :subtitle="__('Everything awaiting HR in this company: requirement publication, training requests and plan approvals. Each action runs the owning workflow and its own checks.')"
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
                <x-ui.table>
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
                <x-ui.table>
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
            <h2 class="text-lg font-semibold">{{ __('Training plans') }}</h2>
            @if ($plans->isEmpty())
                <p class="text-sm text-muted">{{ __('No submitted training plan awaits approval.') }}</p>
            @else
                <x-ui.table>
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
    @endif
</div>
