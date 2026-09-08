<?php

namespace App\Domains\People\Skills\Livewire\Register;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Skills\Enums\AssessmentStatus;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\WorkforceSubjects;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HR-wide register of current released skill levels (0014-b).
 *
 * One row per employee and skill: the latest non-expired finalized
 * assessment wins, exactly like the 0006-a history page, so a lapsed newer
 * score never displaces an older valid one. An employee whose scores for a
 * skill all lapsed shows expired with no current level.
 *
 * Read-only. The company is one of the HR user's allowed companies and every
 * query is pinned to it; the filters narrow, never widen. The page never
 * takes an employee id input.
 */
final class Index extends Component
{
    public const VIEW_CAPABILITY = 'people.skill.register.view';

    public const EXPORT_EVENT = 'people.skill.register.exported';

    public ?int $companyEntityId = null;

    /** Organisation-unit stable id, or empty for all departments. */
    public string $department = '';

    /** Skill id, or empty for all skills. */
    public string $skill = '';

    /** At-or-above current level, or empty for every level. */
    public string $level = '';

    /** Expiring within N days (inclusive), or empty for every validity. */
    public string $expiringWithinDays = '';

    public bool $expiredOnly = false;

    /** employee|skill|valid_until. */
    public string $sort = 'employee';

    /** asc|desc. */
    public string $direction = 'asc';

    /** @var array<int, string>|null */
    private ?array $allowedCompanies = null;

    public function mount(): void
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $this->companyEntityId = $companies === [] ? null : (int) array_key_first($companies);
    }

    public function selectCompany(int $companyEntityId): void
    {
        $this->authorizeView();
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies()), 404);
        $this->companyEntityId = $companyEntityId;
        $this->reset('department', 'skill', 'level', 'expiringWithinDays', 'expiredOnly');
    }

    public function render(): View
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $companyEntityId = $this->companyEntityId === null ? null : $this->requireCompany();

        return view('people::livewire.register.index', [
            'companies' => $companies,
            'departments' => $companyEntityId === null ? [] : $this->departmentNames($companyEntityId),
            'skills' => $companyEntityId === null ? [] : $this->skillNames($companyEntityId),
            'rows' => $companyEntityId === null ? collect() : $this->rows($companyEntityId),
        ]);
    }

    /** The filtered rows as CSV, and one audit action saying who exported what. */
    public function export(): StreamedResponse
    {
        $companyEntityId = $this->requireCompany();
        $rows = $this->rows($companyEntityId);
        $filename = sprintf('skill-register-%d-%s.csv', $companyEntityId, now()->toDateString());

        app(SemanticActionRecorder::class)->record(
            event: self::EXPORT_EVENT,
            summary: __('Exported :count skill register rows to CSV', ['count' => $rows->count()]),
            source: __('Skills'),
            subject: ['name' => 'skill-register', 'identifier' => $filename],
            surface: 'people.skill.register',
            uiElement: 'export',
            context: [
                'company_entity_id' => $companyEntityId,
                'department' => $this->department === '' ? null : $this->department,
                'skill' => $this->skill === '' ? null : $this->skill,
                'level' => $this->level === '' ? null : $this->level,
                'expiring_within_days' => $this->expiringWithinDays === '' ? null : $this->expiringWithinDays,
                'expired_only' => $this->expiredOnly,
                'rows' => $rows->count(),
                'skill_ids' => $rows->pluck('skill_id')->unique()->values()->all(),
            ],
        );

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['employee', 'department', 'skill', 'level', 'assessed_on', 'valid_until', 'expired']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['employee'], $row['department'], $row['skill'],
                    $row['level'] === null ? '' : (string) $row['level'],
                    $row['assessed_on'], $row['valid_until'] ?? '',
                    $row['expired'] ? 'yes' : 'no',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return Collection<int, array{employee_id: int, employee: string, department_id: string|null, department: string, skill_id: int, skill: string, level: int|null, assessed_on: string, valid_until: string|null, expired: bool}>
     */
    private function rows(int $companyEntityId): Collection
    {
        $tenantId = $this->tenantId();
        $today = CarbonImmutable::today();

        $assessments = SkillAssessment::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('status', AssessmentStatus::Finalized->value)
            ->orderByDesc('assessed_at')
            ->orderByDesc('id')
            ->get();

        $names = $assessments->isEmpty() ? collect() : Employee::query()
            ->whereIn('id', $assessments->pluck('employee_entity_id')->unique()->all())
            ->pluck('full_name', 'id');
        $skills = $assessments->isEmpty() ? collect() : Skill::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereIn('id', $assessments->pluck('skill_id')->unique()->all())
            ->pluck('name', 'id');
        $departments = $this->departmentNames($companyEntityId);
        $employeeDepartments = $this->employeeDepartments($companyEntityId);

        $rows = [];
        foreach ($assessments->groupBy(fn (SkillAssessment $row): string => $row->employee_entity_id.':'.$row->skill_id) as $group) {
            /** @var SkillAssessment|null $current */
            $current = $group->first(fn (SkillAssessment $row): bool => ! self::isExpired($row->valid_until, $today));
            $source = $current ?? $group->first();
            $employeeId = (int) $source->employee_entity_id;
            $skillId = (int) $source->skill_id;
            $unitId = $employeeDepartments[$employeeId] ?? null;
            $rows[] = [
                'employee_id' => $employeeId,
                'employee' => (string) ($names[$employeeId] ?? __('Unknown employee')),
                'department_id' => $unitId,
                'department' => $unitId === null ? __('No department') : ($departments[$unitId] ?? $unitId),
                'skill_id' => $skillId,
                'skill' => (string) ($skills[$skillId] ?? __('Unknown skill')),
                'level' => $current === null ? null : (int) $current->assessed_level,
                'assessed_on' => (string) $source->assessed_at?->toDateString(),
                'valid_until' => $source->valid_until === null ? null : (string) $source->valid_until->toDateString(),
                'expired' => $current === null,
            ];
        }

        return collect($rows)
            ->when($this->department !== '' && array_key_exists($this->department, $departments), fn (Collection $rows): Collection => $rows->where('department_id', $this->department))
            ->when($this->skill !== '' && ctype_digit($this->skill), fn (Collection $rows): Collection => $rows->where('skill_id', (int) $this->skill))
            ->when($this->level !== '' && ctype_digit($this->level), fn (Collection $rows): Collection => $rows->where('level', '>=', (int) $this->level))
            ->when($this->expiredOnly, fn (Collection $rows): Collection => $rows->where('expired', true))
            ->when($this->expiringWithinDays !== '' && ctype_digit($this->expiringWithinDays), fn (Collection $rows): Collection => $rows->filter(
                fn (array $row): bool => $row['valid_until'] !== null
                    && $row['valid_until'] >= $today->toDateString()
                    && $row['valid_until'] <= $today->addDays((int) $this->expiringWithinDays)->toDateString()
            ))
            ->sortBy([
                fn (array $a, array $b): int => match ($this->sort) {
                    'skill' => [$a['skill'], $a['employee']] <=> [$b['skill'], $b['employee']],
                    'valid_until' => [($a['valid_until'] ?? '~~~~'), $a['employee']] <=> [($b['valid_until'] ?? '~~~~'), $b['employee']],
                    default => [$a['employee'], $a['skill']] <=> [$b['employee'], $b['skill']],
                },
            ])
            ->when($this->direction === 'desc', fn (Collection $rows): Collection => $rows->reverse())
            ->values();
    }

    /** @return array<string, string> organisation-unit stable id => name */
    private function departmentNames(int $companyEntityId): array
    {
        $names = [];
        foreach (app(WorkforceSubjects::class)->organizationUnits($companyEntityId) as $unit) {
            $names[$unit->reference->externalId] = $unit->name;
        }

        return $names;
    }

    /** @return array<int, string|null> employee entity id => organisation-unit stable id */
    private function employeeDepartments(int $companyEntityId): array
    {
        $departments = [];
        foreach (app(WorkforceSubjects::class)->employees($companyEntityId) as $employee) {
            $departments[(int) $employee->reference->externalId] = $employee->organizationReference?->externalId;
        }

        return $departments;
    }

    /** @return array<int, string> */
    private function skillNames(int $companyEntityId): array
    {
        return Skill::query()->forCompany($this->tenantId(), $companyEntityId)->orderBy('name')->pluck('name', 'id')
            ->map(static fn ($name): string => (string) $name)->all();
    }

    /** @return array<int, string> */
    private function allowedCompanies(): array
    {
        return $this->allowedCompanies ??= app(SkillAudience::class)->allowedCompanies(Auth::user(), self::VIEW_CAPABILITY);
    }

    private function tenantId(): int
    {
        return app(TenantContext::class)->requireTenantId();
    }

    private function authorizeView(): void
    {
        try {
            app(SkillAudience::class)->authorizeAudience(Auth::user(), self::VIEW_CAPABILITY);
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }

    private function requireCompany(): int
    {
        $this->authorizeView();
        $companyEntityId = $this->companyEntityId;
        abort_unless($companyEntityId !== null && array_key_exists($companyEntityId, $this->allowedCompanies()), 404);

        return $companyEntityId;
    }

    private static function isExpired(mixed $validUntil, CarbonImmutable $today): bool
    {
        return $validUntil !== null
            && CarbonImmutable::parse($validUntil)->startOfDay()->lessThan($today);
    }
}
