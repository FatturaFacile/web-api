<?php

declare(strict_types=1);

namespace App\Enums;

enum ContactType: string
{
    case Client = 'client';
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Both = 'both';
    case Lead = 'lead';
}
