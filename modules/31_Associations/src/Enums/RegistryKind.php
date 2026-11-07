<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

enum RegistryKind: string
{
    case Member = 'member';
    case Cardholder = 'cardholder';
    case Candidate = 'candidate';
    case Volunteer = 'volunteer';
    case Sponsor = 'sponsor';
    case Instructor = 'instructor';
    case Donor = 'donor';
    case SupplierLink = 'supplier_link';
    case Custom = 'custom';
}
