<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Skills')"
        :subtitle="__('Company skill catalog and proficiency scale.')"
    >
        @if ($canManage && $tab === 'skills')
            <x-slot:actions>
                <x-ui.button type="button" variant="primary" wire:click="startSkill">
                    <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                    {{ __('New skill') }}
                </x-ui.button>
            </x-slot:actions>
        @elseif ($canManage && $tab === 'categories')
            <x-slot:actions>
                <x-ui.button type="button" variant="primary" wire:click="startCategory">
                    <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                    {{ __('New category') }}
                </x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    @endif

    @if ($companies === [])
        <x-ui.alert variant="info">
            {{ __('No company workforce data is synchronized yet. Connect a People provider to start the skill catalog.') }}
        </x-ui.alert>
    @else
        @if (count($companies) > 1)
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="text-muted">{{ __('Company') }}</span>
                @foreach ($companies as $entityId => $name)
                    <x-ui.button
                        type="button"
                        size="sm"
                        wire:click="selectCompany({{ $entityId }})"
                        :variant="$companyEntityId === $entityId ? 'primary' : 'secondary'"
                    >{{ $name }}</x-ui.button>
                @endforeach
            </div>
        @endif

        <nav class="flex gap-4 border-b border-edge text-sm" role="tablist">
            @foreach (['skills' => __('Skills'), 'categories' => __('Categories'), 'scale' => __('Proficiency Scale')] as $key => $label)
                <button
                    type="button"
                    role="tab"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    wire:click="$set('tab', '{{ $key }}')"
                    class="pb-2 {{ $tab === $key ? 'border-b-2 border-accent font-medium text-ink' : 'text-muted' }}"
                >{{ $label }}</button>
            @endforeach
        </nav>

        @if ($tab === 'skills')
            @error('skills')
                <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
            @enderror

            <x-ui.card>
                <x-ui.filter-bar class="mb-3">
                    <x-slot:search>
                        <x-ui.search-input
                            id="skills-search"
                            wire:key="skills-search"
                            wire:model.live.debounce.300ms="search"
                            :placeholder="__('Search code or name…')"
                            :aria-label="__('Search skills')"
                        />
                    </x-slot:search>
                    <x-ui.select id="skills-category-filter" wire:model.live="filterCategoryId" :aria-label="__('Filter skills by category')">
                        <option value="">{{ __('All categories') }}</option>
                        @foreach ($categoryOptions as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <div class="flex min-h-11 flex-wrap items-center gap-x-5 gap-y-2 px-1">
                        <x-ui.checkbox id="skills-critical-filter" wire:model.live="criticalOnly" :label="__('Critical only')" />
                        <x-ui.checkbox id="skills-inactive-filter" wire:model.live="includeInactive" :label="__('Include inactive')" />
                    </div>
                </x-ui.filter-bar>

            <x-ui.table container="flush" :caption="__('Skills catalog')">
                <x-slot:head>
                    <tr>
                        <x-ui.sortable-th column="code" :sort-by="$skillSortBy" :sort-dir="$skillSortDir" action="sortSkills('code')" :label="__('Skill ID')" />
                        <x-ui.sortable-th column="name" :sort-by="$skillSortBy" :sort-dir="$skillSortDir" action="sortSkills('name')" :label="__('Skill')" />
                        <x-ui.th>{{ __('Category') }}</x-ui.th>
                        <x-ui.sortable-th column="scope" :sort-by="$skillSortBy" :sort-dir="$skillSortDir" action="sortSkills('scope')" :label="__('Scope')" />
                        <x-ui.th>{{ __('Critical') }}</x-ui.th>
                        <x-ui.sortable-th column="method" :sort-by="$skillSortBy" :sort-dir="$skillSortDir" action="sortSkills('method')" :label="__('Method')" />
                        <x-ui.sortable-th column="cadence" :sort-by="$skillSortBy" :sort-dir="$skillSortDir" action="sortSkills('cadence')" :label="__('Cadence')" numeric />
                        @if ($canManage)
                            <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
                        @endif
                    </tr>
                </x-slot:head>
                <x-slot:body>
                    @forelse ($skills as $skill)
                        <tr wire:key="skill-{{ $skill->id }}" @class(['opacity-60' => $skill->active === false])>
                            <td class="px-table-cell-x py-table-cell-y font-mono text-sm">{{ $skill->code }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                                <span class="font-medium">{{ $skill->name }}</span>
                                <span class="block text-muted">{{ $skill->definition }}</span>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-sm">{{ $skill->category?->name }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm">{{ ucfirst($skill->scope->value) }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm">
                                @if ($skill->isCritical())
                                    <x-ui.badge variant="warning">{{ ucfirst($skill->critical_classification->value) }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-sm">{{ $skill->default_assessment_method->label() }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums">
                                {{ $skill->default_reassessment_months !== null ? __(':months mo', ['months' => $skill->default_reassessment_months]) : '—' }}
                            </td>
                            @if ($canManage)
                                <td class="px-table-cell-x py-table-cell-y text-sm">
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="startSkill({{ $skill->id }})">{{ __('Edit') }}</x-ui.button>
                                    <x-ui.button type="button" :variant="$skill->active ? 'danger-ghost' : 'ghost'" size="sm" wire:click="toggleSkillActive({{ $skill->id }})">
                                        {{ $skill->active ? __('Deactivate') : __('Reactivate') }}
                                    </x-ui.button>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-table-cell-x py-8 text-center text-sm text-muted">
                                @if ($hasSkills)
                                    {{ __('No skills match your search and filters.') }}
                                @else
                                    {{ __('No skills have been added yet.') }}
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </x-slot:body>
            </x-ui.table>
                <div class="mt-3">
                    <x-ui.pagination :paginator="$skills" :per-page-options="$this->perPageOptions()" :per-page="$perPage" id="skills-per-page" />
                </div>
            </x-ui.card>
        @elseif ($tab === 'categories')
            @error('categoryForm')
                <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
            @enderror

            <x-ui.card>
            <x-ui.table container="flush" :caption="__('Skill categories')">
                <x-slot:head>
                    <tr>
                        <x-ui.sortable-th column="code" :sort-by="$categorySortBy" :sort-dir="$categorySortDir" action="sortCategories('code')" :label="__('Code')" />
                        <x-ui.sortable-th column="name" :sort-by="$categorySortBy" :sort-dir="$categorySortDir" action="sortCategories('name')" :label="__('Category')" />
                        <x-ui.sortable-th column="skills" :sort-by="$categorySortBy" :sort-dir="$categorySortDir" action="sortCategories('skills')" :label="__('Skills')" numeric />
                        <x-ui.sortable-th column="status" :sort-by="$categorySortBy" :sort-dir="$categorySortDir" action="sortCategories('status')" :label="__('Status')" />
                        @if ($canManage)
                            <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
                        @endif
                    </tr>
                </x-slot:head>
                <x-slot:body>
                    @forelse ($categories as $category)
                        <tr wire:key="category-{{ $category->id }}">
                            <td class="px-table-cell-x py-table-cell-y font-mono text-sm">{{ $category->code }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">{{ $category->name }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums">{{ $category->skills_count }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm">
                                <x-ui.badge :variant="$category->active ? 'success' : 'neutral'">
                                    {{ $category->active ? __('Active') : __('Inactive') }}
                                </x-ui.badge>
                            </td>
                            @if ($canManage)
                                <td class="px-table-cell-x py-table-cell-y text-sm">
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="startCategory({{ $category->id }})">{{ __('Edit') }}</x-ui.button>
                                    <x-ui.button type="button" :variant="$category->active ? 'danger-ghost' : 'ghost'" size="sm" wire:click="toggleCategoryActive({{ $category->id }})">
                                        {{ $category->active ? __('Deactivate') : __('Reactivate') }}
                                    </x-ui.button>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">
                                {{ __('No skill categories have been added yet.') }}
                            </td>
                        </tr>
                    @endforelse
                </x-slot:body>
            </x-ui.table>
                <div class="mt-3">
                    <x-ui.pagination :paginator="$categories" :per-page-options="$this->perPageOptions()" :per-page="$perPage" id="categories-per-page" />
                </div>
            </x-ui.card>
        @else
            @unless ($hasPublishedScale)
                <x-ui.alert variant="info">
                    <div class="space-y-3">
                        <div class="space-y-1">
                            <p class="font-medium text-ink">{{ __('A published proficiency scale is required before assessments can be submitted.') }}</p>
                            <p>{{ __('The standard scale defines six evidence-based levels from 0 (Not trained) to 5 (Expert / Authoriser).') }}</p>
                        </div>

                        @if ($canManage)
                            @if ($hasScaleDraft)
                                <p>{{ __('A scale draft is ready below. Review its meaning and publish it to open assessment submission.') }}</p>
                            @else
                                <div class="space-y-2">
                                    <x-ui.button type="button" wire:click="installStarterPack" wire:loading.attr="disabled" wire:target="installStarterPack">
                                        <span wire:loading.remove wire:target="installStarterPack">{{ __('Publish the standard 0–5 proficiency scale') }}</span>
                                        <span wire:loading wire:target="installStarterPack">{{ __('Publishing standard scale…') }}</span>
                                    </x-ui.button>
                                    <p class="text-sm">{{ __('Adds any missing standard skill categories without changing existing categories.') }}</p>
                                </div>
                            @endif
                        @else
                            <p>{{ __('Ask a People HR administrator to publish the standard proficiency scale before assessments begin.') }}</p>
                        @endif

                        <p class="text-sm">{{ __('Level 0 is an assessed result; an employee with no score remains not yet assessed. Publishing the scale does not assess employees or grant qualifications.') }}</p>
                    </div>
                </x-ui.alert>
            @endunless

            @foreach ($scales as $scale)
                <section wire:key="scale-{{ $scale->id }}" class="space-y-2 rounded border border-edge p-4">
                    <header class="flex flex-wrap items-center gap-2">
                        <h2 class="text-sm font-medium text-ink">{{ $scale->name }}</h2>
                        <span class="font-mono text-sm text-muted">{{ $scale->code }} · v{{ $scale->version }}</span>
                        <x-ui.badge :variant="match ($scale->status->value) { 'published' => 'success', 'draft' => 'info', default => 'neutral' }">
                            {{ ucfirst($scale->status->value) }}
                        </x-ui.badge>
                        @if ($canManage)
                            @if ($scale->status->value === 'draft')
                                <x-ui.button size="sm" wire:click="publishScale({{ $scale->id }})">{{ __('Publish') }}</x-ui.button>
                            @elseif ($scale->status->value === 'published')
                                <x-ui.button size="sm" variant="ghost" wire:click="draftNewScaleVersion({{ $scale->id }})">
                                    {{ __('Draft new version') }}
                                </x-ui.button>
                            @endif
                        @endif
                    </header>
                    <x-ui.table :caption="__('Levels of :scale', ['scale' => $scale->name])">
                        <x-slot:head>
                            <tr>
                                <x-ui.th>{{ __('Level') }}</x-ui.th>
                                <x-ui.th>{{ __('Name') }}</x-ui.th>
                                <x-ui.th>{{ __('Behavioural anchor') }}</x-ui.th>
                                <x-ui.th>{{ __('Authority') }}</x-ui.th>
                            </tr>
                        </x-slot:head>
                        <x-slot:body>
                            @foreach ($scale->levels as $level)
                                <tr wire:key="level-{{ $level->id }}">
                                    <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums">{{ $level->level }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">{{ $level->name }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-sm">{{ $level->anchor }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-sm">{{ $level->authority }}</td>
                                </tr>
                            @endforeach
                        </x-slot:body>
                    </x-ui.table>
                </section>
            @endforeach
        @endif
    @endif
</div>
