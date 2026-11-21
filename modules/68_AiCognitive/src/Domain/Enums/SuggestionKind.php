<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Domain\Enums;

/**
 * The kinds of item the review queue holds. The first four are draft ERP entities parsed from unstructured text; the others
 * are accounting proposals, anomaly alerts and predictive recommendations.
 */
enum SuggestionKind: string
{
    case SalesQuote = 'sales_quote';
    case PurchaseOrder = 'purchase_order';
    case SupportTicket = 'support_ticket';
    case Contact = 'contact';
    case AccountingCoding = 'accounting_coding';
    case ReconciliationMatch = 'reconciliation_match';
    case Anomaly = 'anomaly';
    case LatePaymentRisk = 'late_payment_risk';
    case ChurnRisk = 'churn_risk';
    case Reorder = 'reorder';
    case CashFlowAlert = 'cash_flow_alert';

    /**
     * Whether approving the suggestion creates an ERP record from a draft.
     */
    public function isDraftEntity(): bool
    {
        return in_array($this, [self::SalesQuote, self::PurchaseOrder, self::SupportTicket, self::Contact], true);
    }

    /**
     * The automation workflow that governs the suggestion.
     */
    public function workflow(): string
    {
        return match ($this) {
            self::SalesQuote, self::PurchaseOrder, self::SupportTicket, self::Contact => 'extraction_'.$this->value,
            self::AccountingCoding => 'accounting_coding',
            self::ReconciliationMatch => 'reconciliation',
            self::Anomaly => 'anomaly_guard',
            self::LatePaymentRisk, self::ChurnRisk, self::Reorder, self::CashFlowAlert => 'predictive_insight',
        };
    }
}
