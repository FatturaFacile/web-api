<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Application\Commands;

use Modules\Beekeeping\Models\Hive;

final readonly class RecordHiveInspectionCommand
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public Hive $hive, public array $data, public ?string $operatorId = null) {}
}
