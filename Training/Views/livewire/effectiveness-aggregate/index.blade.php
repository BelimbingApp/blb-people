<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Training effectiveness')"
        :subtitle="__('Applied-rating means and answer rates per course at each checkpoint, for events that ended in the last twelve months.')"
    />

    @if (session('effectiveness-policy-status'))
        <x-ui.alert variant="success">{{ session('effectiveness-policy-status') }}</x-ui.alert>
    @endif

    @if ($mayManage)
        <x-ui.card>
            <div class="space-y-4">
                <h2 class="text-lg font-semibold text-ink">{{ __('Set checkpoint policy') }}</h2>
                <p class="text-sm text-muted">
                    {{ __('How many days after an event ends each effectiveness question opens. Changes are prospective and append-only.') }}
                </p>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <x-ui.input type="number" min="1" wire:model="day30" :label="__('30-day offset (days)')" />
                    <x-ui.input type="number" min="1" wire:model="day60" :label="__('60-day offset (days)')" />
                    <x-ui.input type="number" min="1" wire:model="day90" :label="__('90-day offset (days)')" />
                    <x-ui.input type="date" wire:model="effectiveFrom" :label="__('Effective from')" />
                    <div class="sm:col-span-2">
                        <x-ui.input type="text" wire:model="reason" :label="__('Reason')" />
                    </div>
                </div>
                <x-ui.button type="button" variant="primary" wire:click="setPolicy">
                    {{ __('Record policy') }}
                </x-ui.button>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card>
        <div class="space-y-4">
            <h2 class="text-lg font-semibold text-ink">{{ __('Checkpoint policy history') }}</h2>
            @if ($policyHistory === [])
                <p class="text-sm text-muted">{{ __('No company policy has been set; workbook defaults (30 / 60 / 90) apply.') }}</p>
            @else
                <x-ui.table :caption="__('Checkpoint policy history for this company')">
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('Effective from') }}</x-ui.th>
                            <x-ui.th>{{ __('30-day') }}</x-ui.th>
                            <x-ui.th>{{ __('60-day') }}</x-ui.th>
                            <x-ui.th>{{ __('90-day') }}</x-ui.th>
                            <x-ui.th>{{ __('Set by') }}</x-ui.th>
                            <x-ui.th>{{ __('Reason') }}</x-ui.th>
                        </tr>
                    </x-slot:head>
                    <x-slot:body>
                        @foreach ($policyHistory as $policy)
                            <tr wire:key="policy-{{ $policy->id }}">
                                <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums">{{ $policy->effective_from->toDateString() }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums">{{ $policy->day_30_offset }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums">{{ $policy->day_60_offset }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums">{{ $policy->day_90_offset }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $setByNames[$policy->set_by_user_id] ?? __('Unknown') }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ $policy->reason }}</td>
                            </tr>
                        @endforeach
                    </x-slot:body>
                </x-ui.table>
            @endif
        </div>
    </x-ui.card>

    @if ($departments !== [])
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <x-ui.button type="button" wire:click="$set('departmentEntityId', null)" :variant="$departmentEntityId === null ? 'primary' : 'secondary'">
                {{ __('All departments') }}
            </x-ui.button>
            @foreach ($departments as $id => $name)
                <x-ui.button type="button" wire:click="$set('departmentEntityId', {{ $id }})" :variant="$departmentEntityId === $id ? 'primary' : 'secondary'">
                    {{ $name }}
                </x-ui.button>
            @endforeach
        </div>
    @endif

    @if ($rows === [])
        <x-ui.alert variant="info">{{ __('No attended training in the last twelve months has reached a checkpoint yet.') }}</x-ui.alert>
    @else
        @foreach ($rows as $row)
            <x-ui.card wire:key="course-{{ $row->courseId }}">
                <div class="space-y-4">
                    <h2 class="text-lg font-semibold text-ink">{{ $row->courseTitle }}</h2>

                    <x-ui.table :caption="__('Effectiveness checkpoints for :course', ['course' => $row->courseTitle])">
                        <x-slot:head>
                            <tr>
                                <x-ui.th>{{ __('Checkpoint') }}</x-ui.th>
                                <x-ui.th>{{ __('Opened') }}</x-ui.th>
                                <x-ui.th>{{ __('Answered') }}</x-ui.th>
                                <x-ui.th>{{ __('Answer rate') }}</x-ui.th>
                                <x-ui.th>{{ __('Mean applied rating') }}</x-ui.th>
                            </tr>
                        </x-slot:head>
                        <x-slot:body>
                            @foreach ($checkpoints as $checkpoint)
                                @php($stats = $row->checkpoints[$checkpoint->value])
                                <tr wire:key="course-{{ $row->courseId }}-{{ $checkpoint->value }}">
                                    <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $checkpoint->label() }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums">{{ $stats->opened }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums">{{ $stats->answered }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums">
                                        @if ($stats->answerRate === null)
                                            {{-- Not reached is not nought answered. --}}
                                            <span class="text-muted">{{ __('Not reached') }}</span>
                                        @else
                                            {{ $stats->answerRate }}%
                                        @endif
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums">
                                        @if ($stats->meanRating === null)
                                            <span class="text-muted">{{ __('No answers') }}</span>
                                        @else
                                            {{ number_format($stats->meanRating, 2) }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </x-slot:body>
                    </x-ui.table>

                    <x-ui.table :caption="__('Open follow-up development actions for :course', ['course' => $row->courseTitle])">
                        <x-slot:head>
                            <tr>
                                <x-ui.th>{{ __('Open follow-up') }}</x-ui.th>
                                <x-ui.th>{{ __('Drill down') }}</x-ui.th>
                            </tr>
                        </x-slot:head>
                        <x-slot:body>
                            <tr wire:key="course-{{ $row->courseId }}-follow-up">
                                <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums">{{ count($row->openFollowUpActionIds) }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm">
                                    @if ($row->openFollowUpActionIds === [])
                                        <span class="text-muted">{{ __('Nothing outstanding') }}</span>
                                    @else
                                        <x-ui.link :href="route('people.skill.development-actions.index', ['focusActionIds' => $row->openFollowUpActionIds])">
                                            {{ __('See the actions still running') }}
                                        </x-ui.link>
                                    @endif
                                </td>
                            </tr>
                        </x-slot:body>
                    </x-ui.table>

                    @if ($row->comments !== [])
                        <section class="space-y-2">
                            <h3 class="text-sm font-semibold text-ink">{{ __('Comments') }} ({{ count($row->comments) }})</h3>
                            @foreach ($row->comments as $comment)
                                <p class="text-sm text-muted">
                                    <x-ui.badge variant="neutral">{{ $comment->checkpoint->label() }}</x-ui.badge>
                                    <span class="text-ink">{{ $comment->comment }}</span>
                                    — {{ $comment->answeredBy }}
                                </p>
                            @endforeach
                        </section>
                    @endif
                </div>
            </x-ui.card>
        @endforeach
    @endif
</div>
