<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

/**
 * One record of the module by its id: a garment, a par contract, a scan session, a wash batch, a billing run or a roll cage.
 */
final readonly class GetEntityDetailQuery
{
    public const string Garment = 'garment';

    public const string ParContract = 'par_contract';

    public const string ScanSession = 'scan_session';

    public const string WashBatch = 'wash_batch';

    public const string BillingRun = 'billing_run';

    public const string RollCage = 'roll_cage';

    public function __construct(public string $entity, public string $id) {}
}
