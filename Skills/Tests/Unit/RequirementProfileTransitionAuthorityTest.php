<?php

use App\Domains\People\Skills\Enums\RequirementProfileStatus;
use App\Domains\People\Skills\Exceptions\PublishedRequirementImmutableException;
use App\Domains\People\Skills\Models\RequirementProfile;
use App\Domains\People\Skills\Workflow\RequirementProfileTransitionAuthority;
use Tests\TestCase;

uses(TestCase::class);

test('database lifecycle authority fails closed outside a transaction', function (): void {
    $profile = new RequirementProfile;
    $profile->setRawAttributes([
        'id' => 42,
        'tenant_id' => 7,
        'status' => RequirementProfileStatus::Draft->value,
    ], true);

    expect(fn () => app(RequirementProfileTransitionAuthority::class)->authorizeDatabaseWrite(
        $profile,
        RequirementProfileStatus::Draft,
        RequirementProfileStatus::PendingHodReview,
    ))->toThrow(
        PublishedRequirementImmutableException::class,
        'requires an active database transaction',
    );
});
