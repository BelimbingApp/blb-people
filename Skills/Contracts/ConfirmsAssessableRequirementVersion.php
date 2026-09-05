<?php

namespace App\Domains\People\Skills\Contracts;

use App\Domains\People\Skills\Exceptions\InvalidAssessmentException;

/**
 * Whether a requirement profile version may be assessed against.
 *
 * A second seam beside ResolvesSkillRequirements, and for the same reason: the
 * assessment surface must not import profile models or stores (blb-people#80,
 * enforced by ResolvesSkillRequirementsContractTest). Assessment needs to know
 * "may I write evidence against this version" without learning what a version
 * is made of, so it asks through a contract the profile side implements.
 */
interface ConfirmsAssessableRequirementVersion
{
    /**
     * @throws InvalidAssessmentException when the version does not belong to
     *                                    this company, or is not published
     */
    public function assertAssessable(int $companyEntityId, int $requirementProfileId): void;
}
