<?php

namespace App\Domains\People\Progression\Exceptions;

use DomainException;

/** A publish request the policy store refuses, with the reason in the message. */
final class ProgressionPolicyPublicationException extends DomainException {}
