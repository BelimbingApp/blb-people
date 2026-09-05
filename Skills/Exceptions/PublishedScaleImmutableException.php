<?php

namespace App\Domains\People\Skills\Exceptions;

use RuntimeException;

/**
 * A published proficiency scale refuses mutation so historical scores keep
 * their meaning. Change the scale by creating a new draft version instead.
 */
class PublishedScaleImmutableException extends RuntimeException {}
