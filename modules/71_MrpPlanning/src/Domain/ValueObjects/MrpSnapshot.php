<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\ValueObjects;

/**
 * The complete, immutable input of a planning run, loaded by the infrastructure from the upstream modules.
 */
final readonly class MrpSnapshot
{
    /**
     * @param  array<string, ItemPlanningData>  $items  by item id
     * @param  list<DemandLine>  $demands
     * @param  list<SupplyLine>  $supplies
     * @param  list<ShutdownPeriod>  $shutdowns
     * @param  array<string, WorkCenterCalendar>  $calendars  by work centre id
     * @param  array<string, list<string>>  $alternatives  work centre id => alternative work centre ids
     * @param  list<ScheduleBlock>  $fixedBlocks  blocks of firm orders and kept proposals: they keep their place and load the work centres
     */
    public function __construct(
        public RunParameters $parameters,
        public array $items,
        public array $demands,
        public array $supplies,
        public array $shutdowns = [],
        public array $calendars = [],
        public array $alternatives = [],
        public array $fixedBlocks = [],
    ) {}

    /**
     * The same snapshot with other fixed schedule blocks.
     *
     * @param  list<ScheduleBlock>  $blocks
     */
    public function withFixedBlocks(array $blocks): self
    {
        return new self($this->parameters, $this->items, $this->demands, $this->supplies, $this->shutdowns, $this->calendars, $this->alternatives, $blocks);
    }
}
