<?php

declare(strict_types=1);

/*
 * XML and PDF fixtures shared by the OCR extraction tests (not a test file: Pest only runs *Test.php).
 */

/**
 * A FatturaPA 1.2 invoice from Rossi Srl to Verdi Spa with two lines at 22% and a purchase order reference.
 */
function ocrFatturaPaXml(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<p:FatturaElettronica xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2" versione="FPR12">
  <FatturaElettronicaHeader>
    <CedentePrestatore><DatiAnagrafici>
      <IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>01234567890</IdCodice></IdFiscaleIVA>
      <CodiceFiscale>RSSMRA80A01H501U</CodiceFiscale>
      <Anagrafica><Denominazione>Rossi Srl</Denominazione></Anagrafica>
    </DatiAnagrafici></CedentePrestatore>
    <CessionarioCommittente><DatiAnagrafici>
      <IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>09876543210</IdCodice></IdFiscaleIVA>
      <Anagrafica><Denominazione>Verdi Spa</Denominazione></Anagrafica>
    </DatiAnagrafici></CessionarioCommittente>
  </FatturaElettronicaHeader>
  <FatturaElettronicaBody>
    <DatiGenerali>
      <DatiGeneraliDocumento><TipoDocumento>TD01</TipoDocumento><Divisa>EUR</Divisa><Data>2026-03-05</Data><Numero>FT-17</Numero><ImportoTotaleDocumento>183.00</ImportoTotaleDocumento></DatiGeneraliDocumento>
      <DatiOrdineAcquisto><IdDocumento>PO-2026-77</IdDocumento></DatiOrdineAcquisto>
    </DatiGenerali>
    <DatiBeniServizi>
      <DettaglioLinee><NumeroLinea>1</NumeroLinea><Descrizione>Toner nero HP</Descrizione><Quantita>2.00</Quantita><PrezzoUnitario>50.00</PrezzoUnitario><PrezzoTotale>100.00</PrezzoTotale><AliquotaIVA>22.00</AliquotaIVA></DettaglioLinee>
      <DettaglioLinee><NumeroLinea>2</NumeroLinea><Descrizione>Carta A4</Descrizione><Quantita>10.00</Quantita><PrezzoUnitario>5.00</PrezzoUnitario><PrezzoTotale>50.00</PrezzoTotale><AliquotaIVA>22.00</AliquotaIVA></DettaglioLinee>
      <DatiRiepilogo><AliquotaIVA>22.00</AliquotaIVA><ImponibileImporto>150.00</ImponibileImporto><Imposta>33.00</Imposta></DatiRiepilogo>
    </DatiBeniServizi>
    <DatiPagamento><DettaglioPagamento><DataScadenzaPagamento>2026-04-04</DataScadenzaPagamento><IBAN>IT60X0542811101000000123456</IBAN></DettaglioPagamento></DatiPagamento>
  </FatturaElettronicaBody>
</p:FatturaElettronica>
XML;
}

/**
 * A Factur-X / ZUGFeRD CrossIndustryInvoice (EN 16931) of a Swiss seller with one line.
 */
function ocrCiiXml(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
  <rsm:ExchangedDocument><ram:ID>RE-2026-0042</ram:ID><ram:IssueDateTime><udt:DateTimeString format="102">20260305</udt:DateTimeString></ram:IssueDateTime></rsm:ExchangedDocument>
  <rsm:SupplyChainTradeTransaction>
    <ram:IncludedSupplyChainTradeLineItem>
      <ram:SpecifiedTradeProduct><ram:Name>Wartungsvertrag</ram:Name></ram:SpecifiedTradeProduct>
      <ram:SpecifiedLineTradeAgreement><ram:NetPriceProductTradePrice><ram:ChargeAmount>500.00</ram:ChargeAmount></ram:NetPriceProductTradePrice></ram:SpecifiedLineTradeAgreement>
      <ram:SpecifiedLineTradeDelivery><ram:BilledQuantity unitCode="C62">2</ram:BilledQuantity></ram:SpecifiedLineTradeDelivery>
      <ram:SpecifiedLineTradeSettlement><ram:ApplicableTradeTax><ram:RateApplicablePercent>19.00</ram:RateApplicablePercent></ram:ApplicableTradeTax><ram:SpecifiedTradeSettlementLineMonetarySummation><ram:LineTotalAmount>1000.00</ram:LineTotalAmount></ram:SpecifiedTradeSettlementLineMonetarySummation></ram:SpecifiedLineTradeSettlement>
    </ram:IncludedSupplyChainTradeLineItem>
    <ram:ApplicableHeaderTradeAgreement>
      <ram:SellerTradeParty><ram:Name>Muster GmbH</ram:Name><ram:SpecifiedLegalOrganization><ram:ID>CHE-123.456.789</ram:ID></ram:SpecifiedLegalOrganization><ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">DE123456789</ram:ID></ram:SpecifiedTaxRegistration></ram:SellerTradeParty>
      <ram:BuyerOrderReferencedDocument><ram:IssuerAssignedID>PO-2026-77</ram:IssuerAssignedID></ram:BuyerOrderReferencedDocument>
    </ram:ApplicableHeaderTradeAgreement>
    <ram:ApplicableHeaderTradeSettlement>
      <ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>
      <ram:SpecifiedTradeSettlementPaymentMeans><ram:PayeePartyCreditorFinancialAccount><ram:IBANID>DE89370400440532013000</ram:IBANID></ram:PayeePartyCreditorFinancialAccount></ram:SpecifiedTradeSettlementPaymentMeans>
      <ram:ApplicableTradeTax><ram:CalculatedAmount>190.00</ram:CalculatedAmount><ram:BasisAmount>1000.00</ram:BasisAmount><ram:RateApplicablePercent>19.00</ram:RateApplicablePercent></ram:ApplicableTradeTax>
      <ram:SpecifiedTradePaymentTerms><ram:DueDateDateTime><udt:DateTimeString format="102">20260404</udt:DateTimeString></ram:DueDateDateTime></ram:SpecifiedTradePaymentTerms>
      <ram:SpecifiedTradeSettlementHeaderMonetarySummation><ram:LineTotalAmount>1000.00</ram:LineTotalAmount><ram:TaxBasisTotalAmount>1000.00</ram:TaxBasisTotalAmount><ram:TaxTotalAmount currencyID="EUR">190.00</ram:TaxTotalAmount><ram:GrandTotalAmount>1190.00</ram:GrandTotalAmount></ram:SpecifiedTradeSettlementHeaderMonetarySummation>
    </ram:ApplicableHeaderTradeSettlement>
  </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>
XML;
}

/**
 * A minimal PDF/A-3 style file whose catalog attaches the XML as a Flate-compressed embedded file.
 */
function ocrPdfWithAttachment(string $xml, bool $compressed = true): string
{
    $stream = $compressed ? gzcompress($xml) : $xml;
    $filter = $compressed ? '/Filter /FlateDecode ' : '';

    return "%PDF-1.7\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>\nendobj\n"
        ."4 0 obj\n<< /Type /EmbeddedFile /Subtype /text#2Fxml {$filter}/Length ".strlen($stream)." >>\nstream\n{$stream}\nendstream\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
}
