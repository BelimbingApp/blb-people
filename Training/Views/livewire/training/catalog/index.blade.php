<div class="space-y-section-gap">
    <x-ui.page-header :title="__('Training catalog')" :subtitle="__('Maintain the company course catalog before scheduling delivery.')">
        @if ($canManage && $hasActiveSkills)
            <x-slot:actions>
                <x-ui.button
                    variant="primary"
                    as="a"
                    href="{{ route('people.training.catalog.create', $listQuery) }}"
                    wire:navigate
                >
                    {{ __('New course') }}
                </x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    @endif

    @if ($companies === [])
        <x-ui.alert variant="info">{{ __('No authorized workforce company is available.') }}</x-ui.alert>
    @else
        @if (count($companies) > 1)
            <div class="flex flex-wrap gap-2" aria-label="{{ __('Workforce company') }}">
                @foreach ($companies as $entityId => $name)
                    <x-ui.button type="button" wire:click="selectCompany({{ $entityId }})" :variant="$companyEntityId === $entityId ? 'primary' : 'secondary'">{{ $name }}</x-ui.button>
                @endforeach
            </div>
        @endif

        @if ($canManage && ! $hasActiveSkills)
            <x-ui.alert variant="info">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="font-medium text-ink">{{ __('Add an active skill before defining a course.') }}</p>
                        <p class="mt-1 text-sm text-muted">{{ __('Every course must cover at least one active company skill.') }}</p>
                    </div>
                    @if ($canManageSkills)
                        <x-ui.button :href="route('people.skill.catalog.index')" variant="control" class="shrink-0 self-start sm:self-auto">
                            {{ __('Set up skills') }}
                        </x-ui.button>
                    @else
                        <p class="text-sm text-muted">{{ __('Ask a People skills administrator to add an active skill.') }}</p>
                    @endif
                </div>
            </x-ui.alert>
        @endif

        <x-ui.card>
            <x-ui.filter-bar class="mb-2">
                <x-slot:search>
                    <x-ui.search-input
                        wire:key="training-catalog-search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search by code or title...') }}"
                    />
                </x-slot:search>
                <x-ui.select
                    id="training-catalog-status"
                    wire:model.live="status"
                    :label="__('Status')"
                >
                    <option value="">{{ __('All statuses') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                </x-ui.select>
                <x-ui.select
                    id="training-catalog-delivery"
                    wire:model.live="delivery"
                    :label="__('Delivery mode')"
                >
                    <option value="">{{ __('All delivery modes') }}</option>
                    @foreach ($deliveryModes as $mode)
                        <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.filter-bar>

            <x-ui.table container="flush" :caption="__('Training courses')">
                <x-slot:head>
                    <tr>
                        <x-ui.sortable-th
                            column="code"
                            :sort-by="$sortBy"
                            :sort-dir="$sortDir"
                            action="sort('code')"
                            :label="__('Training ID')"
                        />
                        <x-ui.sortable-th
                            column="title"
                            :sort-by="$sortBy"
                            :sort-dir="$sortDir"
                            action="sort('title')"
                            :label="__('Course')"
                        />
                        <x-ui.sortable-th
                            column="delivery_mode"
                            :sort-by="$sortBy"
                            :sort-dir="$sortDir"
                            action="sort('delivery_mode')"
                            :label="__('Delivery')"
                        />
                        <x-ui.th>{{ __('Skills') }}</x-ui.th>
                        <x-ui.sortable-th
                            column="active"
                            :sort-by="$sortBy"
                            :sort-dir="$sortDir"
                            action="sort('active')"
                            :label="__('Status')"
                        />
                        @if ($canManage)
                            <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
                        @endif
                    </tr>
                </x-slot:head>
                <x-slot:body>
                    @forelse ($courses ?? [] as $course)
                        <tr wire:key="training-course-{{ $course->id }}" @class(['opacity-60' => ! $course->active])>
                            <td class="px-table-cell-x py-table-cell-y font-mono text-sm">{{ $course->code }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm">
                                <span class="font-medium">{{ $course->title }}</span>
                                @if ($course->description)
                                    <span class="block text-muted">{{ $course->description }}</span>
                                @endif
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-sm">{{ $course->delivery_mode->label() }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm">{{ $course->mappedSkills()->pluck('code')->join(', ') }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm">
                                <x-ui.badge :variant="$course->active ? 'success' : 'neutral'">
                                    {{ $course->active ? __('Active') : __('Inactive') }}
                                </x-ui.badge>
                            </td>
                            @if ($canManage)
                                <td class="px-table-cell-x py-table-cell-y text-sm text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <x-ui.link
                                            href="{{ route('people.training.catalog.edit', array_merge($listQuery, ['courseId' => $course->id])) }}"
                                            wire:navigate
                                        >
                                            {{ __('Edit') }}
                                        </x-ui.link>
                                        <x-ui.button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            wire:click="toggleCourseActive({{ $course->id }})"
                                        >
                                            {{ $course->active ? __('Deactivate') : __('Reactivate') }}
                                        </x-ui.button>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canManage ? 6 : 5 }}" class="px-table-cell-x py-8 text-center text-sm text-muted">
                                {{ $search !== '' || $status !== '' || $delivery !== ''
                                    ? __('No courses match the current filters.')
                                    : __('No courses are defined for this company.') }}
                            </td>
                        </tr>
                    @endforelse
                </x-slot:body>
            </x-ui.table>

            @if ($courses !== null)
                <div class="mt-3">
                    <x-ui.pagination
                        :paginator="$courses"
                        :per-page-options="$this->perPageOptions()"
                        :per-page="$perPage"
                        id="training-catalog-per-page"
                    />
                </div>
            @endif
        </x-ui.card>
    @endif
</div>
