<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Support;

use Modules\Hse\Domain\Enums\Jurisdiction;

/**
 * The fixed legal wording of the PPE hand-over record, per locale: a statutory document carries its own text (the
 * acknowledgement clause cites D.Lgs. 81/08 Art. 75-78 in Italy and OLL Art. 5 and 90 in Switzerland), so it does not
 * depend on the interface translations.
 */
final class VerbaleLabels
{
    /**
     * @return array<string, string>
     */
    public static function for(string $locale, Jurisdiction $jurisdiction): array
    {
        $language = strtolower(substr($locale, 0, 2));
        $labels = self::LABELS[$language] ?? self::LABELS['en'];
        $labels['legal'] = ($jurisdiction === Jurisdiction::Italy ? self::LEGAL_IT : self::LEGAL_CH)[$language] ?? ($jurisdiction === Jurisdiction::Italy ? self::LEGAL_IT : self::LEGAL_CH)['en'];

        return $labels;
    }

    private const array LABELS = [
        'it' => ['title' => 'Verbale di consegna DPI', 'employer' => 'Datore di lavoro', 'worker' => 'Lavoratore', 'item' => 'Dispositivo', 'standard' => 'Norma CE', 'lot' => 'Lotto / matricola', 'quantity' => 'Quantità', 'handed_over' => 'Consegnato il', 'replacement' => 'Sostituzione entro', 'hash' => 'Impronta di integrità (SHA-256)', 'signature' => 'Firma del lavoratore', 'vat' => 'P.IVA'],
        'de' => ['title' => 'Übergabeprotokoll PSA', 'employer' => 'Arbeitgeber', 'worker' => 'Arbeitnehmer', 'item' => 'Ausrüstung', 'standard' => 'CE-Norm', 'lot' => 'Charge / Seriennummer', 'quantity' => 'Menge', 'handed_over' => 'Übergeben am', 'replacement' => 'Ersatz bis', 'hash' => 'Integritäts-Hash (SHA-256)', 'signature' => 'Unterschrift des Arbeitnehmers', 'vat' => 'MWST-Nr.'],
        'fr' => ['title' => 'Procès-verbal de remise EPI', 'employer' => 'Employeur', 'worker' => 'Travailleur', 'item' => 'Équipement', 'standard' => 'Norme CE', 'lot' => 'Lot / numéro de série', 'quantity' => 'Quantité', 'handed_over' => 'Remis le', 'replacement' => 'Remplacement avant le', 'hash' => "Empreinte d'intégrité (SHA-256)", 'signature' => 'Signature du travailleur', 'vat' => 'N° TVA'],
        'en' => ['title' => 'PPE hand-over record', 'employer' => 'Employer', 'worker' => 'Worker', 'item' => 'Equipment', 'standard' => 'CE standard', 'lot' => 'Lot / serial number', 'quantity' => 'Quantity', 'handed_over' => 'Handed over on', 'replacement' => 'Replace by', 'hash' => 'Integrity hash (SHA-256)', 'signature' => 'Worker signature', 'vat' => 'VAT no.'],
    ];

    private const array LEGAL_IT = [
        'it' => 'Il lavoratore dichiara di aver ricevuto il dispositivo di protezione individuale indicato, di essere stato informato e formato sul suo corretto uso e sui rischi da cui protegge, e si impegna a utilizzarlo e a curarlo come previsto dagli artt. 75-78 del D.Lgs. 81/2008, segnalando ogni difetto o inconveniente.',
        'de' => 'Der Arbeitnehmer bestätigt den Erhalt der genannten persönlichen Schutzausrüstung, über deren richtigen Gebrauch und die abgedeckten Risiken informiert und geschult worden zu sein, und verpflichtet sich, sie gemäss Art. 75-78 D.Lgs. 81/2008 zu verwenden und zu pflegen.',
        'fr' => "Le travailleur déclare avoir reçu l'équipement de protection individuelle indiqué, avoir été informé et formé à son bon usage et aux risques couverts, et s'engage à l'utiliser et à l'entretenir conformément aux art. 75-78 du D.Lgs. 81/2008.",
        'en' => 'The worker acknowledges receiving the personal protective equipment above, having been informed and trained on its correct use and the risks it covers, and undertakes to use and look after it as required by Art. 75-78 of D.Lgs. 81/2008.',
    ];

    private const array LEGAL_CH = [
        'it' => "Il lavoratore conferma di aver ricevuto l'equipaggiamento di protezione individuale indicato, di essere stato istruito sul suo uso corretto e si impegna a utilizzarlo secondo gli art. 5 e 90 dell'OPI (OLL).",
        'de' => 'Der Arbeitnehmer bestätigt den Erhalt der genannten persönlichen Schutzausrüstung, über den richtigen Gebrauch instruiert worden zu sein, und verpflichtet sich, sie gemäss Art. 5 und 90 VUV (OLL) zu benutzen.',
        'fr' => "Le travailleur confirme avoir reçu l'équipement de protection individuelle indiqué, avoir été instruit de son bon usage et s'engage à l'utiliser conformément aux art. 5 et 90 OPA (OLL).",
        'en' => 'The worker confirms receiving the personal protective equipment above, having been instructed on its correct use, and undertakes to use it in accordance with Art. 5 and 90 of the OLL/VUV accident prevention ordinance.',
    ];
}
