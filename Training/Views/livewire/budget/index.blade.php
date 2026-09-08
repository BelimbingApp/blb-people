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

    @if ($mayManage && $unallocatedDepartments !== [])
        <x-ui.card>
            <div class="space-y-4">
                <div class="max-w-3xl">
                    <h2 class="text-lg font-semibold text-ink">{{ __('Set a department’s first allocation') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Choose an active department with no allocation for :year. The reason is retained in the budget audit.', ['year' => $year]) }}</p>
                </div>
                <form wire:submit="saveFirst" class="grid gap-4 lg:grid-cols-[minmax(14rem,1fr)_minmax(12rem,0.7fr)_minmax(16rem,1.3fr)_auto] lg:items-end">
                    <x-ui.select id="training-budget-new-department" wire:model="newDepartmentEntityId" :label="__('Department')" required :error="$errors->first('newDepartmentEntityId')">
                        <option value="">{{ __('Choose a department') }}</option>
                        @foreach ($unallocatedDepartments as $departmentEntityId => $departmentName)
                            <option value="{{ $departmentEntityId }}">{{ $departmentName }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input id="training-budget-new-amount" type="text" inputmode="decimal" wire:model="newAmount" :label="__('Annual allocation')" :placeholder="__('0.0000')" required :error="$errors->first('newAmount')" />
                    <x-ui.input id="training-budget-new-reason" wire:model="newReason" :label="__('Reason')" :placeholder="__('Why this allocation is being set')" required :error="$errors->first('newReason')" />
                    <x-ui.button type="submit" class="w-full lg:w-auto" wire:loading.attr="disabled" wire:target="saveFirst">
                        <span wire:loading.remove wire:target="saveFirst">{{ __('Set allocation') }}</span>
                        <span wire:loading wire:target="saveFirst">{{ __('Setting allocation…') }}</span>
                    </x-ui.button>
                </form>
            </div>
        </x-ui.card>
    @elseif ($mayManage && $eligibleDepartments === [])
        <x-ui.alert variant="info">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <p class="font-medium text-ink">{{ __('No active departments are available for allocation.') }}</p>
                    <p class="mt-1 text-sm text-muted">{{ __('Add an active organization unit before setting a training budget.') }}</p>
                </div>
                @if ($mayManageDepartmentSetup)
                    <x-ui.button :href="route('people.settings.index')" variant="control" class="shrink-0 self-start sm:self-auto">
                        {{ __('Set up departments') }}
                    </x-ui.button>
                @else
                    <p class="text-sm text-muted">{{ __('Ask a People Settings administrator to add an active department.') }}</p>
                @endif
            </div>
        </x-ui.alert>
    @endif

    @if ($rows === [])
        <x-ui.alert variant="info">{{ __('No department has an allocation or a training request for this year yet.') }}</x-ui.alert>
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
                        <td class="px-table-cell-x text-ink">
                            <div class="space-y-2">
                                <span>{{ $row->departmentName }}</span>
                                @if ($row->budgetId !== null)
                                    @php($departmentHistory = $history[$row->departmentEntityId] ?? collect())
                                    <x-ui.disclosure
                                        :title="__('History (:count)', ['count' => $departmentHistory->count()])"
                                        panel-id="training-budget-{{ $row->departmentEntityId }}-history"
                                    >
                                        <ol class="space-y-2 text-sm">
                                            @forelse ($departmentHistory as $record)
                                                @php($actor = $historyActors[$record->actor_user_id] ?? null)
                                                <li wire:key="training-budget-history-{{ $record->id }}">
                                                    <span class="font-medium">{{ __($record->kind->label()) }}</span>
                                                    · <x-ui.datetime :value="$record->occurred_at" />
                                                    · <span class="text-muted">{{ __('by :actor', ['actor' => $actor?->name ?? __('Unavailable')]) }}</span>
                                                    <p class="text-muted">
                                                        @if ($record->previous_amount === null)
                                                            {{ __('Set to :amount', ['amount' => $record->amount]) }}
                                                        @else
                                                            {{ __(':from → :to', ['from' => $record->previous_amount, 'to' => $record->amount]) }}
                                                        @endif
                                                        @if ($record->overage_amount !== null)
                                                            · {{ __('overage :amount', ['amount' => $record->overage_amount]) }}
                                                        @endif
                                                    </p>
                                                    @if ($record->reason)<p>{{ $record->reason }}</p>@endif
                                                </li>
                                            @empty
                                                <li class="text-muted">{{ __('No budget changes have been recorded yet.') }}</li>
                                            @endforelse
                                        </ol>
                                    </x-ui.disclosure>
                                @endif
                            </div>
                        </td>
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
