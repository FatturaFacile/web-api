<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Infrastructure;

use Modules\SwissFiscal\Domain\Contracts\FiscalDocumentGeneratorInterface;
use Modules\SwissFiscal\Domain\Exceptions\SwissFiscalException;

/** Resolves the fiscal document strategy of a country; new countries register without touching the existing ones. */
final class FiscalDocumentGeneratorRegistry
{
    /** @var array<string, FiscalDocumentGeneratorInterface> */
    private array $generators = [];

    /**
     * @param  iterable<FiscalDocumentGeneratorInterface>  $generators
     */
    public function __construct(iterable $generators = [])
    {
        foreach ($generators as $generator) {
            $this->register($generator);
        }
    }

    public function register(FiscalDocumentGeneratorInterface $generator): void
    {
        $this->generators[strtoupper($generator->country())] = $generator;
    }

    public function for(string $country): FiscalDocumentGeneratorInterface
    {
        return $this->generators[strtoupper($country)]
            ?? throw new SwissFiscalException("No fiscal document generator is registered for {$country}.");
    }

    /** @return list<string> */
    public function countries(): array
    {
        return array_keys($this->generators);
    }
}
