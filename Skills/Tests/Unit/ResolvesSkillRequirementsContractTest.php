<?php

use App\Domains\People\Skills\Contracts\ResolvesSkillRequirements;
use App\Domains\People\Skills\Data\ResolvedSkillRequirement;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Services\RequirementResolver;

/**
 * Fixture-only resolver — unit-tests assessment gap math against the contract
 * DTO with no profile implementation loaded (BelimbingApp/blb-people#80).
 *
 * This is NOT the architectural boundary guard; see the arch expectations below
 * (BelimbingApp/blb-people-connector#83).
 */
final class FixtureSkillRequirements implements ResolvesSkillRequirements
{
    /**
     * @param  list<ResolvedSkillRequirement>  $rows
     */
    public function __construct(private array $rows) {}

    public function requirementsFor(array $employeeData, ?DateTimeInterface $asOf = null): array
    {
        return $this->rows;
    }
}

test('assessment gap math runs against fixture requirements with no profile implementation', function (): void {
    $resolver = new FixtureSkillRequirements([
        new ResolvedSkillRequirement(
            requirementReference: 'fixture.ops',
            requirementVersion: 3,
            requirementProfileId: 3001,
            skillId: 101,
            requiredLevel: 4,
            criticality: RequirementCriticality::Critical,
            mandatoryGate: true,
        ),
        new ResolvedSkillRequirement(
            requirementReference: 'fixture.ops',
            requirementVersion: 3,
            requirementProfileId: 3001,
            skillId: 202,
            requiredLevel: 2,
            criticality: RequirementCriticality::Development,
        ),
    ]);

    app()->instance(ResolvesSkillRequirements::class, $resolver);

    $requirements = app(ResolvesSkillRequirements::class)->requirementsFor([
        'company_entity_id' => 1,
    ]);

    expect($requirements)->toHaveCount(2)
        ->and($requirements[0]->gap(2))->toBe(2)
        ->and($requirements[0]->gap(4))->toBe(0)
        ->and($requirements[0]->gap(5))->toBe(0)
        ->and($requirements[1]->gap(0))->toBe(2)
        ->and($requirements[0]->requirementReference)->toBe('fixture.ops')
        ->and($requirements[0]->requirementVersion)->toBe(3)
        ->and($requirements[0]->mandatoryGate)->toBeTrue()
        ->and(class_exists(RequirementResolver::class))->toBeTrue();

    expect($resolver)->toBeInstanceOf(ResolvesSkillRequirements::class)
        ->and($resolver)->not->toBeInstanceOf(RequirementResolver::class);
});

/**
 * Load-bearing boundary guard (blb-people-connector#83).
 *
 * Assessment must only see ResolvesSkillRequirements + ResolvedSkillRequirement.
 * Pest's not->toUse(class-string) can silently pass; not->toBeUsedIn is the
 * direction that actually fails when a profile type is imported into the
 * assessment surface.
 *
 * Avoid toOnlyBeUsedIn (full-app scan OOMs CI at 512MB). Profile internals under test:
 * - RequirementProfile / RequirementProfileSelector / RequirementItem models
 * - RequirementProfileStore / RequirementResolver (concrete profile machinery)
 *
 * Assessment surface under test (FQCNs — files may land via people #12 / #80):
 * - AssessmentStore
 * - Livewire\Assessment (HOD matrix)
 * - SkillAssessment / EmployeeSkillScore models
 */
/**
 * The assessment surface, resolved once.
 *
 * Both boundary guards below scan this and nothing else. They used to build
 * their own lists and the lists disagreed: the import guard read four hardcoded
 * paths while the table guard recursed `Livewire/Assessment`, so a new
 * component under that directory was covered against table names and invisible
 * to the import rule (#480). Two neighbouring guards with different considered
 * sets leave the gap between them unguarded, and nothing fails to say so.
 *
 * One list, resolved by directory rather than by remembering to append, means a
 * new assessment-surface file is covered by construction.
 *
 * Helper names are global across the composed Pest suite, hence the prefix.
 *
 * @return list<string>
 */
function resolvesSkillRequirementsSurfaceFiles(): array
{
    $skillRoot = dirname(__DIR__, 2);
    $relativePaths = [
        'Services/AssessmentStore.php',
        'Livewire/Assessment',
        'Models/SkillAssessment.php',
        'Models/EmployeeSkillScore.php',
    ];

    $files = [];
    foreach ($relativePaths as $relative) {
        $absolute = $skillRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($absolute)) {
            $files[] = $absolute;

            continue;
        }
        if (is_dir($absolute)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile() && str_ends_with($fileInfo->getFilename(), '.php')) {
                    $files[] = $fileInfo->getPathname();
                }
            }
        }
    }

    sort($files);

    return $files;
}

test('assessment surface must not import requirement-profile internals', function (): void {
    $profileInternals = [
        'App\\Domains\\People\\Skills\\Models\\RequirementProfile',
        'App\\Domains\\People\\Skills\\Models\\RequirementProfileSelector',
        'App\\Domains\\People\\Skills\\Models\\RequirementItem',
        'App\\Domains\\People\\Skills\\Services\\RequirementProfileStore',
        'App\\Domains\\People\\Skills\\Services\\RequirementResolver',
    ];

    $files = resolvesSkillRequirementsSurfaceFiles();
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect($contents)->not->toBeFalse();

        foreach ($profileInternals as $profileInternal) {
            // One needle per negated assertion: not->toContain(a, b) fails only
            // when EVERY needle is present, so a multi-needle form would pass
            // while a single internal leaked.
            expect(str_contains((string) $contents, $profileInternal))
                ->toBeFalse("assessment surface must not import profile internal [{$profileInternal}] in {$file}");
        }
    }
});

// Avoid toOnlyBeUsedIn: full-app dependency scans OOM composed platform CI (512MB).
// Targeted not->toBeUsedIn against the assessment surface above is the load-bearing guard.

/**
 * Arch rules catch use/import edges; they do not see raw table-name strings.
 * Scan assessment-surface PHP sources for the profile tables so a
 * DB::table('people_connector_skill_requirement_…') breach also goes red.
 */
test('assessment surface php sources never name requirement-profile tables', function (): void {
    $forbidden = [
        'people_connector_skill_requirement_profiles',
        'people_connector_skill_requirement_profile_selectors',
        'people_connector_skill_requirement_items',
    ];

    $files = resolvesSkillRequirementsSurfaceFiles();

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect($contents)->not->toBeFalse();
        foreach ($forbidden as $table) {
            expect(str_contains($contents, $table))
                ->toBeFalse("assessment surface must not name profile table [{$table}] in {$file}");
        }
    }
});

/**
 * Guards the guards: a file that did not exist when the guards were written
 * must still be considered by them.
 *
 * The first version of this test compared the helper's output against the
 * directory listing — and passed even when the helper was narrowed back to a
 * single hardcoded path, because `Livewire/Assessment` holds exactly one file
 * today, so both forms produce the same list. It compared two things that
 * happened to agree and proved nothing, which is the same defect shape as #480
 * itself.
 *
 * The property that matters is not "the sets match now", it is "a NEW component
 * is covered by construction". That can only be shown by introducing one.
 */
test('a new assessment Livewire source is considered without being listed', function (): void {
    $directory = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'Livewire'.DIRECTORY_SEPARATOR.'Assessment';
    $probe = $directory.DIRECTORY_SEPARATOR.'ZzConsideredSetProbe.php';

    expect(is_dir($directory))->toBeTrue();
    expect(file_exists($probe))->toBeFalse();

    file_put_contents($probe, "<?php\n\nnamespace App\\Domains\\People\\Skills\\Livewire\\Assessment;\n\nclass ZzConsideredSetProbe {}\n");

    try {
        expect(in_array($probe, resolvesSkillRequirementsSurfaceFiles(), true))
            ->toBeTrue('a new Livewire/Assessment source is not considered by the boundary guards');
    } finally {
        @unlink($probe);
    }

    expect(file_exists($probe))->toBeFalse();
});
