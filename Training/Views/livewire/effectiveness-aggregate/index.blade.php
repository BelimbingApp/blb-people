<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Training effectiveness')"
        :subtitle="__('Applied-rating means and answer rates per course at each checkpoint, for events that ended in the last twelve months.')"
    />

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
