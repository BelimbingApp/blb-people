<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Reassessment queue')"
        :subtitle="__('Requests waiting to be reassessed, oldest due first.')"
    />

    <x-ui.card>
        <div class="flex flex-wrap items-end gap-4">
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-muted">{{ __('Status') }}</span>
                <select wire:model.live="status" class="rounded-input border-line bg-surface text-sm text-ink">
                    <option value="">{{ __('Any status') }}</option>
                    @foreach ($statuses as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="text-muted">{{ __('Raised by') }}</span>
                <select wire:model.live="source" class="rounded-input border-line bg-surface text-sm text-ink">
                    <option value="">{{ __('Anyone') }}</option>
                    {{-- A head asked for this one; the other was opened by a confirmed
                         pass or certificate. They are answered differently. --}}
                    <option value="hod">{{ __('Head of department') }}</option>
                    <option value="training">{{ __('Training result') }}</option>
                </select>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="text-muted">{{ __('Department') }}</span>
                <select wire:model.live="department" class="rounded-input border-line bg-surface text-sm text-ink">
                    <option value="">{{ __('All departments') }}</option>
                    @foreach ($departments as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex items-center gap-2 text-sm text-ink">
                <input type="checkbox" wire:model.live="overdueOnly" value="1" class="rounded border-line">
                {{ __('Overdue only') }}
            </label>
        </div>
    </x-ui.card>

    <x-ui.card>
        {{-- Say what the number counts and when it was true, rather than showing a
             bare figure whose definition the reader has to guess (#16). --}}
        <p class="text-sm text-muted" data-overdue-count="{{ $overdueCount }}">
            {{ trans_choice(
                '{0}No request in this view is past its due date as of :date|{1}:count request is past its due date as of :date|[2,*]:count requests are past their due date as of :date',
                $overdueCount,
                ['count' => $overdueCount, 'date' => $asOf],
            ) }}
        </p>
    </x-ui.card>

    <x-ui.card>
        <x-ui.table container="flush" :caption="__('Reassessment requests')">
            <x-slot name="head">
                <tr>
                    <x-ui.th>{{ __('Employee') }}</x-ui.th>
                    <x-ui.th>{{ __('Skill') }}</x-ui.th>
                    <x-ui.th>{{ __('Department') }}</x-ui.th>
                    <x-ui.th>{{ __('Due') }}</x-ui.th>
                    <x-ui.th>{{ __('Age') }}</x-ui.th>
                    <x-ui.th>{{ __('Raised by') }}</x-ui.th>
                    <x-ui.th>{{ __('Status') }}</x-ui.th>
                </tr>
            </x-slot>
            @forelse ($rows as $row)
                <tr wire:key="reassessment-{{ $row['id'] }}"
                    data-request-id="{{ $row['id'] }}"
                    data-days="{{ $row['days'] }}"
                    data-overdue="{{ $row['overdue'] ? '1' : '0' }}">
                    <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">{{ $row['employee'] }}</td>
                    <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $row['skill'] }}</td>
                    <td class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ $row['department'] }}</td>
                    <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums text-ink">{{ $row['due_at'] }}</td>
                    <td class="px-table-cell-x py-table-cell-y text-sm">
                        @if ($row['overdue'])
                            {{-- Late is stated in words as well as colour: a badge nobody
                                 can distinguish is not a signal. --}}
                            <x-ui.badge variant="danger">
                                {{ trans_choice('{1}:count day late|[2,*]:count days late', $row['days'], ['count' => $row['days']]) }}
                            </x-ui.badge>
                        @elseif ($row['days'] === 0)
                            <span class="text-muted">{{ __('Due today') }}</span>
                        @else
                            <span class="text-muted">
                                {{ trans_choice('{1}:count day left|[2,*]:count days left', abs($row['days']), ['count' => abs($row['days'])]) }}
                            </span>
                        @endif
                    </td>
                    <td class="px-table-cell-x py-table-cell-y text-sm text-muted">
                        {{ $row['source'] === 'training' ? __('Training result') : __('Head of department') }}
                    </td>
                    <td class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ $row['status'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-table-cell-x py-10 text-center text-sm text-muted">
                        {{ __('No reassessment requests match this view.') }}
                    </td>
                </tr>
            @endforelse
        </x-ui.table>
    </x-ui.card>
</div>
