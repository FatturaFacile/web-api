<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\ChartTemplates;

/**
 * DATEV Standardkontenrahmen SKR03 (process structure) at the level of the principal accounts, with the VAT, payroll
 * and tax accounts every German company uses. Rows follow the layout of the catalog. The classes 0, 1 and 2 mix
 * account types, so their accounts have no grouping parent.
 */
final class Skr03Data
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: ?string, 5: ?string, 6: ?string}>
     */
    public static function accounts(): array
    {
        return [
            ['0027', 'EDV-Software', 'asset', 'debit', null, 'non_current_asset', null],
            ['0050', 'Grundstuecke und Bauten', 'asset', 'debit', null, 'non_current_asset', null],
            ['0200', 'Technische Anlagen und Maschinen', 'asset', 'debit', null, 'non_current_asset', null],
            ['0320', 'Pkw', 'asset', 'debit', null, 'non_current_asset', null],
            ['0400', 'Betriebsausstattung', 'asset', 'debit', null, 'non_current_asset', null],
            ['0410', 'Geschaeftsausstattung', 'asset', 'debit', null, 'non_current_asset', null],
            ['0420', 'Buroeinrichtung', 'asset', 'debit', null, 'non_current_asset', null],
            ['0480', 'Geringwertige Wirtschaftsgueter', 'asset', 'debit', null, 'non_current_asset', null],
            ['0630', 'Verbindlichkeiten gegenueber Kreditinstituten', 'liability', 'credit', null, 'non_current_liability', null],
            ['0800', 'Gezeichnetes Kapital', 'equity', 'credit', null, 'equity', null],
            ['0840', 'Kapitalruecklage', 'equity', 'credit', null, 'equity', null],
            ['0850', 'Gewinnruecklagen', 'equity', 'credit', null, 'equity', null],
            ['0860', 'Gewinnvortrag vor Verwendung', 'equity', 'credit', null, 'equity', null],
            ['0868', 'Verlustvortrag vor Verwendung', 'equity', 'debit', null, 'equity', null],
            ['0955', 'Steuerrueckstellungen', 'liability', 'credit', null, 'non_current_liability', null],
            ['0970', 'Sonstige Rueckstellungen', 'liability', 'credit', null, 'non_current_liability', null],
            ['0990', 'Aktive Rechnungsabgrenzung', 'asset', 'debit', null, 'other_current_asset', null],
            ['1000', 'Kasse', 'asset', 'debit', null, 'cash', null],
            ['1200', 'Bank', 'asset', 'debit', null, 'cash', 'bank'],
            ['1360', 'Geldtransit', 'asset', 'debit', null, 'cash', null],
            ['1400', 'Forderungen aus Lieferungen und Leistungen', 'asset', 'debit', null, 'receivable', 'customer'],
            ['1571', 'Abziehbare Vorsteuer 7 %', 'asset', 'debit', null, 'other_current_asset', null],
            ['1576', 'Abziehbare Vorsteuer 19 %', 'asset', 'debit', null, 'other_current_asset', null],
            ['1577', 'Abziehbare Vorsteuer nach Paragraph 13b UStG 19 %', 'asset', 'debit', null, 'other_current_asset', null],
            ['1590', 'Durchlaufende Posten', 'asset', 'debit', null, 'other_current_asset', null],
            ['1600', 'Verbindlichkeiten aus Lieferungen und Leistungen', 'liability', 'credit', null, 'current_liability', 'supplier'],
            ['1700', 'Sonstige Verbindlichkeiten', 'liability', 'credit', null, 'current_liability', null],
            ['1740', 'Verbindlichkeiten aus Lohn und Gehalt', 'liability', 'credit', null, 'current_liability', null],
            ['1741', 'Verbindlichkeiten aus Lohn- und Kirchensteuer', 'liability', 'credit', null, 'current_liability', null],
            ['1742', 'Verbindlichkeiten im Rahmen der sozialen Sicherheit', 'liability', 'credit', null, 'current_liability', null],
            ['1771', 'Umsatzsteuer 7 %', 'liability', 'credit', null, 'current_liability', null],
            ['1776', 'Umsatzsteuer 19 %', 'liability', 'credit', null, 'current_liability', null],
            ['1780', 'Umsatzsteuer-Vorauszahlung', 'liability', 'credit', null, 'current_liability', null],
            ['1787', 'Umsatzsteuer nach Paragraph 13b UStG 19 %', 'liability', 'credit', null, 'current_liability', null],
            ['1800', 'Privatentnahmen', 'equity', 'debit', null, 'equity', null],
            ['1890', 'Privateinlagen', 'equity', 'credit', null, 'equity', null],
            ['2100', 'Zinsen und aehnliche Aufwendungen', 'expense', 'debit', null, null, null],
            ['2150', 'Aufwendungen aus Kursdifferenzen', 'expense', 'debit', null, null, null],
            ['2650', 'Sonstige Zinsen und aehnliche Ertraege', 'revenue', 'credit', null, null, null],
            ['2660', 'Ertraege aus Kursdifferenzen', 'revenue', 'credit', null, null, null],
            ['2700', 'Sonstige betriebliche Ertraege', 'revenue', 'credit', null, null, null],
            ['3', 'Wareneingang und Bestandsveraenderungen', 'expense', 'debit', null, null, null],
            ['3200', 'Wareneingang', 'expense', 'debit', '3', null, null],
            ['3300', 'Wareneingang 7 % Vorsteuer', 'expense', 'debit', '3', null, null],
            ['3400', 'Wareneingang 19 % Vorsteuer', 'expense', 'debit', '3', null, null],
            ['3425', 'Innergemeinschaftlicher Erwerb 19 % Vorsteuer und 19 % Umsatzsteuer', 'expense', 'debit', '3', null, null],
            ['3550', 'Steuerfreier innergemeinschaftlicher Erwerb', 'expense', 'debit', '3', null, null],
            ['3700', 'Nachlaesse', 'expense', 'credit', '3', null, null],
            ['3800', 'Bezugsnebenkosten', 'expense', 'debit', '3', null, null],
            ['3980', 'Bestand Waren', 'asset', 'debit', null, 'inventory', 'inventory'],
            ['4', 'Betriebliche Aufwendungen', 'expense', 'debit', null, null, null],
            ['4100', 'Loehne und Gehaelter', 'expense', 'debit', '4', null, null],
            ['4120', 'Gehaelter', 'expense', 'debit', '4', null, null],
            ['4130', 'Gesetzliche soziale Aufwendungen', 'expense', 'debit', '4', null, null],
            ['4210', 'Miete', 'expense', 'debit', '4', null, null],
            ['4240', 'Gas, Strom, Wasser', 'expense', 'debit', '4', null, null],
            ['4320', 'Gewerbesteuer', 'expense', 'debit', '4', null, null],
            ['4360', 'Versicherungen', 'expense', 'debit', '4', null, null],
            ['4530', 'Laufende Kfz-Betriebskosten', 'expense', 'debit', '4', null, null],
            ['4610', 'Werbekosten', 'expense', 'debit', '4', null, null],
            ['4650', 'Bewirtungskosten', 'expense', 'debit', '4', null, null],
            ['4660', 'Reisekosten Arbeitnehmer', 'expense', 'debit', '4', null, null],
            ['4806', 'Wartungskosten fuer Hard- und Software', 'expense', 'debit', '4', null, null],
            ['4830', 'Abschreibungen auf Sachanlagen', 'expense', 'debit', '4', 'non_cash_expense', null],
            ['4855', 'Sofortabschreibung geringwertiger Wirtschaftsgueter', 'expense', 'debit', '4', 'non_cash_expense', null],
            ['4910', 'Porto', 'expense', 'debit', '4', null, null],
            ['4920', 'Telefon', 'expense', 'debit', '4', null, null],
            ['4930', 'Buerobedarf', 'expense', 'debit', '4', null, null],
            ['4950', 'Rechts- und Beratungskosten', 'expense', 'debit', '4', null, null],
            ['4957', 'Abschluss- und Pruefungskosten', 'expense', 'debit', '4', null, null],
            ['4970', 'Nebenkosten des Geldverkehrs', 'expense', 'debit', '4', null, null],
            ['8', 'Erloese', 'revenue', 'credit', null, null, null],
            ['8120', 'Steuerfreie Umsaetze nach Paragraph 4 Nr. 1a UStG (Ausfuhr)', 'revenue', 'credit', '8', null, null],
            ['8125', 'Steuerfreie innergemeinschaftliche Lieferungen', 'revenue', 'credit', '8', null, null],
            ['8300', 'Erloese 7 % Umsatzsteuer', 'revenue', 'credit', '8', null, null],
            ['8337', 'Erloese nach Paragraph 13b UStG (Leistungsempfaenger schuldet die Steuer)', 'revenue', 'credit', '8', null, null],
            ['8400', 'Erloese 19 % Umsatzsteuer', 'revenue', 'credit', '8', null, null],
            ['8700', 'Erloesschmaelerungen', 'revenue', 'debit', '8', null, null],
            ['8736', 'Gewaehrte Skonti 19 % Umsatzsteuer', 'revenue', 'debit', '8', null, null],
            ['8900', 'Unentgeltliche Wertabgaben', 'revenue', 'credit', '8', null, null],
        ];
    }
}
