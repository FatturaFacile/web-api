<?php

declare(strict_types=1);

namespace App\Actions\Customs;

use App\DTOs\CustomsOriginEvaluation;
use App\Models\Invoice;

/**
 * Renders the legal origin declaration of an invoice in the requested language and optionally persists the result.
 */
final class GenerateInvoiceOriginDeclarationAction
{
    private const FALLBACK_LOCALE = 'en';

    /**
     * Localized names of the preferential origin territories, keyed by exporter country.
     *
     * @var array<string, array<string, string>>
     */
    private const ORIGIN_NAMES = [
        'CH' => ['it' => 'Svizzera', 'de' => 'Schweiz', 'fr' => 'Suisse', 'en' => 'Switzerland'],
        'LI' => ['it' => 'Liechtenstein', 'de' => 'Liechtenstein', 'fr' => 'Liechtenstein', 'en' => 'Liechtenstein'],
        'GB' => ['it' => 'Regno Unito', 'de' => 'Vereinigtes Königreich', 'fr' => 'Royaume-Uni', 'en' => 'United Kingdom'],
        'NO' => ['it' => 'Norvegia', 'de' => 'Norwegen', 'fr' => 'Norvège', 'en' => 'Norway'],
        'IS' => ['it' => 'Islanda', 'de' => 'Island', 'fr' => 'Islande', 'en' => 'Iceland'],
        'EU' => ['it' => 'Unione Europea', 'de' => 'Europäische Union', 'fr' => 'Union européenne', 'en' => 'European Union'],
    ];

    public function __construct(private readonly EvaluateCustomsOriginAction $evaluate) {}

    /**
     * @return array{evaluation: CustomsOriginEvaluation, declaration_text: string|null, locale: string}
     */
    public function execute(Invoice $invoice, ?string $locale = null, ?string $authorizationOverride = null, bool $persist = false): array
    {
        $evaluation = $this->evaluate->execute($invoice, $authorizationOverride);
        $locale = $this->resolveLocale($locale);
        $text = $this->render($evaluation, $locale);

        if ($persist) {
            $invoice->forceFill([
                'free_trade_agreement_id' => $evaluation->agreement?->id,
                'origin_declaration_text' => $text,
                'duty_exemption_eligible' => $evaluation->dutyExemptionEligible,
            ])->save();
        }

        return ['evaluation' => $evaluation, 'declaration_text' => $text, 'locale' => $locale];
    }

    public function render(CustomsOriginEvaluation $evaluation, string $locale): ?string
    {
        $agreement = $evaluation->agreement;
        if ($agreement === null || ! $evaluation->declarationAllowed) {
            return null;
        }

        $useAuthorization = $evaluation->authorizationNumber !== null;
        $templates = $useAuthorization ? $agreement->declaration_templates : $agreement->declaration_templates_no_auth;
        $template = $templates[$locale] ?? $templates[self::FALLBACK_LOCALE] ?? null;
        if (! is_string($template)) {
            return null;
        }

        return strtr($template, [
            '{customs_auth_number}' => (string) $evaluation->authorizationNumber,
            '{preferential_origin}' => $this->originName($evaluation->exporterCountry, $locale),
        ]);
    }

    private function originName(string $exporterCountry, string $locale): string
    {
        $names = self::ORIGIN_NAMES[$exporterCountry] ?? self::ORIGIN_NAMES['EU'];

        return $names[$locale] ?? $names[self::FALLBACK_LOCALE];
    }

    private function resolveLocale(?string $locale): string
    {
        $locale = strtolower(substr((string) ($locale ?? app()->getLocale()), 0, 2));

        return in_array($locale, ['it', 'de', 'fr', 'en'], true) ? $locale : self::FALLBACK_LOCALE;
    }
}
