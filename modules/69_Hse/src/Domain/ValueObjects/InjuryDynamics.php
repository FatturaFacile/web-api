<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\ValueObjects;

/**
 * How an injury happened, in the ESAW / SUVA coding: what the worker was doing, the deviation from the normal process,
 * the material agent, the injured body part and the nature of the lesion.
 */
final readonly class InjuryDynamics
{
    public function __construct(
        public string $activityAtTimeOfAccident,
        public string $deviatingEvent,
        public string $materialAgent,
        public string $injuredBodyPartCode,
        public string $lesionNatureCode,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null || $data === []) {
            return null;
        }

        return new self(
            (string) ($data['activityAtTimeOfAccident'] ?? ''),
            (string) ($data['deviatingEvent'] ?? ''),
            (string) ($data['materialAgent'] ?? ''),
            (string) ($data['injuredBodyPartCode'] ?? ''),
            (string) ($data['lesionNatureCode'] ?? ''),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'activityAtTimeOfAccident' => $this->activityAtTimeOfAccident,
            'deviatingEvent' => $this->deviatingEvent,
            'materialAgent' => $this->materialAgent,
            'injuredBodyPartCode' => $this->injuredBodyPartCode,
            'lesionNatureCode' => $this->lesionNatureCode,
        ];
    }
}
