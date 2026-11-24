<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\DTOs;

final readonly class BenchDiagnosisDTO
{
    /**
     * @param  list<array{line_type: string, product_id?: string|null, description: string, qty: int|float|string, unit_cost: int|float|string, unit_price: int|float|string, defective_part_serial?: string|null, supplier_id?: string|null, replacement_part_serial?: string|null}>  $lines
     */
    public function __construct(
        public string $labBenchDiagnosticNotes,
        public bool $repairFeasible,
        public bool $partsAvailable,
        public array $lines,
        public bool $goodwill = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromValidated(array $data): self
    {
        return new self(
            labBenchDiagnosticNotes: (string) $data['lab_bench_diagnostic_notes'],
            repairFeasible: (bool) $data['repair_feasible'],
            partsAvailable: (bool) ($data['parts_available'] ?? true),
            lines: array_values($data['lines'] ?? []),
            goodwill: (bool) ($data['goodwill'] ?? false),
        );
    }
}
