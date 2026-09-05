<?php

namespace App\Domains\People\Organisation\Enums;

enum OrganisationReadRefusal: string
{
    case MissingTenant = 'missing_tenant';
    case WrongTenant = 'wrong_tenant';
    case WrongCompany = 'wrong_company';
    case MissingCapability = 'missing_capability';
    case OutsideAudienceScope = 'outside_audience_scope';
    case AudienceScopeUnavailable = 'audience_scope_unavailable';
    case HistoricalReadUnavailable = 'historical_read_unavailable';
    case UnknownSubject = 'unknown_subject';
    case UnsupportedSubject = 'unsupported_subject';
}
