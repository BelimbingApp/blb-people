<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Employee skill register')"
        :subtitle="__('Who holds which released skill level today, per department and skill, with expiry visibility.')"
    />

    @if ($companies === [])
        <x-ui.alert variant="info">{{ __('No company workforce data is synchronized yet.') }}</x-ui.alert>
    @else
        @if (count($companies) > 1)
            <div class="flex items-center gap-2 text-sm">
                <span class="text-muted">{{ __('Company') }}</span>
                @foreach ($companies as $entityId => $name)
                    <x-ui.button type="button" wire:click="selectCompany({{ $entityId }})" :variant="$companyEntityId === $entityId ? 'primary' : 'secondary'">{{ $name }}</x-ui.button>
                @endforeach
            </div>
        @endif

        <x-ui.card>
            <div class="flex flex-wrap items-end gap-4">
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
                @if ($skills !== [])
                    <label class="text-sm text-muted">
                        <span class="sr-only">{{ __('Skill') }}</span>
                        <select wire:model.live="skill" class="rounded border-muted text-sm">
                            <option value="">{{ __('All skills') }}</option>
                            @foreach ($skills as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <label class="text-sm text-muted">
                    <span class="sr-only">{{ __('Minimum level') }}</span>
                    <input type="number" min="0" wire:model.live="level" placeholder="{{ __('Min level') }}" class="w-28 rounded border-muted text-sm" />
                </label>
                <label class="text-sm text-muted">
                    <span class="sr-only">{{ __('Expiring within days') }}</span>
                    <input type="number" min="1" wire:model.live="expiringWithinDays" placeholder="{{ __('Expiring within days') }}" class="w-44 rounded border-muted text-sm" />
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-muted">
                    <input type="checkbox" wire:model.live="expiredOnly" class="rounded border-muted" />
                    {{ __('Expired only') }}
                </label>
                <label class="text-sm text-muted">
                    <span class="sr-only">{{ __('Sort') }}</span>
                    <select wire:model.live="sort" class="rounded border-muted text-sm">
                        <option value="employee">{{ __('Sort by employee') }}</option>
                        <option value="skill">{{ __('Sort by skill') }}</option>
                        <option value="valid_until">{{ __('Sort by valid until') }}</option>
                    </select>
                </label>
                <label class="text-sm text-muted">
                    <span class="sr-only">{{ __('Direction') }}</span>
                    <select wire:model.live="direction" class="rounded border-muted text-sm">
                        <option value="asc">{{ __('Ascending') }}</option>
                        <option value="desc">{{ __('Descending') }}</option>
                    </select>
                </label>
                <x-ui.button type="button" variant="secondary" wire:click="export">{{ __('Export CSV') }}</x-ui.button>
            </div>

            <x-ui.table container="flush" :caption="__('Employee skill register')">
                <x-slot name="head">
                    <tr>
                        <x-ui.th>{{ __('Employee') }}</x-ui.th>
                        <x-ui.th>{{ __('Department') }}</x-ui.th>
                        <x-ui.th>{{ __('Skill') }}</x-ui.th>
                        <x-ui.th align="right">{{ __('Level') }}</x-ui.th>
                        <x-ui.th>{{ __('Assessed on') }}</x-ui.th>
                        <x-ui.th>{{ __('Valid until') }}</x-ui.th>
                        <x-ui.th>{{ __('Status') }}</x-ui.th>
                    </tr>
                </x-slot>
                @forelse ($rows as $row)
                    <tr
                        wire:key="register-{{ $row['employee_id'] }}-{{ $row['skill_id'] }}"
                        data-employee="{{ $row['employee'] }}"
                        data-skill="{{ $row['skill'] }}"
                        data-expired="{{ $row['expired'] ? 'yes' : 'no' }}"
                    >
                        <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">{{ $row['employee'] }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ $row['department'] }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $row['skill'] }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">{{ $row['level'] ?? __('—') }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ $row['assessed_on'] }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ $row['valid_until'] ?? __('No expiry') }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm">
                            @if ($row['expired'])
                                <x-ui.badge variant="danger">{{ __('Expired') }}</x-ui.badge>
                            @else
                                <span class="text-muted">{{ __('Current') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-table-cell-x py-10 text-center text-sm text-muted">
                            {{ __('No released skill levels match these filters for this company.') }}
                        </td>
                    </tr>
                @endforelse
            </x-ui.table>
        </x-ui.card>
    @endif
</div>
