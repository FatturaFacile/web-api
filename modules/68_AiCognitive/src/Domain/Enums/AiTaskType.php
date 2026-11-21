<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Domain\Enums;

/**
 * What a model call is for. The router sends the light tasks to a small or local model and keeps the frontier model for the
 * tasks that need reasoning.
 */
enum AiTaskType: string
{
    case Classification = 'classification';
    case Tagging = 'tagging';
    case Extraction = 'extraction';
    case AccountingCoding = 'accounting_coding';
    case QueryTranslation = 'query_translation';
    case Drafting = 'drafting';
    case AnomalyReasoning = 'anomaly_reasoning';
    case ContractAnalysis = 'contract_analysis';
    case Chat = 'chat';

    public function isLight(): bool
    {
        return in_array($this, [self::Classification, self::Tagging, self::AccountingCoding, self::QueryTranslation], true);
    }
}
