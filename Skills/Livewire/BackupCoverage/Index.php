<?php

namespace App\Domains\People\Skills\Livewire\BackupCoverage;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Department;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Services\CriticalSkillBackupCoverage;
use App\Domains\People\Skills\Services\SkillAudience;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * How many people actually cover each critical skill, and which ones rest on
 * one person.
 *
 * "Covers" is deliberately narrow: at or above the required level, not expired,
 * in this company. Each of those is a way something can look like cover and not
 * be — a lapsed certificate is not somebody you can call at 2am, and a
 * colleague in a sibling company is not your cover either.
 */
final class Index extends Component
{
    public const VIEW_CAPABILITY = 'people.skill.coverage.view';

    /**
     * Exporting is separate from viewing because the file leaves the
     * authorization boundary and is audited as such (0007-d).
     */
    public const EXPORT_CAPABILITY = 'people.skill.coverage.export';

    public const EXPORT_EVENT = 'people.skill.coverage.exported';

    /**
     * Department id to narrow the per-department table to, or empty for all.
     * In the URL so a coverage-gap reminder can land on the department it
     * names (0009-i).
     */
    #[Url(as: 'department')]
    public string $department = '';

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function render(TenantContext $tenants): View
    {
        $this->authorizeView();
        $actor = Auth::user();

        $tenantId = (int) $tenants->requireTenantId();
        $companyId = (int) $actor->company_id;
        $coverage = app(CriticalSkillBackupCoverage::class);

        return view('people::livewire.backup-coverage.index', [
            'rows' => $this->rows($tenantId, $companyId),
            'canExport' => app(SkillAudience::class)->mayAccess($actor, self::EXPORT_CAPABILITY),
            // 0007-c: the same question asked per department and against the
            // tenant's own minimum, rather than company-wide against two.
            'departmentRows' => $coverage->rows(
                $tenantId,
                $companyId,
                $this->department === '' ? null : (int) $this->department,
            ),
            'departments' => $this->departmentNames($companyId),
            'minimum' => $coverage->minimum($tenantId),
        ]);
    }

    /**
     * Department id => name, for the filter. Named from the department type,
     * which is where a department's name actually lives.
     *
     * @return array<int, string>
     */
    private function departmentNames(int $companyId): array
    {
        return Department::query()->where('company_id', $companyId)->with('type')->get()
            ->mapWithKeys(static fn (Department $department): array => [
                (int) $department->id => (string) ($department->name ?? __('Unnamed department')),
            ])
            ->all();
    }

    /** The rendered company rows as CSV, and one audit action saying who exported what. */
    public function export(): StreamedResponse
    {
        $actor = Auth::user();
        try {
            app(SkillAudience::class)->authorizeAudience($actor, self::EXPORT_CAPABILITY);
        } catch (AuthorizationDeniedException) {
            abort(403);
        }

        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $companyId = (int) $actor->company_id;
        $asOf = now()->toDateString();
        $rows = $this->rows($tenantId, $companyId, $asOf);
        $filename = sprintf('critical-skill-coverage-%d-%s.csv', $companyId, now()->format('Y-m-d'));

        app(SemanticActionRecorder::class)->record(
            event: self::EXPORT_EVENT,
            summary: __('Exported :count critical skills to CSV', ['count' => count($rows)]),
            source: __('Skills'),
            subject: ['name' => 'critical-skill-coverage', 'identifier' => $filename],
            surface: 'people.skill.backup-coverage.index',
            uiElement: 'export',
            context: [
                'company_entity_id' => $companyId,
                'rows' => count($rows),
                'skill_ids' => array_column($rows, 'skill_id'),
            ],
        );

        return response()->streamDownload(function () use ($rows, $asOf): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['skill', 'covered', 'single_point_of_failure', 'holders', 'as_of']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['skill'],
                    $row['covered'],
                    $row['single_point_of_failure'] ? 'yes' : 'no',
                    implode('; ', $row['holders']),
                    $asOf,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return list<array{skill_id: int, skill: string, covered: int, single_point_of_failure: bool, holders: list<string>}>
     */
    private function rows(int $tenantId, int $companyId, ?string $today = null): array
    {
        $scores = EmployeeSkillScore::query()->forCompany($tenantId, $companyId)
            ->where('criticality', RequirementCriticality::Critical->value)
            ->get();

        if ($scores->isEmpty()) {
            return [];
        }

        $names = Employee::query()->whereIn('id', $scores->pluck('employee_entity_id')->unique())
            ->pluck('full_name', 'id');
        $skills = Skill::query()->forCompany($tenantId, $companyId)
            ->whereIn('id', $scores->pluck('skill_id')->unique())->pluck('name', 'id');

        $today ??= now()->toDateString();
        // The same minimum the per-department report uses (0007-c): one page
        // calling a team resilient while the other calls it exposed would be
        // worse than either answer alone.
        $minimum = app(CriticalSkillBackupCoverage::class)->minimum();
        $rows = [];

        foreach ($scores->groupBy('skill_id') as $skillId => $group) {
            $holders = $group
                ->filter(static fn (EmployeeSkillScore $score): bool => $score->coversRequirement($today))
                ->map(fn (EmployeeSkillScore $score): string => (string) ($names[$score->employee_entity_id] ?? __('Unknown employee')))
                ->values()
                ->all();

            $rows[] = [
                'skill_id' => (int) $skillId,
                'skill' => (string) ($skills[$skillId] ?? __('Unknown skill')),
                'covered' => count($holders),
                'single_point_of_failure' => count($holders) < $minimum,
                'holders' => $holders,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['covered'], $a['skill']] <=> [$b['covered'], $b['skill']]);

        return $rows;
    }

    private function authorizeView(): void
    {
        try {
            app(SkillAudience::class)->authorizeAudience(Auth::user(), self::VIEW_CAPABILITY);
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }
}
