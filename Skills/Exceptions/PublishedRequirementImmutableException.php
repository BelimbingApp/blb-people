<?php

namespace App\Domains\People\Skills\Exceptions;

use RuntimeException;

/**
 * A published requirement profile refuses mutation so historical policy
 * stays intact. Change the profile by creating a new draft version instead.
 */
class PublishedRequirementImmutableException extends RuntimeException {}
