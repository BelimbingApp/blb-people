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
            <h2 class="text-lg font-semibold">
                @if ($revisingRequestId !== null)
                    {{ __('Revise rejected request #:id', ['id' => $revisingRequestId]) }}
                @else
                    {{ __('New request') }}
                @endif
            </h2>
            @if ($employees === [])
                <p class="text-sm text-muted">{{ __('No employee record is bound to your account in this company, so there is nobody you may request training for.') }}</p>
            @else
                <form wire:submit="draft" class="grid gap-4 md:grid-cols-2">
                    @if ($revisingRequest !== null)
                        {{-- A revision never moves identity: requestor and
                             department stay the row's, so the form names them
                             as read-only copy instead of offering selects the
                             save path would ignore. --}}
                        <p class="text-sm text-ink">{{ __('Requestor') }}: <span class="font-medium">{{ $employees[(int) $revisingRequest->requestor_subject_id] ?? __('Employee :id', ['id' => $revisingRequest->requestor_subject_id]) }}</span></p>
                        <p class="text-sm text-ink">{{ __('Department') }}: <span class="font-medium">{{ $departments[$revisingRequest->department_subject_id] ?? $revisingRequest->department_subject_id }}</span></p>
                    @else
                        <x-ui.select wire:model="requestorEntityId" :label="__('Requestor')">
                            <option value="">{{ __('Choose an employee') }}</option>
                            @foreach ($employees as $entityId => $name)
                                <option value="{{ $entityId }}">{{ $name }}</option>
                            @endforeach
                        </x-ui.select>
                        {{-- Who attends. The store decides whether this actor may
                             ask for each; the page only offers the choice. --}}
                        <x-ui.select wire:model.live="subjectMode" :label="__('Training is for')">
                            <option value="self">{{ __('The requestor') }}</option>
                            <option value="member">{{ __('A member of the department') }}</option>
                            <option value="department">{{ __('The whole department') }}</option>
                        </x-ui.select>
                        @if ($subjectMode === 'member')
                            <x-ui.select wire:model="subjectEmployeeEntityId" :label="__('Department member')">
                                <option value="">{{ __('Choose an employee') }}</option>
                                @foreach ($employees as $entityId => $name)
                                    <option value="{{ $entityId }}">{{ $name }}</option>
                                @endforeach
                            </x-ui.select>
                        @endif
                    @endif
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
                    <x-ui.input type="number" min="0" step="0.0001" wire:model="estimatedCost" :label="__('Estimated cost')" />
                    <x-ui.input type="text" wire:model="proposedDeliveryMethod" :label="__('Proposed delivery method')" />
                    <x-ui.input type="text" wire:model="proposedProvider" :label="__('Proposed trainer or provider')" />
                    <x-ui.input type="date" wire:model="proposedStartDate" :label="__('Proposed start date')" />
                    <x-ui.input type="date" wire:model="proposedEndDate" :label="__('Proposed end date')" />
                    <div class="md:col-span-2 space-y-2">
                        @foreach (['requestorEntityId', 'needSource', 'priority', 'need', 'learningObjective', 'expectedResult', 'estimatedCost', 'proposedDeliveryMethod', 'proposedProvider', 'proposedStartDate', 'proposedEndDate', 'revisionNotes'] as $field)
                            @error($field)<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        @endforeach
                        @if ($revisingRequestId !== null)
                            <x-ui.input type="text" wire:model="revisionNotes" :label="__('What changed in this revision')" />
                            <div class="flex flex-wrap gap-2">
                                <x-ui.button type="submit" variant="primary">{{ __('Save revision') }}</x-ui.button>
                                <x-ui.button type="button" variant="secondary" wire:click="cancelRevision">{{ __('Cancel revision') }}</x-ui.button>
                            </div>
                        @else
                            <x-ui.button type="submit" variant="primary">{{ __('Save draft') }}</x-ui.button>
                        @endif
                    </div>
                </form>
            @endif
        </section>

        <section class="space-y-4">
            <h2 class="text-lg font-semibold">{{ __('Your requests') }}</h2>
            @if ($requests->isEmpty())
                <p class="text-sm text-muted">{{ __('No training request yet.') }}</p>
            @else
                <x-ui.table :caption="__('Your training requests')">
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
                                    @elseif (in_array($request->id, $revisable, true))
                                        <x-ui.button type="button" variant="primary" wire:click="startRevision({{ $request->id }})">{{ __('Revise') }}</x-ui.button>
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
