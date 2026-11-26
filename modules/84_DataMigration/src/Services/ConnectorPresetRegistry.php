<?php

declare(strict_types=1);

namespace Modules\DataMigration\Services;

use Modules\DataMigration\Domain\Enums\MigrationSourceSystem;
use Modules\DataMigration\Domain\Enums\MigrationTargetEntity;

/**
 * Built-in connector profiles, header aliases, default value transformations, and preset templates
 * for all 11 supported source ERP systems (consolidating #279 and #25-#39).
 */
final class ConnectorPresetRegistry
{
    /**
     * @return list<array<string, mixed>>
     */
    public function connectorsMetadata(): array
    {
        return array_map(
            fn (MigrationSourceSystem $source): array => [
                'code' => $source->value,
                'name_key' => 'dataMigration.connector.'.$source->value.'.name',
                'description_key' => 'dataMigration.connector.'.$source->value.'.description',
                'jurisdiction' => $source->jurisdiction(),
                'default_currency' => $source->defaultCurrency(),
                'supported_extensions' => $source->supportedExtensions(),
                'default_entities' => array_map(
                    static fn (MigrationTargetEntity $entity): string => $entity->value,
                    $source->defaultEntities(),
                ),
                'default_transformations' => $this->defaultTransformations($source),
            ],
            MigrationSourceSystem::cases(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function entitySchemas(): array
    {
        return array_map(
            static fn (MigrationTargetEntity $entity): array => [
                'code' => $entity->value,
                'label_key' => 'dataMigration.entity.'.$entity->value,
                'fields' => array_map(
                    static fn (array $field): array => [
                        ...$field,
                        'label_key' => 'dataMigration.field.'.$field['field'],
                    ],
                    $entity->schemaFields(),
                ),
            ],
            MigrationTargetEntity::cases(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultTransformations(MigrationSourceSystem $source): array
    {
        return match ($source) {
            MigrationSourceSystem::Bexio,
            MigrationSourceSystem::Abacus,
            MigrationSourceSystem::BananaAccounting => [
                'date_format' => 'd.m.Y',
                'decimal_separator' => '.',
                'thousand_separator' => "'",
                'currency_default' => 'CHF',
                'debit_credit_sign_rule' => 'explicit_columns',
                'vat_code_map' => ['8.1' => 'CH-STD-81', '2.6' => 'CH-RED-26', '3.8' => 'CH-ACC-38', '0' => 'CH-EXEMPT'],
                'uom_map' => ['Stk' => 'PZ', 'Std' => 'H', 'Pcs' => 'PZ'],
            ],
            MigrationSourceSystem::FattureInCloud,
            MigrationSourceSystem::DipendentiInCloud,
            MigrationSourceSystem::DaneaEasyfatt,
            MigrationSourceSystem::TeamSystem,
            MigrationSourceSystem::Zucchetti,
            MigrationSourceSystem::FatturaPaXmlZipArchive => [
                'date_format' => 'd/m/Y',
                'decimal_separator' => ',',
                'thousand_separator' => '.',
                'currency_default' => 'EUR',
                'debit_credit_sign_rule' => 'explicit_columns',
                'vat_code_map' => ['22' => 'IT-STD-22', '10' => 'IT-RED-10', '4' => 'IT-MIN-4', '0' => 'IT-N0'],
                'uom_map' => ['NR' => 'PZ', 'PZ' => 'PZ', 'ORE' => 'H'],
            ],
            MigrationSourceSystem::Odoo,
            MigrationSourceSystem::GenericCsvXlsx => [
                'date_format' => 'Y-m-d',
                'decimal_separator' => '.',
                'thousand_separator' => '',
                'currency_default' => 'EUR',
                'debit_credit_sign_rule' => 'explicit_columns',
                'vat_code_map' => [],
                'uom_map' => [],
            ],
        };
    }

    /**
     * Normalized header aliases per target field across IT, DE, FR, EN and ERP-specific exports.
     *
     * @return array<string, list<string>>
     */
    public function fieldAliases(MigrationTargetEntity $entity, ?MigrationSourceSystem $source = null): array
    {
        $common = match ($entity) {
            MigrationTargetEntity::ChartOfAccounts => [
                'code' => ['code', 'account_code', 'conto', 'codice_conto', 'numero_conto', 'kto', 'konto', 'kontonummer', 'account', 'nr'],
                'name' => ['name', 'account_name', 'descrizione', 'nome_conto', 'bezeichnung', 'kontobezeichnung', 'libelle'],
                'type' => ['type', 'account_type', 'tipo', 'sezione', 'bclass', 'klasse', 'categorie', 'gr'],
                'currency' => ['currency', 'valuta', 'divisa', 'waehrung', 'monnaie'],
                'parent_code' => ['parent_code', 'mastro', 'gruppo', 'gruppe', 'parent', 'oberkonto'],
                'standard' => ['standard', 'piano_conti', 'chart_standard'],
            ],
            MigrationTargetEntity::Customers, MigrationTargetEntity::Vendors => [
                'company_name' => ['company_name', 'ragione_sociale', 'denominazione', 'name', 'firma', 'company', 'nome_azienda', 'nom', 'raison_sociale', 'client_name', 'vendor_name'],
                'country' => ['country', 'paese', 'nazione', 'land', 'pays', 'country_code', 'id_paese'],
                'vat_number' => ['vat_number', 'partita_iva', 'piva', 'p_iva', 'vat', 'mwst_nr', 'id_fiscale_iva', 'tva'],
                'fiscal_code' => ['fiscal_code', 'codice_fiscale', 'cf', 'cod_fiscale', 'tax_code'],
                'uid_number' => ['uid_number', 'uid', 'idi', 'che_uid', 'uid_nummer', 'ide'],
                'email' => ['email', 'e_mail', 'mail', 'posta_elettronica', 'email_address'],
                'phone' => ['phone', 'telefono', 'tel', 'telefon', 'mobile'],
                'iban' => ['iban', 'conto_bancario', 'bank_iban'],
                'qr_iban' => ['qr_iban', 'qriban', 'swiss_qr_iban'],
                'sdi_code' => ['sdi_code', 'codice_sdi', 'codice_destinatario', 'sdi'],
                'pec' => ['pec', 'pec_email'],
                'street' => ['street', 'indirizzo', 'via', 'address', 'strasse', 'rue', 'adresse'],
                'postal_code' => ['postal_code', 'cap', 'plz', 'zip', 'npa', 'code_postal'],
                'city' => ['city', 'citta', 'comune', 'ort', 'ville', 'localita'],
                'payment_terms_days' => ['payment_terms_days', 'giorni_pagamento', 'payment_terms', 'zahlungsfrist', 'delai_paiement'],
            ],
            MigrationTargetEntity::Products => [
                'sku' => ['sku', 'codice', 'codice_articolo', 'code', 'default_code', 'artikelnummer', 'reference', 'cod'],
                'name' => ['name', 'nome', 'articolo', 'bezeichnung', 'product_name', 'nom', 'titolo'],
                'description' => ['description', 'descrizione', 'beschreibung', 'notes'],
                'unit_measure' => ['unit_measure', 'um', 'unita_misura', 'uom', 'einheit', 'unite'],
                'sale_price' => ['sale_price', 'prezzo_vendita', 'prezzo', 'list_price', 'verkaufspreis', 'prix_vente', 'price'],
                'cost_price' => ['cost_price', 'prezzo_acquisto', 'costo', 'standard_price', 'einkaufspreis', 'prix_achat'],
                'tax_rate' => ['tax_rate', 'aliquota_iva', 'iva', 'mwst', 'tva', 'vat_rate'],
                'barcode' => ['barcode', 'ean', 'gtin', 'codice_barre'],
            ],
            MigrationTargetEntity::Employees => [
                'email' => ['email', 'work_email', 'email_aziendale', 'e_mail', 'mail'],
                'first_name' => ['first_name', 'nome', 'vorname', 'prenom'],
                'last_name' => ['last_name', 'cognome', 'nachname', 'nom'],
                'social_security_number' => ['social_security_number', 'avs', 'ahv', 'ahv_nummer', 'nss', 'numero_avs'],
                'fiscal_code' => ['fiscal_code', 'codice_fiscale', 'cf'],
                'job_title' => ['job_title', 'qualifica', 'mansione', 'stelle', 'funktion', 'poste'],
                'department' => ['department', 'reparto', 'abteilung', 'departement'],
                'hire_date' => ['hire_date', 'data_assunzione', 'eintrittsdatum', 'date_entree'],
                'base_salary' => ['base_salary', 'stipendio_base', 'ral', 'lohn', 'bruttolohn', 'salaire_brut'],
                'currency' => ['currency', 'valuta', 'waehrung', 'monnaie'],
                'iban' => ['iban', 'conto_stipendio', 'bank_iban'],
            ],
            MigrationTargetEntity::OpeningGlBalances => [
                'entry_date' => ['entry_date', 'data', 'data_registrazione', 'datum', 'date', 'posting_date'],
                'account_code' => ['account_code', 'conto', 'codice_conto', 'konto', 'kt_soll', 'account'],
                'description' => ['description', 'descrizione', 'causale', 'buchungstext', 'libelle', 'text'],
                'debit_amount' => ['debit_amount', 'dare', 'soll', 'debit', 'importo_dare'],
                'credit_amount' => ['credit_amount', 'avere', 'haben', 'credit', 'importo_avere'],
                'currency' => ['currency', 'valuta', 'waehrung', 'monnaie'],
                'document_number' => ['document_number', 'numero_documento', 'beleg', 'belegnummer', 'doc', 'piece'],
            ],
            MigrationTargetEntity::OpenArApItems => [
                'ledger_side' => ['ledger_side', 'tipo_partita', 'side', 'ar_ap', 'sezionale'],
                'counterparty_name' => ['counterparty_name', 'controparte', 'ragione_sociale', 'cliente_fornitore', 'partner', 'name'],
                'counterparty_tax_id' => ['counterparty_tax_id', 'partita_iva', 'piva', 'uid', 'tax_id'],
                'document_number' => ['document_number', 'numero_documento', 'numero_fattura', 'rechnungsnummer', 'invoice_number'],
                'posting_date' => ['posting_date', 'data_documento', 'data_fattura', 'rechnungsdatum', 'date'],
                'due_date' => ['due_date', 'data_scadenza', 'scadenza', 'faelligkeit', 'echeance'],
                'original_amount' => ['original_amount', 'importo_originale', 'totale_documento', 'betrag', 'amount'],
                'open_amount' => ['open_amount', 'importo_aperto', 'residuo', 'offener_betrag', 'balance'],
                'currency' => ['currency', 'valuta', 'waehrung', 'monnaie'],
            ],
            MigrationTargetEntity::HistoricalInvoices => [
                'direction' => ['direction', 'direzione', 'tipo_ciclo', 'active_passive'],
                'invoice_number' => ['invoice_number', 'numero', 'numero_fattura', 'rechnungsnummer', 'number', 'nr'],
                'date' => ['date', 'data', 'data_fattura', 'rechnungsdatum', 'invoice_date'],
                'due_date' => ['due_date', 'data_scadenza', 'scadenza', 'faelligkeit'],
                'counterparty_name' => ['counterparty_name', 'cliente', 'fornitore', 'ragione_sociale', 'partner_name', 'name'],
                'counterparty_vat' => ['counterparty_vat', 'partita_iva', 'piva', 'uid', 'vat_number'],
                'counterparty_country' => ['counterparty_country', 'paese', 'country'],
                'currency' => ['currency', 'valuta', 'divisa', 'waehrung'],
                'total_net' => ['total_net', 'imponibile', 'netto', 'net_amount', 'amount_untaxed'],
                'total_tax' => ['total_tax', 'iva', 'imposta', 'mwst', 'tax_amount', 'amount_tax'],
                'total_gross' => ['total_gross', 'totale', 'lordo', 'brutto', 'total_amount', 'amount_total'],
                'line_description' => ['line_description', 'descrizione_riga', 'descrizione', 'oggetto'],
            ],
        };

        if ($source === MigrationSourceSystem::BananaAccounting && $entity === MigrationTargetEntity::OpeningGlBalances) {
            $common['account_code'][] = 'conto_dare';
            $common['debit_amount'][] = 'importo';
        }

        return $common;
    }

    /**
     * System preset mapping definitions for seeding `migration_field_mapping_templates`.
     *
     * @return list<array{name: string, source_system: MigrationSourceSystem, entity_type: MigrationTargetEntity, column_mappings_json: array<string, mixed>, value_transformations_json: array<string, mixed>}>
     */
    public function systemPresets(): array
    {
        $presets = [];

        foreach (MigrationSourceSystem::cases() as $source) {
            $transformations = $this->defaultTransformations($source);

            foreach ($source->defaultEntities() as $entity) {
                $aliases = $this->fieldAliases($entity, $source);
                $mappings = [];

                foreach ($entity->schemaFields() as $fieldDef) {
                    $field = $fieldDef['field'];
                    $mappings[$field] = [
                        'source_column' => $aliases[$field][0] ?? $field,
                        'required' => $fieldDef['required'],
                        'default_value' => null,
                        'confidence' => 1.0,
                    ];
                }

                $presets[] = [
                    'name' => $source->value.' — '.$entity->value,
                    'source_system' => $source,
                    'entity_type' => $entity,
                    'column_mappings_json' => $mappings,
                    'value_transformations_json' => $transformations,
                ];
            }
        }

        return $presets;
    }
}
