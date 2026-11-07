<?php

declare(strict_types=1);

namespace Modules\Payroll\Swissdec;

/**
 * What separates this application from a Swissdec certified payroll. The application is not certified and states it
 * wherever a declaration is shown. The list comes from the public Swissdec pages on the salary standard: the guidelines of
 * version 5.0 were read through their field overview only, and those of version 6.0, the current basis of certification
 * since 6 March 2026, could not be read.
 */
final class SwissdecCertificationStatus
{
    /** The version whose public field overview the structure follows. */
    public const string StructureBasis = 'ELM 5.0, SalaryDeclarationDomainOverview 20200220';

    /** The schema of that overview. No XSD file was available: nothing here is validated against one. */
    public const string SchemaNamespace = 'http://www.swissdec.ch/schema/sd/20200220/SalaryDeclaration';

    public const string CurrentCertificationBasis = 'ELM 6.0 (20260306)';

    /** @var list<string> */
    public const array Domains = ['AHV-AVS', 'TaxAtSource'];

    /** @var list<string> */
    public const array DomainsNotBuilt = ['UVG-LAA', 'UVGZ-LAAC', 'KTG-AMC', 'BVG-LPP', 'FAK-CAF', 'Tax (salary certificate)', 'Statistic', 'TaxCrossborder'];

    /**
     * @return array{is_certified: false, structure_basis: string, schema_namespace: string, schema_validated: false, current_certification_basis: string, domains_built: list<string>, domains_not_built: list<string>, missing_for_certification: list<string>}
     */
    public static function toArray(): array
    {
        return [
            'is_certified' => false,
            'structure_basis' => self::StructureBasis,
            'schema_namespace' => self::SchemaNamespace,
            'schema_validated' => false,
            'current_certification_basis' => self::CurrentCertificationBasis,
            'domains_built' => self::Domains,
            'domains_not_built' => self::DomainsNotBuilt,
            'missing_for_certification' => [
                'Migrare la struttura alle direttive ELM 6.0, con gli allegati pubblicati e quelli attesi nel 2026.',
                'Ottenere gli XSD della versione di riferimento e validare ogni dichiarazione contro di essi.',
                'Costruire i domini mancanti: LAINF, LAINFC, IGM, LPP, AF, certificato di salario, statistica, frontalieri.',
                'Allineare i calcoli alle direttive sul trattamento dei dati salariali, incluso l\'allegato 1 sull\'imposta alla fonte.',
                'Implementare il trasmettitore secondo le direttive tecniche per i mittenti, con il distributore Swissdec.',
                'Superare i casi di test pubblicati da Swissdec e la procedura formale di certificazione.',
                'Completare i dati ELM-QST assenti: coniuge o partner, confessione, altre attività, famiglie monoparentali, numero DPI per cantone.',
            ],
        ];
    }
}
