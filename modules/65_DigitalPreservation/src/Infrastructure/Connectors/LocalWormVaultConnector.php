<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Connectors;

use Modules\DigitalPreservation\Domain\Contracts\PreservationConnectorInterface;
use Modules\DigitalPreservation\Domain\Enums\PreservationProvider;
use Modules\DigitalPreservation\Domain\ValueObjects\ConnectorReceipt;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationConfiguration;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationVault;

/**
 * Built-in self-hosted WORM vault: the files are already in the vault, so dispatching only issues a local receipt (RdV).
 * It is not an accredited conservator (AgID).
 */
final readonly class LocalWormVaultConnector implements PreservationConnectorInterface
{
    public function __construct(private PreservationVault $vault) {}

    public function provider(): PreservationProvider
    {
        return PreservationProvider::LocalWorm;
    }

    public function dispatch(PreservationBatch $batch, string $pdvXml, array $files, PreservationConfiguration $configuration): ConnectorReceipt
    {
        $reference = 'LOCAL-'.$batch->batch_number;
        $present = 0;

        foreach (array_keys($files) as $path) {
            $present += $this->vault->exists($path) ? 1 : 0;
        }

        $rdv = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<RapportoDiVersamento Conservatore="LOCAL_WORM" Riferimento="'.htmlspecialchars($reference, ENT_XML1).'">'
            .'<ImprontaPdV Algoritmo="SHA-256">'.hash('sha256', $pdvXml).'</ImprontaPdV>'
            .'<DocumentiAcquisiti>'.$present.'</DocumentiAcquisiti>'
            .'<Esito>ACCETTATO</Esito>'
            .'</RapportoDiVersamento>'."\n";

        return new ConnectorReceipt($reference, $rdv, true);
    }

    public function testConnection(PreservationConfiguration $configuration): bool
    {
        return true;
    }
}
