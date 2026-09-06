<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Training requests')"
        :subtitle="__('Draft a training request for yourself or, as a head of department, for a member of your department; submit it and follow its review. HR reviews and approves from the HR governance queue.')"
    />

    @if ($companies === [])
        <x-ui.alert variant="info">{{ __('No company is attributed to your role.') }}</x-ui.alert>
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

        @error('request')
            <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
        @enderror

        <section class="space-y-4">
            <h2 class="text-lg font-semibold">{{ __('New request') }}</h2>
            @if ($employees === [])
                <p class="text-sm text-muted">{{ __('No employee record is bound to your account in this company, so there is nobody you may request training for.') }}</p>
            @else
                <form wire:submit="draft" class="grid gap-4 md:grid-cols-2">
                    <x-ui.select wire:model="requestorEntityId" :label="__('Requestor')">
                        <option value="">{{ __('Choose an employee') }}</option>
                        @foreach ($employees as $entityId => $name)
                            <option value="{{ $entityId }}">{{ $name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select wire:model="needSource" :label="__('Need source')">
                        @foreach ($needSources as $source)
                            <option value="{{ $source->value }}">{{ __(str_replace('_', ' ', ucfirst($source->value))) }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select wire:model="priority" :label="__('Priority')">
                        @foreach ($priorities as $level)
                            <option value="{{ $level->value }}">{{ __(ucfirst($level->value)) }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input type="text" wire:model="need" :label="__('Training need')" />
                    <x-ui.input type="text" wire:model="learningObjective" :label="__('Learning objective')" />
                    <x-ui.input type="text" wire:model="expectedResult" :label="__('Expected result')" />
                    <div class="md:col-span-2 space-y-2">
                        @foreach (['requestorEntityId', 'needSource', 'priority', 'need', 'learningObjective', 'expectedResult'] as $field)
                            @error($field)<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        @endforeach
                        <x-ui.button type="submit" variant="primary">{{ __('Save draft') }}</x-ui.button>
                    </div>
                </form>
            @endif
        </section>

        <section class="space-y-4">
            <h2 class="text-lg font-semibold">{{ __('Your requests') }}</h2>
            @if ($requests->isEmpty())
                <p class="text-sm text-muted">{{ __('No training request yet.') }}</p>
            @else
                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('Need') }}</x-ui.th>
                            <x-ui.th>{{ __('Requestor') }}</x-ui.th>
                            <x-ui.th>{{ __('Department') }}</x-ui.th>
                            <x-ui.th>{{ __('Priority') }}</x-ui.th>
                            <x-ui.th>{{ __('Status') }}</x-ui.th>
                            <x-ui.th>{{ __('Action') }}</x-ui.th>
                        </tr>
                    </x-slot:head>
                    <x-slot:body>
                        @foreach ($requests as $request)
                            <tr wire:key="training-request-{{ $request->id }}">
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                                    <span class="font-medium">{{ $request->need }}</span>
                                    <span class="block text-muted">{{ $request->learning_objective }}</span>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $employees[(int) $request->requestor_subject_id] ?? __('Employee :id', ['id' => $request->requestor_subject_id]) }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $departments[$request->department_subject_id] ?? $request->department_subject_id }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $request->priority->value }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                                    <span data-status="{{ $request->status->value }}">{{ $request->status->value }}</span>
                                    @foreach ($request->decisions as $decision)
                                        <span class="block text-muted">{{ $decision->decision }} · {{ $decision->occurred_at }}</span>
                                    @endforeach
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-sm space-y-2">
                                    @if (in_array($request->id, $editable, true))
                                        <x-ui.button type="button" variant="primary" wire:click="submitRequest({{ $request->id }})">{{ __('Submit') }}</x-ui.button>
                                    @elseif (in_array($request->id, $recommendable, true))
                                        <x-ui.input type="text" wire:model="recommendNotes.{{ $request->id }}" :placeholder="__('Recommendation notes (optional)')" />
                                        <x-ui.button type="button" variant="primary" wire:click="recommend({{ $request->id }})">{{ __('Recommend') }}</x-ui.button>
                                    @else
                                        <span class="text-muted">{{ __('Awaiting the next reviewer') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-slot:body>
                </x-ui.table>
            @endif
        </section>
    @endif
</div>
