<?php

declare(strict_types=1);

namespace Modules\Treasury\Services\PaymentFlows;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;

/**
 * Turns a SEPA pain.001.001.03 file into a CBI bonifico flow: the CBI implementation guidelines identify the initiating
 * party with its CUC (Codice Unico CBI) as `InitgPty/Id/OrgId/Othr` issued by `CBI`.
 */
final class CbiInitiatorDecorator
{
    public const string ISSUER = 'CBI';

    public function apply(string $xml, string $cuc): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        if (! $document->loadXML($xml, LIBXML_NONET)) {
            throw new InvalidArgumentException('The payment file is not well-formed XML.');
        }

        $namespace = (string) $document->documentElement?->namespaceURI;
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('p', $namespace);
        $party = $xpath->query('//p:GrpHdr/p:InitgPty')->item(0);

        if ($party === null) {
            throw new InvalidArgumentException('The payment file has no initiating party.');
        }

        foreach ($xpath->query('p:Id', $party) as $existing) {
            $party->removeChild($existing);
        }

        $id = $document->createElementNS($namespace, 'Id');
        $other = $document->createElementNS($namespace, 'Othr');
        $other->appendChild($document->createElementNS($namespace, 'Id', $cuc));
        $other->appendChild($document->createElementNS($namespace, 'Issr', self::ISSUER));
        $id->appendChild($document->createElementNS($namespace, 'OrgId'))->appendChild($other);

        // PartyIdentification32 order: Nm, PstlAdr, Id, CtryOfRes, CtctDtls
        $following = $xpath->query('p:CtryOfRes | p:CtctDtls', $party)->item(0);
        $party->insertBefore($id, $following);

        return (string) $document->saveXML();
    }
}
