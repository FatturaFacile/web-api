<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Modules\DataResidency\Infrastructure\Models\SovereigntyComplianceAttestation;

/**
 * The auditor-ready PDF of a Data Sovereignty Attestation Certificate, in the language of the reader (it, de, fr, en). The
 * PDF carries the certificate number, the topology table, the key status, the frameworks, the payload hash and the
 * signature, so a printed copy can be checked against the JSON bundle.
 */
final class AttestationPdfRenderer
{
    private const array LABELS = [
        'en' => ['title' => 'Data Sovereignty Attestation Certificate', 'number' => 'Certificate number', 'issued' => 'Issued', 'valid' => 'Valid until', 'zone' => 'Jurisdiction', 'topology' => 'Physical topology', 'service' => 'Service', 'node' => 'Node', 'location' => 'Location', 'provider' => 'Provider', 'certs' => 'Certifications', 'key' => 'Key management', 'mode' => 'Mode', 'status' => 'Status', 'frameworks' => 'Compliance frameworks', 'hash' => 'SHA-256 payload hash', 'signature' => 'Ed25519 signature', 'signer' => 'Signer key fingerprint'],
        'it' => ['title' => 'Certificato di attestazione della sovranità dei dati', 'number' => 'Numero certificato', 'issued' => 'Emesso il', 'valid' => 'Valido fino al', 'zone' => 'Giurisdizione', 'topology' => 'Topologia fisica', 'service' => 'Servizio', 'node' => 'Nodo', 'location' => 'Ubicazione', 'provider' => 'Fornitore', 'certs' => 'Certificazioni', 'key' => 'Gestione delle chiavi', 'mode' => 'Modalità', 'status' => 'Stato', 'frameworks' => 'Quadri normativi', 'hash' => 'Hash SHA-256 del contenuto', 'signature' => 'Firma Ed25519', 'signer' => 'Impronta della chiave di firma'],
        'de' => ['title' => 'Bescheinigung der Datensouveränität', 'number' => 'Zertifikatsnummer', 'issued' => 'Ausgestellt am', 'valid' => 'Gültig bis', 'zone' => 'Rechtsraum', 'topology' => 'Physische Topologie', 'service' => 'Dienst', 'node' => 'Knoten', 'location' => 'Standort', 'provider' => 'Anbieter', 'certs' => 'Zertifizierungen', 'key' => 'Schlüsselverwaltung', 'mode' => 'Modus', 'status' => 'Status', 'frameworks' => 'Regelwerke', 'hash' => 'SHA-256-Hash der Nutzdaten', 'signature' => 'Ed25519-Signatur', 'signer' => 'Fingerabdruck des Signaturschlüssels'],
        'fr' => ['title' => 'Certificat d\'attestation de souveraineté des données', 'number' => 'Numéro du certificat', 'issued' => 'Émis le', 'valid' => 'Valable jusqu\'au', 'zone' => 'Juridiction', 'topology' => 'Topologie physique', 'service' => 'Service', 'node' => 'Nœud', 'location' => 'Emplacement', 'provider' => 'Fournisseur', 'certs' => 'Certifications', 'key' => 'Gestion des clés', 'mode' => 'Mode', 'status' => 'Statut', 'frameworks' => 'Référentiels', 'hash' => 'Empreinte SHA-256 du contenu', 'signature' => 'Signature Ed25519', 'signer' => 'Empreinte de la clé de signature'],
    ];

    public function render(SovereigntyComplianceAttestation $attestation, string $locale = 'en'): string
    {
        $t = self::LABELS[$locale] ?? self::LABELS['en'];
        $e = static fn (mixed $value): string => e(is_scalar($value) ? (string) $value : '');

        $rows = '';

        foreach ((array) ($attestation->topology_snapshot_json['nodes'] ?? []) as $service => $node) {
            $node = (array) $node;
            $rows .= '<tr><td>'.$e($service).'</td><td>'.$e($node['node_code'] ?? '').'</td><td>'.$e(($node['city'] ?? '').', '.($node['canton_or_region'] ?? '').' ('.($node['country_iso2'] ?? '').')').'</td><td>'.$e($node['provider_name'] ?? '').'</td><td>'.$e(implode(', ', (array) ($node['certifications'] ?? []))).'</td></tr>';
        }

        $byok = $attestation->byok_snapshot_json;
        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px}h1{font-size:18px}table{width:100%;border-collapse:collapse}td,th{border:1px solid #999;padding:4px;text-align:left}.mono{font-family:monospace;word-break:break-all}</style></head><body>'
            .'<h1>'.$e($t['title']).'</h1>'
            .'<p><b>'.$e($t['number']).':</b> '.$e($attestation->certificate_number).'<br><b>'.$e($t['zone']).':</b> '.$e($attestation->jurisdiction_zone)
            .'<br><b>'.$e($t['issued']).':</b> '.$e($attestation->issued_at->toDateString()).' &nbsp; <b>'.$e($t['valid']).':</b> '.$e($attestation->valid_until->toDateString()).'</p>'
            .'<h2>'.$e($t['topology']).'</h2><table><tr><th>'.$e($t['service']).'</th><th>'.$e($t['node']).'</th><th>'.$e($t['location']).'</th><th>'.$e($t['provider']).'</th><th>'.$e($t['certs']).'</th></tr>'.$rows.'</table>'
            .'<h2>'.$e($t['key']).'</h2><p>'.$e($t['mode']).': '.$e($byok['mode'] ?? '-').' &middot; '.$e($t['status']).': '.$e($byok['status'] ?? '-').'</p>'
            .'<h2>'.$e($t['frameworks']).'</h2><p>'.$e(implode(' · ', $attestation->compliance_frameworks_json)).'</p>'
            .'<p><b>'.$e($t['hash']).':</b><br><span class="mono">'.$e($attestation->sha256_payload_hash).'</span></p>'
            .'<p><b>'.$e($t['signature']).':</b><br><span class="mono">'.$e($attestation->digital_signature).'</span></p>'
            .'<p><b>'.$e($t['signer']).':</b> <span class="mono">'.$e($attestation->signer_key_fingerprint).'</span></p></body></html>';

        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }
}
