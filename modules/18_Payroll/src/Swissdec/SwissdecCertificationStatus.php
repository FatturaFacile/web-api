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

    /**
     * The schema of that overview. The official XSD of Swissdec was not available: SwissdecXsdValidator checks the
     * structure against a schema written by hand from the public field overview, which is an internal consistency check
     * and not a validation against the official schema, so `schema_validated` stays false.
     */
    public const string SchemaNamespace = 'http://www.swissdec.ch/schema/sd/20200220/SalaryDeclaration';

    public const string Elm60SchemaNamespace = 'http://www.swissdec.ch/schema/sd/20260306/SalaryDeclaration';

    public const string CurrentCertificationBasis = 'ELM 6.0 (20260306)';

    /** @var list<string> */
    public const array Domains = [
        'AHV-AVS',
        'UVG-LAA',
        'UVGZ-LAAC',
        'KTG-AMC',
        'BVG-LPP',
        'FAK-CAF',
        'TaxAtSource',
        'Tax',
        'Statistic',
        'TaxCrossborder',
    ];

    /** @var list<string> */
    public const array DomainsNotBuilt = [];

    /**
     * @return array{is_certified: false, structure_basis: string, schema_namespace: string, schema_validated: bool, current_certification_basis: string, domains_built: list<string>, domains_not_built: list<string>, missing_for_certification: list<string>}
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
                'Superare i casi di test formali pubblicati da Swissdec e la procedura ufficiale di certificazione presso il distributore.',
            ],
        ];
    }
}
