<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\ChartTemplates;

/**
 * Plan General de Contabilidad (PGC, Real Decreto 1514/2007) at the level of the principal accounts, with the VAT,
 * payroll and tax accounts every Spanish company uses. Rows follow the layout of the catalog.
 */
final class SpanishPgcData
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: ?string, 5: ?string, 6: ?string}>
     */
    public static function accounts(): array
    {
        return [
            ['1', 'Financiacion basica', 'equity', 'credit', null, null, null],
            ['100', 'Capital social', 'equity', 'credit', '1', 'equity', null],
            ['112', 'Reserva legal', 'equity', 'credit', '1', 'equity', null],
            ['113', 'Reservas voluntarias', 'equity', 'credit', '1', 'equity', null],
            ['120', 'Remanente', 'equity', 'credit', '1', 'equity', null],
            ['121', 'Resultados negativos de ejercicios anteriores', 'equity', 'debit', '1', 'equity', null],
            ['129', 'Resultado del ejercicio', 'equity', 'credit', '1', 'equity', null],
            ['170', 'Deudas a largo plazo con entidades de credito', 'liability', 'credit', '1', 'non_current_liability', null],
            ['2', 'Activo no corriente', 'asset', 'debit', null, null, null],
            ['203', 'Propiedad industrial', 'asset', 'debit', '2', 'non_current_asset', null],
            ['206', 'Aplicaciones informaticas', 'asset', 'debit', '2', 'non_current_asset', null],
            ['210', 'Terrenos y bienes naturales', 'asset', 'debit', '2', 'non_current_asset', null],
            ['211', 'Construcciones', 'asset', 'debit', '2', 'non_current_asset', null],
            ['213', 'Maquinaria', 'asset', 'debit', '2', 'non_current_asset', null],
            ['216', 'Mobiliario', 'asset', 'debit', '2', 'non_current_asset', null],
            ['217', 'Equipos para procesos de informacion', 'asset', 'debit', '2', 'non_current_asset', null],
            ['218', 'Elementos de transporte', 'asset', 'debit', '2', 'non_current_asset', null],
            ['280', 'Amortizacion acumulada del inmovilizado intangible', 'asset', 'credit', '2', 'non_current_asset', null],
            ['281', 'Amortizacion acumulada del inmovilizado material', 'asset', 'credit', '2', 'non_current_asset', null],
            ['3', 'Existencias', 'asset', 'debit', null, null, null],
            ['300', 'Mercaderias', 'asset', 'debit', '3', 'inventory', 'inventory'],
            ['310', 'Materias primas', 'asset', 'debit', '3', 'inventory', 'inventory'],
            ['350', 'Productos terminados', 'asset', 'debit', '3', 'inventory', 'inventory'],
            ['390', 'Deterioro de valor de las mercaderias', 'asset', 'credit', '3', 'inventory', null],
            ['4', 'Acreedores y deudores por operaciones comerciales', 'liability', 'credit', null, null, null],
            ['400', 'Proveedores', 'liability', 'credit', '4', 'current_liability', 'supplier'],
            ['407', 'Anticipos a proveedores', 'asset', 'debit', '4', 'other_current_asset', null],
            ['410', 'Acreedores por prestaciones de servicios', 'liability', 'credit', '4', 'current_liability', 'supplier'],
            ['430', 'Clientes', 'asset', 'debit', '4', 'receivable', 'customer'],
            ['436', 'Clientes de dudoso cobro', 'asset', 'debit', '4', 'receivable', null],
            ['438', 'Anticipos de clientes', 'liability', 'credit', '4', 'current_liability', null],
            ['465', 'Remuneraciones pendientes de pago', 'liability', 'credit', '4', 'current_liability', null],
            ['4700', 'Hacienda Publica, deudora por IVA', 'asset', 'debit', '4', 'other_current_asset', null],
            ['472', 'Hacienda Publica, IVA soportado', 'asset', 'debit', '4', 'other_current_asset', null],
            ['4721', 'IVA soportado en adquisiciones intracomunitarias', 'asset', 'debit', '472', 'other_current_asset', null],
            ['473', 'Hacienda Publica, retenciones y pagos a cuenta', 'asset', 'debit', '4', 'other_current_asset', null],
            ['475', 'Hacienda Publica, acreedora por conceptos fiscales', 'liability', 'credit', '4', 'current_liability', null],
            ['4750', 'Hacienda Publica, acreedora por IVA', 'liability', 'credit', '475', 'current_liability', null],
            ['4751', 'Hacienda Publica, acreedora por retenciones practicadas', 'liability', 'credit', '475', 'current_liability', null],
            ['476', 'Organismos de la Seguridad Social, acreedores', 'liability', 'credit', '4', 'current_liability', null],
            ['477', 'Hacienda Publica, IVA repercutido', 'liability', 'credit', '4', 'current_liability', null],
            ['480', 'Gastos anticipados', 'asset', 'debit', '4', 'other_current_asset', null],
            ['485', 'Ingresos anticipados', 'liability', 'credit', '4', 'current_liability', null],
            ['490', 'Deterioro de valor de creditos por operaciones comerciales', 'asset', 'credit', '4', 'receivable', null],
            ['5', 'Cuentas financieras', 'asset', 'debit', null, null, null],
            ['520', 'Deudas a corto plazo con entidades de credito', 'liability', 'credit', '5', 'current_liability', null],
            ['551', 'Cuenta corriente con socios y administradores', 'liability', 'credit', '5', 'current_liability', null],
            ['570', 'Caja, euros', 'asset', 'debit', '5', 'cash', null],
            ['572', 'Bancos e instituciones de credito c/c vista, euros', 'asset', 'debit', '5', 'cash', 'bank'],
            ['6', 'Compras y gastos', 'expense', 'debit', null, null, null],
            ['600', 'Compras de mercaderias', 'expense', 'debit', '6', null, null],
            ['601', 'Compras de materias primas', 'expense', 'debit', '6', null, null],
            ['606', 'Descuentos sobre compras por pronto pago', 'expense', 'credit', '6', null, null],
            ['608', 'Devoluciones de compras', 'expense', 'credit', '6', null, null],
            ['621', 'Arrendamientos y canones', 'expense', 'debit', '6', null, null],
            ['622', 'Reparaciones y conservacion', 'expense', 'debit', '6', null, null],
            ['623', 'Servicios de profesionales independientes', 'expense', 'debit', '6', null, null],
            ['624', 'Transportes', 'expense', 'debit', '6', null, null],
            ['625', 'Primas de seguros', 'expense', 'debit', '6', null, null],
            ['626', 'Servicios bancarios y similares', 'expense', 'debit', '6', null, null],
            ['627', 'Publicidad, propaganda y relaciones publicas', 'expense', 'debit', '6', null, null],
            ['628', 'Suministros', 'expense', 'debit', '6', null, null],
            ['629', 'Otros servicios', 'expense', 'debit', '6', null, null],
            ['630', 'Impuesto sobre beneficios', 'expense', 'debit', '6', null, null],
            ['631', 'Otros tributos', 'expense', 'debit', '6', null, null],
            ['640', 'Sueldos y salarios', 'expense', 'debit', '6', null, null],
            ['642', 'Seguridad Social a cargo de la empresa', 'expense', 'debit', '6', null, null],
            ['662', 'Intereses de deudas', 'expense', 'debit', '6', null, null],
            ['668', 'Diferencias negativas de cambio', 'expense', 'debit', '6', null, null],
            ['680', 'Amortizacion del inmovilizado intangible', 'expense', 'debit', '6', 'non_cash_expense', null],
            ['681', 'Amortizacion del inmovilizado material', 'expense', 'debit', '6', 'non_cash_expense', null],
            ['7', 'Ventas e ingresos', 'revenue', 'credit', null, null, null],
            ['700', 'Ventas de mercaderias', 'revenue', 'credit', '7', null, null],
            ['705', 'Prestaciones de servicios', 'revenue', 'credit', '7', null, null],
            ['706', 'Descuentos sobre ventas por pronto pago', 'revenue', 'debit', '7', null, null],
            ['708', 'Devoluciones de ventas', 'revenue', 'debit', '7', null, null],
            ['740', 'Subvenciones, donaciones y legados a la explotacion', 'revenue', 'credit', '7', null, null],
            ['752', 'Ingresos por arrendamientos', 'revenue', 'credit', '7', null, null],
            ['768', 'Diferencias positivas de cambio', 'revenue', 'credit', '7', null, null],
            ['769', 'Otros ingresos financieros', 'revenue', 'credit', '7', null, null],
        ];
    }
}
