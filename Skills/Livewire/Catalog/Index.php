<?php

namespace App\Domains\People\Skills\Livewire\Catalog;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Foundation\Livewire\Concerns\SelectsPerPage;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\CriticalClassification;
use App\Domains\People\Skills\Enums\ProficiencyScaleStatus;
use App\Domains\People\Skills\Enums\SkillScope;
use App\Domains\People\Skills\Exceptions\InvalidSkillCatalogException;
use App\Domains\People\Skills\Models\ProficiencyScale;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillCategory;
use App\Domains\People\Skills\Services\ProficiencyScaleStore;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\SkillCatalogDefaults;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Catalog administration for HR (manage capability) with a read-only view for
 * HODs and evaluators (view capability). Every query and store call is bound
 * to one company workforce entity the acting user may act for, resolved by
 * the connector's CompanyAttribution service — companyEntityId is a client-writable Livewire property,
 * so it is re-checked on every action, never trusted. With no accessible
 * company the page states that honestly instead of pretending a catalog
 * exists.
 */
class Index extends Component
{
    use SelectsPerPage;
    use TogglesSort;
    use WithPagination;

    #[Url]
    public string $tab = 'skills';

    #[Url(as: 'view', history: true)]
    public string $catalogView = 'list';

    #[Url(as: 'company')]
    public ?int $companyEntityId = null;

    public string $search = '';

    public ?int $filterCategoryId = null;

    public bool $criticalOnly = false;

    public bool $includeInactive = false;

    public string $skillSortBy = 'code';

    public string $skillSortDir = 'asc';

    public string $categorySortBy = 'name';

    public string $categorySortDir = 'asc';

    // Skill form state (null id = create).
    #[Url(as: 'skill', history: true)]
    public ?int $editingSkillId = null;

    /** @var array<string, mixed> */
    public array $skillForm = [];

    #[Url(as: 'category', history: true)]
    public ?int $editingCategoryId = null;

    /** @var array<string, mixed> */
    public array $categoryForm = [];

    /** @var array<int, string>|null */
    private ?array $allowedCompanies = null;

    public function mount(): void
    {
        $this->authorizeView();
        if (! in_array($this->tab, ['skills', 'categories', 'scale'], true)) {
            $this->tab = 'skills';
        }

        $companies = $this->allowedCompanies();
        if ($this->companyEntityId === null) {
            $this->companyEntityId = count($companies) > 0 ? (int) array_key_first($companies) : null;
        } elseif (! array_key_exists($this->companyEntityId, $companies)) {
            $this->companyEntityId = null;
        }

        if ($this->catalogView === 'skill-form') {
            $this->startSkill($this->editingSkillId);
        } elseif ($this->catalogView === 'category-form') {
            $this->startCategory($this->editingCategoryId);
        } else {
            $this->catalogView = 'list';
        }
    }

    public function selectCompany(int $companyEntityId): void
    {
        $this->authorizeView();
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies()), 404);

        $this->companyEntityId = $companyEntityId;
        $this->reset('editingSkillId', 'skillForm', 'editingCategoryId', 'categoryForm', 'filterCategoryId');
        $this->catalogView = 'list';
        $this->resetPage();
        $this->resetPage('categoriesPage');
    }

    public function installStarterPack(SkillCatalogDefaults $defaults): void
    {
        $companyEntityId = $this->authorizedCompanyForManage();

        $defaults->install($companyEntityId);
        session()->flash('status', __('The standard proficiency scale is published. Existing categories were preserved and missing standard categories were added.'));
    }

    public function startSkill(?int $skillId = null): void
    {
        $companyEntityId = $this->authorizedCompanyForManage();

        $skill = $skillId === null ? null : $this->skillQuery($companyEntityId)->findOrFail($skillId);
        $this->editingSkillId = $skill?->id;
        $this->skillForm = [
            'code' => $skill->code ?? '',
            'name' => $skill->name ?? '',
            'definition' => $skill->definition ?? '',
            'category_id' => $skill->category_id ?? $this->categories($companyEntityId)->first()?->id,
            'scope' => ($skill->scope ?? SkillScope::Shared)->value,
            'critical_classification' => $skill?->critical_classification?->value,
            'evidence_guide' => $skill->evidence_guide ?? '',
            'default_assessment_method' => ($skill->default_assessment_method ?? AssessmentMethod::DirectObservation)->value,
            'default_reassessment_months' => $skill->default_reassessment_months ?? null,
        ];
        $this->tab = 'skills';
        $this->catalogView = 'skill-form';
    }

    public function cancelForm(): void
    {
        $this->reset('editingSkillId', 'skillForm', 'editingCategoryId', 'categoryForm');
        $this->catalogView = 'list';
    }

    public function saveSkill(SkillCatalogStore $store): void
    {
        $companyEntityId = $this->authorizedCompanyForManage();

        $months = $this->skillForm['default_reassessment_months'] ?? null;
        $classification = $this->skillForm['critical_classification'] ?? null;

        try {
            $draft = new SkillDraft(
                code: trim((string) ($this->skillForm['code'] ?? '')),
                name: trim((string) ($this->skillForm['name'] ?? '')),
                definition: trim((string) ($this->skillForm['definition'] ?? '')),
                categoryId: (int) ($this->skillForm['category_id'] ?? 0),
                scope: SkillScope::from((string) ($this->skillForm['scope'] ?? SkillScope::Shared->value)),
                criticalClassification: $classification === null || $classification === ''
                    ? null
                    : CriticalClassification::from((string) $classification),
                evidenceGuide: trim((string) ($this->skillForm['evidence_guide'] ?? '')) ?: null,
                defaultAssessmentMethod: AssessmentMethod::from(
                    (string) ($this->skillForm['default_assessment_method'] ?? AssessmentMethod::DirectObservation->value),
                ),
                defaultReassessmentMonths: $months === null || $months === '' ? null : (int) $months,
            );

            if ($this->editingSkillId === null) {
                $store->defineSkill($companyEntityId, $draft);
            } else {
                $store->reviseSkill($companyEntityId, $this->editingSkillId, $draft);
            }
        } catch (InvalidSkillCatalogException $exception) {
            $this->addError('skillForm', $exception->getMessage());

            return;
        }

        session()->flash('status', $this->editingSkillId === null
            ? __('Skill created successfully.')
            : __('Skill updated successfully.'));
        $this->cancelForm();
    }

    public function toggleSkillActive(int $skillId, SkillCatalogStore $store): void
    {
        $companyEntityId = $this->authorizedCompanyForManage();

        $skill = $this->skillQuery($companyEntityId)->find($skillId);
        abort_if($skill === null, 404);

        try {
            $skill->active
                ? $store->deactivateSkill($companyEntityId, $skillId)
                : $store->reactivateSkill($companyEntityId, $skillId);
        } catch (InvalidSkillCatalogException $exception) {
            $this->addError('skills', $exception->getMessage());
        }
    }

    public function saveCategory(SkillCatalogStore $store): void
    {
        $companyEntityId = $this->authorizedCompanyForManage();
        $code = trim((string) ($this->categoryForm['code'] ?? ''));
        $name = trim((string) ($this->categoryForm['name'] ?? ''));

        try {
            if ($this->editingCategoryId === null) {
                $store->defineCategory($companyEntityId, $code, $name);
            } else {
                $store->editCategory($companyEntityId, $this->editingCategoryId, $name);
            }
        } catch (InvalidSkillCatalogException $exception) {
            $this->addError('categoryForm', $exception->getMessage());

            return;
        }

        session()->flash('status', $this->editingCategoryId === null
            ? __('Category created successfully.')
            : __('Category updated successfully.'));
        $this->cancelForm();
    }

    public function startCategory(?int $categoryId = null): void
    {
        $companyEntityId = $this->authorizedCompanyForManage();
        $category = $categoryId === null ? null : $this->categories($companyEntityId)->firstWhere('id', $categoryId);
        abort_if($categoryId !== null && $category === null, 404);

        $this->editingCategoryId = $category?->id;
        $this->categoryForm = [
            'code' => $category->code ?? '',
            'name' => $category->name ?? '',
        ];
        $this->tab = 'categories';
        $this->catalogView = 'category-form';
    }

    public function toggleCategoryActive(int $categoryId, SkillCatalogStore $store): void
    {
        $companyEntityId = $this->authorizedCompanyForManage();

        $category = $this->categories($companyEntityId)->firstWhere('id', $categoryId);
        abort_if($category === null, 404);

        try {
            $category->active
                ? $store->deactivateCategory($companyEntityId, $categoryId)
                : $store->reactivateCategory($companyEntityId, $categoryId);
        } catch (InvalidSkillCatalogException $exception) {
            $this->addError('categoryForm', $exception->getMessage());
        }
    }

    public function publishScale(int $scaleId, ProficiencyScaleStore $store): void
    {
        $companyEntityId = $this->authorizedCompanyForManage();
        $store->publish($companyEntityId, $scaleId);
    }

    public function draftNewScaleVersion(int $scaleId, ProficiencyScaleStore $store): void
    {
        $companyEntityId = $this->authorizedCompanyForManage();
        $store->newDraftFrom($companyEntityId, $scaleId);
    }

    public function render(): View
    {
        $companies = $this->allowedCompanies();
        $companyEntityId = $this->companyEntityId !== null && array_key_exists($this->companyEntityId, $companies)
            ? $this->companyEntityId
            : null;
        $scales = $companyEntityId === null ? collect() : $this->scales($companyEntityId);
        $categories = $companyEntityId === null ? collect() : $this->categories($companyEntityId);

        if ($this->catalogView === 'skill-form') {
            $companyEntityId = $this->authorizedCompanyForManage();

            return view('people::livewire.catalog.skill-form', [
                'categories' => $categories,
                'scopeOptions' => SkillScope::cases(),
                'methodOptions' => AssessmentMethod::cases(),
                'classificationOptions' => CriticalClassification::cases(),
                'skill' => $this->editingSkillId === null || $companyEntityId === null
                    ? null
                    : $this->skillQuery($companyEntityId)->findOrFail($this->editingSkillId),
            ]);
        }

        if ($this->catalogView === 'category-form') {
            $companyEntityId = $this->authorizedCompanyForManage();
            $category = $this->editingCategoryId === null
                ? null
                : $categories->firstWhere('id', $this->editingCategoryId);
            abort_if($this->editingCategoryId !== null && $category === null, 404);

            return view('people::livewire.catalog.category-form', [
                'category' => $category,
            ]);
        }

        return view('people::livewire.catalog.index', [
            'companies' => $companies,
            'categoryOptions' => $categories,
            'categories' => $companyEntityId === null ? $this->emptyPaginator('categoriesPage') : $this->paginatedCategories($companyEntityId),
            'skills' => $companyEntityId === null ? $this->emptyPaginator() : $this->filteredSkills($companyEntityId),
            'hasSkills' => $companyEntityId !== null && $this->skillQuery($companyEntityId)->exists(),
            'scales' => $scales,
            'hasPublishedScale' => $scales->contains(
                fn (ProficiencyScale $scale): bool => $scale->status === ProficiencyScaleStatus::Published,
            ),
            'hasScaleDraft' => $scales->contains(
                fn (ProficiencyScale $scale): bool => $scale->status === ProficiencyScaleStatus::Draft,
            ),
            'canManage' => $this->canManage(),
        ]);
    }

    /**
     * Resolved once per request: the picker, every action guard, and the
     * render pass all ask the same question of the same actor.
     *
     * @return array<int, string> company entity id => display name
     */
    private function allowedCompanies(): array
    {
        return $this->allowedCompanies ??= app(SkillAudience::class)->allowedCompanies(
            Auth::user(),
            'people.skill.catalog.view',
        );
    }

    /**
     * The single authorization funnel for every mutating action: manage
     * capability plus proof the actor may act for the selected company.
     */
    private function authorizedCompanyForManage(): int
    {
        abort_if($this->companyEntityId === null, 404);
        abort_unless(array_key_exists($this->companyEntityId, $this->allowedCompanies()), 404);

        try {
            app(SkillAudience::class)->authorizeCatalogManage(Auth::user(), $this->companyEntityId);
        } catch (AuthorizationDeniedException) {
            abort(403);
        }

        return $this->companyEntityId;
    }

    private function categories(int $companyEntityId)
    {
        return SkillCategory::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
            ->orderBy('name')
            ->get();
    }

    /**
     * Skill::category() carries no escape, so the eager load has to pin the
     * company itself — which costs nothing here, because this method was
     * handed the company it is allowed to act for. A category belonging to
     * anyone else simply does not load, and the view renders a blank cell
     * rather than another company's category name.
     */
    private function skillQuery(int $companyEntityId): Builder
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        return Skill::query()
            ->forCompany($tenantId, $companyEntityId)
            ->with(['category' => fn ($query) => $query->forCompany($tenantId, $companyEntityId)]);
    }

    private function filteredSkills(int $companyEntityId)
    {
        $table = (new Skill)->getTable();
        $sortColumn = [
            'code' => $table.'.code',
            'name' => $table.'.name',
            'scope' => $table.'.scope',
            'method' => $table.'.default_assessment_method',
            'cadence' => $table.'.default_reassessment_months',
        ][$this->skillSortBy] ?? $table.'.code';

        return $this->skillQuery($companyEntityId)
            ->when(! $this->includeInactive, fn (Builder $query): Builder => $query->where($table.'.active', true))
            ->when($this->filterCategoryId !== null, fn (Builder $query): Builder => $query->where($table.'.category_id', $this->filterCategoryId))
            ->when($this->criticalOnly, fn (Builder $query): Builder => $query->whereNotNull($table.'.critical_classification'))
            ->when(trim($this->search) !== '', function (Builder $query) use ($table): void {
                $search = '%'.mb_strtolower(trim($this->search)).'%';
                $query->whereRaw('(lower('.$table.'.code) like ? or lower('.$table.'.name) like ?)', [$search, $search]);
            })
            ->orderBy($sortColumn, $this->skillSortDir)
            ->orderBy($table.'.id')
            ->paginate($this->clampedPerPage());
    }

    private function paginatedCategories(int $companyEntityId)
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $table = (new SkillCategory)->getTable();
        $skillTable = (new Skill)->getTable();
        $skillCount = Skill::query()
            ->forCompany($tenantId, $companyEntityId)
            ->selectRaw('count(*)')
            ->whereColumn($skillTable.'.category_id', $table.'.id');
        $sortColumn = [
            'code' => $table.'.code',
            'name' => $table.'.name',
            'skills' => 'skills_count',
            'status' => $table.'.active',
        ][$this->categorySortBy] ?? $table.'.name';

        return SkillCategory::query()
            ->forCompany($tenantId, $companyEntityId)
            ->select($table.'.*')
            ->selectSub($skillCount, 'skills_count')
            ->orderBy($sortColumn, $this->categorySortDir)
            ->orderBy($table.'.id')
            ->paginate($this->clampedPerPage(), pageName: 'categoriesPage');
    }

    private function emptyPaginator(string $pageName = 'page'): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, $this->clampedPerPage(), 1, [
            'path' => request()->url(),
            'pageName' => $pageName,
        ]);
    }

    public function sortSkills(string $column): void
    {
        $this->toggleSort($column, ['code', 'name', 'scope', 'method', 'cadence'], [
            'code' => 'asc', 'name' => 'asc', 'scope' => 'asc', 'method' => 'asc', 'cadence' => 'asc',
        ], 'skillSortBy', 'skillSortDir');
    }

    public function sortCategories(string $column): void
    {
        $this->toggleSort($column, ['code', 'name', 'skills', 'status'], [
            'code' => 'asc', 'name' => 'asc', 'skills' => 'desc', 'status' => 'desc',
        ], 'categorySortBy', 'categorySortDir', false);
        $this->resetPage('categoriesPage');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterCategoryId(): void
    {
        $this->resetPage();
    }

    public function updatedCriticalOnly(): void
    {
        $this->resetPage();
    }

    public function updatedIncludeInactive(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(mixed $value): void
    {
        $this->perPage = $this->clampedPerPage(is_numeric($value) ? (int) $value : null);
        $this->resetPage();
        $this->resetPage('categoriesPage');
    }

    private function scales(int $companyEntityId)
    {
        return ProficiencyScale::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
            ->with('levels')
            ->orderBy('code')
            ->orderByDesc('version')
            ->get();
    }

    private function canManage(): bool
    {
        return $this->companyEntityId !== null
            && app(SkillAudience::class)->mayManageCatalog(Auth::user(), $this->companyEntityId);
    }

    private function authorizeView(): void
    {
        try {
            app(SkillAudience::class)->authorizeAudience(
                Auth::user(),
                'people.skill.catalog.view',
            );
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }
}
