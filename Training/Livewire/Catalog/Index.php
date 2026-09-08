<?php

namespace App\Domains\People\Training\Livewire\Catalog;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\SelectsPerPage;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Livewire\Catalog\Concerns\ResolvesCatalogCompany;
use App\Domains\People\Training\Models\TrainingCourse;
use App\Domains\People\Training\Services\TrainingAudience;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Company-scoped course list; create and revise use dedicated form pages. */
final class Index extends Component
{
    use ResetsPaginationOnSearch;
    use ResolvesCatalogCompany;
    use SelectsPerPage;
    use TogglesSort;
    use WithPagination;

    #[Url(as: 'company', except: null)]
    public ?int $companyEntityId = null;

    #[Url(except: '')]
    public string $search = '';

    /** active|inactive|'' */
    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $delivery = '';

    #[Url(except: 'code')]
    public string $sortBy = 'code';

    #[Url(except: 'asc')]
    public string $sortDir = 'asc';

    private const SORTABLE = [
        'code' => 'code',
        'title' => 'title',
        'delivery_mode' => 'delivery_mode',
        'active' => 'active',
    ];

    public function mount(TrainingAudience $audience): void
    {
        $preferred = $this->companyEntityId;
        $this->resolveInitialCompany($audience, $preferred);
    }

    public function selectCompany(int $companyEntityId, TrainingAudience $audience): void
    {
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies($audience)), 404);

        $this->companyEntityId = $companyEntityId;
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: [
                'code' => 'asc',
                'title' => 'asc',
                'delivery_mode' => 'asc',
                'active' => 'desc',
            ],
        );
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedDelivery(): void
    {
        $this->resetPage();
    }

    public function toggleCourseActive(int $courseId, TrainingAudience $audience, TrainingCatalogStore $store): void
    {
        $company = $this->managedCompany($audience);
        $course = TrainingCourse::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $company)
            ->find($courseId);
        abort_if($course === null, 404);

        $course->active
            ? $store->deactivateCourse($company, $courseId)
            : $store->reactivateCourse($company, $courseId);
    }

    public function render(TrainingAudience $audience): View
    {
        $companies = $this->allowedCompanies($audience);
        $company = $this->viewedCompany($audience);
        $canManage = $company !== null && $audience->canManage(Auth::user(), $company);
        $hasActiveSkills = $company !== null && $canManage && $this->companyHasActiveSkills($company);

        return view('people::livewire.training.catalog.index', [
            'companies' => $companies,
            'courses' => $company === null ? null : $this->courses($company),
            'canManage' => $canManage,
            'hasActiveSkills' => $hasActiveSkills,
            'canManageSkills' => $canManage && $company !== null
                && app(SkillAudience::class)->mayManageCatalog(Auth::user(), $company),
            'deliveryModes' => DeliveryMode::cases(),
            'listQuery' => $this->listQuery(),
        ]);
    }

    /** @return array<string, int|string> */
    private function listQuery(): array
    {
        return array_filter([
            'company' => $this->companyEntityId,
            'search' => $this->search,
            'status' => $this->status,
            'delivery' => $this->delivery,
            'sortBy' => $this->sortBy !== 'code' ? $this->sortBy : null,
            'sortDir' => $this->sortDir !== 'asc' ? $this->sortDir : null,
            'page' => $this->getPage() > 1 ? $this->getPage() : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function companyHasActiveSkills(int $companyEntityId): bool
    {
        return Skill::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
            ->where('active', true)
            ->exists();
    }

    private function courses(int $companyEntityId): LengthAwarePaginator
    {
        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'code';
        $sortDir = $this->sortDir === 'desc' ? 'desc' : 'asc';
        $search = trim($this->search);
        $tenantId = app(TenantContext::class)->requireTenantId();

        return TrainingCourse::query()
            ->forCompany($tenantId, $companyEntityId)
            ->when($search !== '', function (Builder $query) use ($search, $tenantId, $companyEntityId): void {
                // RequireCompanyScope refuses orWhere anywhere in the tree, so
                // code/title search is two company-pinned lookups merged by id.
                $ids = TrainingCourse::query()
                    ->forCompany($tenantId, $companyEntityId)
                    ->where('code', 'like', '%'.$search.'%')
                    ->pluck('id')
                    ->merge(
                        TrainingCourse::query()
                            ->forCompany($tenantId, $companyEntityId)
                            ->where('title', 'like', '%'.$search.'%')
                            ->pluck('id'),
                    )
                    ->unique()
                    ->values()
                    ->all();

                $query->whereIn('id', $ids === [] ? [0] : $ids);
            })
            ->when($this->status === 'active', fn (Builder $query): Builder => $query->where('active', true))
            ->when($this->status === 'inactive', fn (Builder $query): Builder => $query->where('active', false))
            ->when(
                $this->delivery !== '' && DeliveryMode::tryFrom($this->delivery) !== null,
                fn (Builder $query): Builder => $query->where('delivery_mode', $this->delivery),
            )
            ->orderBy($sortColumn, $sortDir)
            ->orderBy('id')
            ->paginate($this->clampedPerPage());
    }
}
