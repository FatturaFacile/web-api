<?php

declare(strict_types=1);

return [
    // Block issuing a cross-border invoice that lacks Incoterm, tariff code, origin or net weight.
    'enforce_readiness' => (bool) env('FOREIGN_TRADE_ENFORCE_READINESS', true),
];
