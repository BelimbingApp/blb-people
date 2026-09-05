<?php

namespace App\Domains\People\Skills\Enums;

enum SelfStandingRefusal: string
{
    case Unauthorized = 'unauthorized';
    case MissingScope = 'missing_scope';
    case SubjectMismatch = 'subject_mismatch';
    case BindingUnavailable = 'binding_unavailable';
    case Unavailable = 'unavailable';
    case Unpublished = 'unpublished';
    case UnsupportedPeriod = 'unsupported_period';
}
