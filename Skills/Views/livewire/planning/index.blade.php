<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('HOD planning')"
        :subtitle="__('Skill gaps and open training needs for your departments. Actions only propose.')"
    />

    @error('planning')
        <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
    @enderror

    @if ($confirmation !== null)
        <x-ui.alert variant="success">{{ $confirmation }}</x-ui.alert>
    @endif

    @if ($units === [])
        <x-ui.alert variant="info">
            {{ __('No departments are assigned to you.') }}
        </x-ui.alert>
    @else
        @foreach ($units as $unit)
            <x-ui.card>
                <div class="mb-4">
                    <h2 class="text-lg font-medium tracking-tight text-ink">{{ $unit['name'] }}</h2>
                </div>

                <h3 class="text-sm font-medium text-ink">{{ __('Open skill gaps') }}</h3>
                <x-ui.table container="flush" :caption="__('Open skill gaps')" :row-hover="false">
                    <x-slot name="head">
                        <tr>
                            <x-ui.th>{{ __('Employee') }}</x-ui.th>
                            <x-ui.th>{{ __('Skill') }}</x-ui.th>
                            <x-ui.th align="right">{{ __('Required') }}</x-ui.th>
                            <x-ui.th align="right">{{ __('Assessed') }}</x-ui.th>
                            <x-ui.th align="right">{{ __('Gap') }}</x-ui.th>
                        </tr>
                    </x-slot>

                    @forelse ($unit['gaps'] as $gap)
                        <tr wire:key="hod-gap-{{ $gap['id'] }}">
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $gap['employee'] }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                            {{ $gap['skill'] }}
                            <span class="block text-xs text-muted">{{ $gap['reference'] }}</span>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">{{ $gap['required'] }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">{{ $gap['assessed'] ?? __('Not assessed') }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">{{ $gap['gap'] }}</td>
                        </tr>
                        <tr wire:key="hod-gap-propose-{{ $gap['id'] }}">
                        <td colspan="5" class="px-table-cell-x py-table-cell-y">
                            <div class="flex flex-wrap items-end gap-2 text-sm">
                                <x-ui.input :label="__('Objective')" wire:model="daObjective.{{ $gap['id'] }}" />
                                <x-ui.input :label="__('Intervention')" wire:model="daIntervention.{{ $gap['id'] }}" />
                                <x-ui.input :label="__('Evidence')" wire:model="daEvidence.{{ $gap['id'] }}" />
                                <x-ui.select :label="__('Trainer')" wire:model="daTrainer.{{ $gap['id'] }}" :block="false">
                                    <option value="">{{ __('Choose a trainer') }}</option>
                                    @foreach ($unit['employees'] as $trainer)
                                        <option value="{{ $trainer['id'] }}">{{ $trainer['name'] }}</option>
                                    @endforeach
                                </x-ui.select>
                                <x-ui.select :label="__('Owner')" wire:model="daOwner.{{ $gap['id'] }}" :block="false">
                                    <option value="">{{ __('Acting HOD (default)') }}</option>
                                    @foreach ($unit['employees'] as $owner)
                                        <option value="{{ $owner['id'] }}">{{ $owner['name'] }}</option>
                                    @endforeach
                                </x-ui.select>
                                <x-ui.select :label="__('HR coordinator')" wire:model="daHr.{{ $gap['id'] }}" :block="false">
                                    <option value="">{{ __('Choose HR coordinator') }}</option>
                                    @foreach ($unit['employees'] as $coordinator)
                                        <option value="{{ $coordinator['id'] }}">{{ $coordinator['name'] }}</option>
                                    @endforeach
                                </x-ui.select>
                                <x-ui.select :label="__('Type')" wire:model="daType.{{ $gap['id'] }}" :block="false">
                                    <option value="">{{ __('Coaching (default)') }}</option>
                                    <option value="on_the_job_training">{{ __('On-the-job training') }}</option>
                                    <option value="classroom_training">{{ __('Classroom training') }}</option>
                                    <option value="supervised_practice">{{ __('Supervised practice') }}</option>
                                    <option value="improvement_project">{{ __('Improvement project') }}</option>
                                </x-ui.select>
                                <button
                                    type="button"
                                    wire:click="proposeDevelopmentAction('{{ $gap['id'] }}')"
                                    class="font-medium underline"
                                >{{ __('Propose action') }}</button>
                            </div>
                        </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-table-cell-x py-10 text-center text-sm text-muted">
                                {{ __('No open skill gaps in this department.') }}
                            </td>
                        </tr>
                    @endforelse
                </x-ui.table>

                <h3 class="mt-6 text-sm font-medium text-ink">{{ __('Open training needs') }}</h3>
                <ul class="mt-1 space-y-1 text-sm">
                    @forelse ($unit['needs'] as $need)
                        <li wire:key="hod-need-{{ $need['id'] }}">
                            <span class="text-ink">{{ $need['need'] }}</span>
                            <span class="text-muted">{{ $need['employee'] }} · {{ $need['status'] }}</span>
                        </li>
                    @empty
                        <li class="text-muted">{{ __('No open training needs in this department.') }}</li>
                    @endforelse
                </ul>

                <h3 class="mt-6 text-sm font-medium text-ink">{{ __('Draft a training request') }}</h3>
                @foreach ($unit['employees'] as $employee)
                    <div wire:key="hod-request-{{ $employee['id'] }}" class="mt-2 flex flex-wrap items-end gap-2 text-sm">
                        <span class="font-medium text-ink">{{ $employee['name'] }}</span>
                        <x-ui.input :label="__('Need')" wire:model="reqNeed.{{ $employee['id'] }}" />
                        <x-ui.input :label="__('Objective')" wire:model="reqObjective.{{ $employee['id'] }}" />
                        <x-ui.input :label="__('Result')" wire:model="reqResult.{{ $employee['id'] }}" />
                        <button
                            type="button"
                            wire:click="draftTrainingRequest('{{ $employee['id'] }}')"
                            class="font-medium underline"
                        >{{ __('Draft request') }}</button>
                    </div>
                @endforeach
            </x-ui.card>
        @endforeach
    @endif
</div>
