<?php

namespace App\Domains\People\Training\Enums;

/**
 * What an evaluation follow-up is about: a participant's support request or
 * a concern about the provider or course. One open follow-up per evaluation
 * per kind; different kinds track independently.
 */
enum TrainingEvaluationFollowupKind: string
{
    case SupportRequest = 'support_request';
    case ProviderConcern = 'provider_concern';
}
