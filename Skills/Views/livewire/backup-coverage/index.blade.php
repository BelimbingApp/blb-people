<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Critical skill backup coverage')"
        :subtitle="__('How many people can currently cover each critical skill, and where that is only one.')"
    />

    <x-ui.card>
        <x-ui.table container="flush" :caption="__('Critical skill coverage')">
            <x-slot name="head">
                <tr>
                    <x-ui.th>{{ __('Skill') }}</x-ui.th>
                    <x-ui.th align="right">{{ __('People covering') }}</x-ui.th>
                    <x-ui.th>{{ __('Resilience') }}</x-ui.th>
                    <x-ui.th>{{ __('Who covers it') }}</x-ui.th>
                </tr>
            </x-slot>
            @forelse ($rows as $row)
                <tr wire:key="coverage-{{ $loop->index }}" data-coverage-count="{{ $row['covered'] }}">
                    <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">{{ $row['skill'] }}</td>
                    <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">{{ $row['covered'] }}</td>
                    <td class="px-table-cell-x py-table-cell-y text-sm">
                        @if ($row['single_point_of_failure'])
                            {{-- The whole point of the page: one person, or none. --}}
                            <x-ui.badge variant="danger">{{ __('Single point of failure') }}</x-ui.badge>
                        @else
                            <span class="text-muted">{{ __('Covered') }}</span>
                        @endif
                    </td>
                    <td class="px-table-cell-x py-table-cell-y text-sm text-muted">
                        {{ $row['holders'] === [] ? __('Nobody currently qualified') : implode(', ', $row['holders']) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-table-cell-x py-10 text-center text-sm text-muted">
                        {{ __('No critical skill requirements are recorded for this company.') }}
                    </td>
                </tr>
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <x-ui.card>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <p class="text-sm text-muted">
                {{ __('A department is covered when :count people hold the skill at the level asked for.', ['count' => $minimum]) }}
            </p>
            @if ($departments !== [])
                <label class="text-sm text-muted">
                    <span class="sr-only">{{ __('Department') }}</span>
                    <select wire:model.live="department" class="rounded border-muted text-sm">
                        <option value="">{{ __('All departments') }}</option>
                        @foreach ($departments as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
        </div>

        <x-ui.table container="flush" :caption="__('Backup coverage by department')">
            <x-slot name="head">
                <tr>
                    <x-ui.th>{{ __('Department') }}</x-ui.th>
                    <x-ui.th>{{ __('Skill') }}</x-ui.th>
                    <x-ui.th align="right">{{ __('Required level') }}</x-ui.th>
                    <x-ui.th align="right">{{ __('Holders') }}</x-ui.th>
                    <x-ui.th align="right">{{ __('Minimum') }}</x-ui.th>
                    <x-ui.th>{{ __('Cover') }}</x-ui.th>
                </tr>
            </x-slot>
            @forelse ($departmentRows as $row)
                <tr wire:key="dept-coverage-{{ $row['department_id'] ?? 'none' }}-{{ $row['skill_id'] }}">
                    <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $row['department'] }}</td>
                    <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">{{ $row['skill'] }}</td>
                    <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">{{ $row['required_level'] }}</td>
                    <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">{{ $row['holders'] }}</td>
                    <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-muted">{{ $row['minimum'] }}</td>
                    <td class="px-table-cell-x py-table-cell-y text-sm">
                        @if ($row['covered'])
                            <x-ui.badge variant="success">{{ __('Covered') }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">{{ __('Short of cover') }}</x-ui.badge>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-table-cell-x py-10 text-center text-sm text-muted">
                        {{ __('No critical skill has been assessed in this department.') }}
                    </td>
                </tr>
            @endforelse
        </x-ui.table>
    </x-ui.card>
</div>
