<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Organisation\Contracts\ReadsOrganisationExplorer;
use App\Domains\People\Organisation\Data\OrganisationAggregate;
use App\Domains\People\Organisation\Data\OrganisationNode;
use App\Domains\People\Organisation\Enums\OrganisationIndicator;
use App\Domains\People\Organisation\Enums\OrganisationPurpose;
use App\Domains\People\Organisation\Enums\OrganisationReadRefusal;
use App\Domains\People\Organisation\Services\NativeOrganisationExplorer;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceCompany;
use App\Domains\People\Provider\Data\WorkforceEmployee;
use App\Domains\People\Provider\Data\WorkforceOrganizationUnit;
use App\Domains\People\Provider\Data\WorkforceRemapFact;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use Illuminate\Support\Collection;

function organisationActor(int $id, int $companyId = 10): Actor
{
    return new Actor(PrincipalType::USER, $id, $companyId, tenantId: 1);
}

function organisationSubject(
    WorkforceResourceType $type,
    string $stableId,
    int $companyId = 10,
): WorkforceSubject {
    return new WorkforceSubject(1, $companyId, $type, $stableId);
}

/** @param list<string> $capabilities */
function organisationExplorer(array $capabilities): NativeOrganisationExplorer
{
    $tenant = Mockery::mock(TenantContext::class);
    $tenant->shouldReceive('currentTenantId')->andReturn(1);

    return new NativeOrganisationExplorer(
        $tenant,
        new OrganisationTestAuthorization($capabilities),
        new OrganisationTestDirectory,
    );
}

test('the organisation read contract resolves to the native directory implementation', function (): void {
    expect(app(ReadsOrganisationExplorer::class))->toBeInstanceOf(NativeOrganisationExplorer::class);
});

test('executive scope does not cross the selected legal company', function (): void {
    $reader = organisationExplorer([
        'people.organisation.structure.view',
        'people.organisation.audience.executive',
    ]);

    expect($reader->structureNode(
        organisationActor(101),
        organisationSubject(WorkforceResourceType::Company, 'company-b', 20),
        new DateTimeImmutable('now'),
    ))->toBe(OrganisationReadRefusal::WrongCompany);
});

test('hod scope refuses a department not assigned to that head', function (): void {
    $reader = organisationExplorer([
        'people.organisation.structure.view',
        'people.organisation.audience.hod',
    ]);

    expect($reader->structureNode(
        organisationActor(102),
        organisationSubject(WorkforceResourceType::OrganizationUnit, 'unit-b'),
        new DateTimeImmutable('now'),
    ))->toBe(OrganisationReadRefusal::OutsideAudienceScope)
        ->and($reader->structureNode(
            organisationActor(102),
            organisationSubject(WorkforceResourceType::OrganizationUnit, 'unit-a'),
            new DateTimeImmutable('now'),
        ))->toBeInstanceOf(OrganisationNode::class);
});

test('employee scope refuses a colleague record while permitting self', function (): void {
    $reader = organisationExplorer([
        'people.organisation.structure.view',
        'people.organisation.audience.employee',
    ]);

    expect($reader->structureNode(
        organisationActor(103),
        organisationSubject(WorkforceResourceType::Employee, 'employee-b'),
        new DateTimeImmutable('now'),
    ))->toBe(OrganisationReadRefusal::OutsideAudienceScope)
        ->and($reader->structureNode(
            organisationActor(103),
            organisationSubject(WorkforceResourceType::Employee, 'employee-a'),
            new DateTimeImmutable('now'),
        ))->toBeInstanceOf(OrganisationNode::class);
});

test('hr governance scope does not cross its attributed legal company', function (): void {
    $reader = organisationExplorer([
        'people.organisation.structure.view',
        'people.organisation.audience.hr',
    ]);

    expect($reader->structureNode(
        organisationActor(104),
        organisationSubject(WorkforceResourceType::Company, 'company-b', 20),
        new DateTimeImmutable('now'),
    ))->toBe(OrganisationReadRefusal::WrongCompany);
});

test('auditor scope refuses reads until an approved engagement and period exist', function (): void {
    $reader = organisationExplorer([
        'people.organisation.structure.view',
        'people.organisation.audience.auditor',
    ]);

    expect($reader->structureNode(
        organisationActor(105),
        organisationSubject(WorkforceResourceType::Company, 'company-a'),
        new DateTimeImmutable('now'),
    ))->toBe(OrganisationReadRefusal::AudienceScopeUnavailable);
});

test('aggregate permission is independent from structure and record access', function (): void {
    $reader = organisationExplorer([
        'people.organisation.aggregate.view',
        'people.organisation.audience.hr',
    ]);
    $company = organisationSubject(WorkforceResourceType::Company, 'company-a');
    $aggregate = $reader->aggregateIndicator(
        organisationActor(106),
        $company,
        OrganisationIndicator::Headcount,
        new DateTimeImmutable('now'),
    );

    expect($aggregate)->toBeInstanceOf(OrganisationAggregate::class)
        ->and($aggregate->value)->toBe(4)
        ->and($reader->structureNode(
            organisationActor(106),
            $company,
            new DateTimeImmutable('now'),
        ))->toBe(OrganisationReadRefusal::MissingCapability)
        ->and($reader->drillThrough(
            organisationActor(106),
            new OrganisationNode(
                $company,
                'Company A',
                true,
                new DateTimeImmutable('now'),
                new DateTimeImmutable('now'),
            ),
            OrganisationPurpose::IndividualDetail,
        ))->toBe(OrganisationReadRefusal::MissingCapability);
});

test('current-only directory refuses historical reads rather than inventing history', function (): void {
    $reader = organisationExplorer([
        'people.organisation.structure.view',
        'people.organisation.audience.executive',
    ]);

    expect($reader->structureNode(
        organisationActor(101),
        organisationSubject(WorkforceResourceType::Company, 'company-a'),
        new DateTimeImmutable('-1 day'),
    ))->toBe(OrganisationReadRefusal::HistoricalReadUnavailable);
});

test('drill-through re-resolves a supplied node instead of trusting caller data', function (): void {
    $reader = organisationExplorer([
        'people.organisation.structure.view',
        'people.organisation.audience.executive',
    ]);
    $forged = new OrganisationNode(
        organisationSubject(WorkforceResourceType::Company, 'not-a-company'),
        'Forged company label',
        true,
        new DateTimeImmutable('now'),
        new DateTimeImmutable('now'),
    );

    expect($reader->drillThrough(
        organisationActor(101),
        $forged,
        OrganisationPurpose::Structure,
    ))->toBe(OrganisationReadRefusal::UnknownSubject);
});

final class OrganisationTestAuthorization implements AuthorizationService
{
    /** @param list<string> $capabilities */
    public function __construct(private readonly array $capabilities) {}

    public function can(
        Actor $actor,
        string $capability,
        ?ResourceContext $resource = null,
        array $context = [],
    ): AuthorizationDecision {
        return in_array($capability, $this->capabilities, true)
            ? AuthorizationDecision::allow(['explicit_test_grant'])
            : AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY);
    }

    public function authorize(
        Actor $actor,
        string $capability,
        ?ResourceContext $resource = null,
        array $context = [],
    ): void {
        if (! $this->can($actor, $capability, $resource, $context)->allowed) {
            throw new LogicException('Test authorization refused the capability.');
        }
    }

    public function filterAllowed(
        Actor $actor,
        string $capability,
        iterable $resources,
        array $context = [],
    ): Collection {
        return $this->can($actor, $capability, context: $context)->allowed
            ? collect($resources)
            : collect();
    }
}

final class OrganisationTestDirectory implements ReadsWorkforceDirectory
{
    private DateTimeImmutable $now;

    public function __construct()
    {
        $this->now = new DateTimeImmutable('now');
    }

    public function companyForPlatform(int $platformCompanyId): ?WorkforceCompany
    {
        return match ($platformCompanyId) {
            10 => $this->company('company-a'),
            20 => $this->company('company-b'),
            default => null,
        };
    }

    public function company(string $companyStableId): ?WorkforceCompany
    {
        return match ($companyStableId) {
            'company-a' => new WorkforceCompany(
                new ExternalReference(WorkforceResourceType::Company, 'company-a'),
                'Company A',
                true,
                $this->now,
            ),
            'company-b' => new WorkforceCompany(
                new ExternalReference(WorkforceResourceType::Company, 'company-b'),
                'Company B',
                true,
                $this->now,
            ),
            default => null,
        };
    }

    public function employees(string $companyStableId): array
    {
        if ($companyStableId !== 'company-a') {
            return [];
        }

        $company = new ExternalReference(WorkforceResourceType::Company, 'company-a');
        $unitA = new ExternalReference(WorkforceResourceType::OrganizationUnit, 'unit-a');
        $unitB = new ExternalReference(WorkforceResourceType::OrganizationUnit, 'unit-b');
        $hod = new ExternalReference(WorkforceResourceType::Employee, 'hod-a');
        $otherHod = new ExternalReference(WorkforceResourceType::Employee, 'hod-b');

        return [
            $this->employee('hod-a', 'Head A', $company, $unitA, 102, $hod),
            $this->employee('employee-a', 'Employee A', $company, $unitA, 103, $hod),
            $this->employee('hod-b', 'Head B', $company, $unitB, 202, $otherHod),
            $this->employee('employee-b', 'Employee B', $company, $unitB, 203, $otherHod),
        ];
    }

    public function organizationUnits(string $companyStableId): array
    {
        if ($companyStableId !== 'company-a') {
            return [];
        }

        $company = new ExternalReference(WorkforceResourceType::Company, 'company-a');

        return [
            new WorkforceOrganizationUnit(
                new ExternalReference(WorkforceResourceType::OrganizationUnit, 'unit-a'),
                $company,
                'Unit A',
                true,
                $this->now,
                $this->now,
            ),
            new WorkforceOrganizationUnit(
                new ExternalReference(WorkforceResourceType::OrganizationUnit, 'unit-b'),
                $company,
                'Unit B',
                true,
                $this->now,
                $this->now,
            ),
        ];
    }

    public function employeeForUser(string $companyStableId, int $platformUserId): ?WorkforceEmployee
    {
        foreach ($this->employees($companyStableId) as $employee) {
            if ($employee->userReference?->externalId === (string) $platformUserId) {
                return $employee;
            }
        }

        return null;
    }

    public function remap(
        WorkforceResourceType $type,
        string $fromStableId,
        string $toStableId,
    ): ?WorkforceRemapFact {
        return null;
    }

    private function employee(
        string $id,
        string $name,
        ExternalReference $company,
        ExternalReference $unit,
        int $userId,
        ExternalReference $head,
    ): WorkforceEmployee {
        return new WorkforceEmployee(
            reference: new ExternalReference(WorkforceResourceType::Employee, $id),
            companyReference: $company,
            displayName: $name,
            active: true,
            effectiveAt: $this->now,
            observedAt: $this->now,
            userReference: new ExternalReference(WorkforceResourceType::User, (string) $userId),
            organizationReference: $unit,
            departmentHeadReference: $head,
        );
    }
}
