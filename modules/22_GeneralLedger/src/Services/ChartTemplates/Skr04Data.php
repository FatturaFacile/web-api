<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\ChartTemplates;

/**
 * DATEV Standardkontenrahmen SKR04 (balance sheet structure) at the level of the principal accounts, with the VAT,
 * payroll and tax accounts every German company uses. Rows follow the layout of the catalog.
 */
final class Skr04Data
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: ?string, 5: ?string, 6: ?string}>
     */
    public static function accounts(): array
    {
        return [
            ['0', 'Anlagevermoegen', 'asset', 'debit', null, null, null],
            ['0135', 'EDV-Software', 'asset', 'debit', '0', 'non_current_asset', null],
            ['0200', 'Grundstuecke und Bauten', 'asset', 'debit', '0', 'non_current_asset', null],
            ['0440', 'Maschinen', 'asset', 'debit', '0', 'non_current_asset', null],
            ['0520', 'Pkw', 'asset', 'debit', '0', 'non_current_asset', null],
            ['0620', 'Werkzeuge', 'asset', 'debit', '0', 'non_current_asset', null],
            ['0650', 'Buroeinrichtung', 'asset', 'debit', '0', 'non_current_asset', null],
            ['0670', 'Geringwertige Wirtschaftsgueter', 'asset', 'debit', '0', 'non_current_asset', null],
            ['1', 'Umlaufvermoegen', 'asset', 'debit', null, null, null],
            ['1000', 'Kasse', 'asset', 'debit', '1', 'cash', null],
            ['1140', 'Bestand Waren', 'asset', 'debit', '1', 'inventory', 'inventory'],
            ['1200', 'Bank', 'asset', 'debit', '1', 'cash', 'bank'],
            ['1360', 'Geldtransit', 'asset', 'debit', '1', 'cash', null],
            ['1400', 'Forderungen aus Lieferungen und Leistungen', 'asset', 'debit', '1', 'receivable', 'customer'],
            ['1401', 'Abziehbare Vorsteuer 7 %', 'asset', 'debit', '1', 'other_current_asset', null],
            ['1406', 'Abziehbare Vorsteuer 19 %', 'asset', 'debit', '1', 'other_current_asset', null],
            ['1407', 'Abziehbare Vorsteuer nach Paragraph 13b UStG 19 %', 'asset', 'debit', '1', 'other_current_asset', null],
            ['1590', 'Durchlaufende Posten', 'asset', 'debit', '1', 'other_current_asset', null],
            ['1900', 'Aktive Rechnungsabgrenzung', 'asset', 'debit', '1', 'other_current_asset', null],
            ['2', 'Eigenkapital', 'equity', 'credit', null, null, null],
            ['2100', 'Privatentnahmen', 'equity', 'debit', '2', 'equity', null],
            ['2180', 'Privateinlagen', 'equity', 'credit', '2', 'equity', null],
            ['2900', 'Gezeichnetes Kapital', 'equity', 'credit', '2', 'equity', null],
            ['2910', 'Kapitalruecklage', 'equity', 'credit', '2', 'equity', null],
            ['2930', 'Gewinnruecklagen', 'equity', 'credit', '2', 'equity', null],
            ['2970', 'Gewinnvortrag vor Verwendung', 'equity', 'credit', '2', 'equity', null],
            ['2978', 'Verlustvortrag vor Verwendung', 'equity', 'debit', '2', 'equity', null],
            ['3', 'Fremdkapital', 'liability', 'credit', null, null, null],
            ['3040', 'Steuerrueckstellungen', 'liability', 'credit', '3', 'non_current_liability', null],
            ['3070', 'Sonstige Rueckstellungen', 'liability', 'credit', '3', 'non_current_liability', null],
            ['3150', 'Verbindlichkeiten gegenueber Kreditinstituten', 'liability', 'credit', '3', 'non_current_liability', null],
            ['3300', 'Verbindlichkeiten aus Lieferungen und Leistungen', 'liability', 'credit', '3', 'current_liability', 'supplier'],
            ['3500', 'Sonstige Verbindlichkeiten', 'liability', 'credit', '3', 'current_liability', null],
            ['3720', 'Verbindlichkeiten aus Lohn und Gehalt', 'liability', 'credit', '3', 'current_liability', null],
            ['3730', 'Verbindlichkeiten aus Lohn- und Kirchensteuer', 'liability', 'credit', '3', 'current_liability', null],
            ['3740', 'Verbindlichkeiten im Rahmen der sozialen Sicherheit', 'liability', 'credit', '3', 'current_liability', null],
            ['3801', 'Umsatzsteuer 7 %', 'liability', 'credit', '3', 'current_liability', null],
            ['3806', 'Umsatzsteuer 19 %', 'liability', 'credit', '3', 'current_liability', null],
            ['3820', 'Umsatzsteuer-Vorauszahlung', 'liability', 'credit', '3', 'current_liability', null],
            ['3835', 'Umsatzsteuer nach Paragraph 13b UStG 19 %', 'liability', 'credit', '3', 'current_liability', null],
            ['4', 'Betriebliche Ertraege', 'revenue', 'credit', null, null, null],
            ['4120', 'Steuerfreie Umsaetze nach Paragraph 4 Nr. 1a UStG (Ausfuhr)', 'revenue', 'credit', '4', null, null],
            ['4125', 'Steuerfreie innergemeinschaftliche Lieferungen', 'revenue', 'credit', '4', null, null],
            ['4300', 'Erloese 7 % Umsatzsteuer', 'revenue', 'credit', '4', null, null],
            ['4337', 'Erloese nach Paragraph 13b UStG (Leistungsempfaenger schuldet die Steuer)', 'revenue', 'credit', '4', null, null],
            ['4400', 'Erloese 19 % Umsatzsteuer', 'revenue', 'credit', '4', null, null],
            ['4700', 'Erloesschmaelerungen', 'revenue', 'debit', '4', null, null],
            ['4736', 'Gewaehrte Skonti 19 % Umsatzsteuer', 'revenue', 'debit', '4', null, null],
            ['4830', 'Sonstige betriebliche Ertraege', 'revenue', 'credit', '4', null, null],
            ['4840', 'Ertraege aus Kursdifferenzen', 'revenue', 'credit', '4', null, null],
            ['5', 'Materialaufwand', 'expense', 'debit', null, null, null],
            ['5200', 'Wareneingang', 'expense', 'debit', '5', null, null],
            ['5300', 'Wareneingang 7 % Vorsteuer', 'expense', 'debit', '5', null, null],
            ['5400', 'Wareneingang 19 % Vorsteuer', 'expense', 'debit', '5', null, null],
            ['5425', 'Innergemeinschaftlicher Erwerb 19 % Vorsteuer und 19 % Umsatzsteuer', 'expense', 'debit', '5', null, null],
            ['5550', 'Steuerfreier innergemeinschaftlicher Erwerb', 'expense', 'debit', '5', null, null],
            ['5700', 'Nachlaesse', 'expense', 'credit', '5', null, null],
            ['5800', 'Bezugsnebenkosten', 'expense', 'debit', '5', null, null],
            ['6', 'Betriebliche Aufwendungen', 'expense', 'debit', null, null, null],
            ['6010', 'Loehne', 'expense', 'debit', '6', null, null],
            ['6020', 'Gehaelter', 'expense', 'debit', '6', null, null],
            ['6110', 'Gesetzliche soziale Aufwendungen', 'expense', 'debit', '6', null, null],
            ['6220', 'Abschreibungen auf Sachanlagen', 'expense', 'debit', '6', 'non_cash_expense', null],
            ['6260', 'Sofortabschreibung geringwertiger Wirtschaftsgueter', 'expense', 'debit', '6', 'non_cash_expense', null],
            ['6310', 'Miete', 'expense', 'debit', '6', null, null],
            ['6325', 'Gas, Strom, Wasser', 'expense', 'debit', '6', null, null],
            ['6400', 'Versicherungen', 'expense', 'debit', '6', null, null],
            ['6530', 'Laufende Kfz-Betriebskosten', 'expense', 'debit', '6', null, null],
            ['6600', 'Werbekosten', 'expense', 'debit', '6', null, null],
            ['6640', 'Bewirtungskosten', 'expense', 'debit', '6', null, null],
            ['6650', 'Reisekosten Arbeitnehmer', 'expense', 'debit', '6', null, null],
            ['6800', 'Porto', 'expense', 'debit', '6', null, null],
            ['6805', 'Telefon', 'expense', 'debit', '6', null, null],
            ['6815', 'Buerobedarf', 'expense', 'debit', '6', null, null],
            ['6825', 'Rechts- und Beratungskosten', 'expense', 'debit', '6', null, null],
            ['6827', 'Abschluss- und Pruefungskosten', 'expense', 'debit', '6', null, null],
            ['6855', 'Nebenkosten des Geldverkehrs', 'expense', 'debit', '6', null, null],
            ['6880', 'Aufwendungen aus Kursdifferenzen', 'expense', 'debit', '6', null, null],
            ['7', 'Weitere Ertraege und Aufwendungen', 'expense', 'debit', null, null, null],
            ['7100', 'Sonstige Zinsen und aehnliche Ertraege', 'revenue', 'credit', '7', null, null],
            ['7300', 'Zinsen und aehnliche Aufwendungen', 'expense', 'debit', '7', null, null],
            ['7600', 'Koerperschaftsteuer', 'expense', 'debit', '7', null, null],
            ['7610', 'Gewerbesteuer', 'expense', 'debit', '7', null, null],
        ];
    }
}
