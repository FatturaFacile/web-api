<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Services\RecurringContracts\IndexationAdjustmentEngine;
use PHPUnit\Framework\TestCase;

class ContractIndexationUnitTest extends TestCase
{
    private IndexationAdjustmentEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new IndexationAdjustmentEngine;
    }

    public function test_calculate_delta_percentage_basic(): void
    {
        // 104 from base 100 = 4%
        $delta = $this->engine->calculateDeltaPercentage('100.0000', '104.0000');
        $this->assertEquals('4.0000', $delta);
    }

    public function test_calculate_delta_percentage_negative(): void
    {
        // 98 from base 100 = -2%
        $delta = $this->engine->calculateDeltaPercentage('100.0000', '98.0000');
        $this->assertEquals('-2.0000', $delta);
    }

    public function test_apply_clause_modifiers_with_pass_through(): void
    {
        // 4% raw with 75% pass-through = 3%
        $applied = $this->engine->applyClauseModifiers('4.0000', '75.00', null, null);
        $this->assertEquals('3.0000', $applied);
    }

    public function test_apply_clause_modifiers_with_cap(): void
    {
        // 8% raw with 100% pass-through capped at 3.50%
        $applied = $this->engine->applyClauseModifiers('8.0000', '100.00', null, '3.50');
        $this->assertEquals('3.5000', $applied);
    }

    public function test_apply_clause_modifiers_with_floor(): void
    {
        // 0.5% raw with 100% pass-through floored at 1.00%
        $applied = $this->engine->applyClauseModifiers('0.5000', '100.00', '1.00', '5.00');
        $this->assertEquals('1.0000', $applied);
    }
}
