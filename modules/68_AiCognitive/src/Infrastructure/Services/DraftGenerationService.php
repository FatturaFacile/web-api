<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Services;

use Modules\AiCognitive\Domain\Contracts\AiProviderGatewayInterface;
use Modules\AiCognitive\Domain\Enums\AiTaskType;
use Modules\AiCognitive\Domain\Enums\DataClassification;
use Modules\AiCognitive\Domain\Exceptions\AiCognitiveException;
use Modules\AiCognitive\Domain\ValueObjects\AiCompletionRequest;
use Modules\Copilot\Services\PromptSanitizer;

/**
 * Drafts multilingual customer emails, payment reminders and quote cover letters. The user always reviews the text: the
 * service returns a subject and a body and sends nothing.
 */
final readonly class DraftGenerationService
{
    public const array Locales = ['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT'];

    public const array Purposes = ['customer_email', 'payment_reminder', 'quote_cover_letter'];

    private const array LanguageNames = ['it-CH' => 'Swiss Italian', 'de-CH' => 'Swiss German (Hochdeutsch with Swiss spelling, no ß)', 'fr-CH' => 'Swiss French', 'en' => 'English', 'it-IT' => 'Italian (Italy)'];

    public function __construct(
        private AiProviderGatewayInterface $gateway,
        private PromptLibrary $prompts,
        private PromptSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, scalar|null>  $facts  the facts the draft may use (names, amounts, dates, invoice numbers)
     * @return array{subject: string, body: string, locale: string}
     *
     * @throws AiCognitiveException
     */
    public function draft(string $tenantId, ?string $userId, string $purpose, string $locale, array $facts, ?string $instructions = null): array
    {
        if (! in_array($purpose, self::Purposes, true) || ! in_array($locale, self::Locales, true)) {
            throw new AiCognitiveException('Unknown draft purpose or language.');
        }

        $lines = [];

        foreach ($facts as $key => $value) {
            if ($value !== null && $value !== '') {
                $lines[] = $this->prompts->defuse($this->sanitizer->prompt((string) $key)).': '.$this->prompts->defuse($this->sanitizer->prompt((string) $value));
            }
        }

        $result = $this->gateway->complete(new AiCompletionRequest(
            $tenantId,
            AiTaskType::Drafting,
            $this->prompts->system($tenantId, 'drafting.email', $locale)."\nPurpose: {$purpose}. Write in ".self::LanguageNames[$locale].'.',
            "<facts>\n".implode("\n", $lines)."\n</facts>".($instructions === null ? '' : "\nExtra instruction of the user: ".$this->prompts->defuse($this->sanitizer->prompt($instructions))),
            DataClassification::ConfidentialPii,
            $userId,
            maxTokens: 700,
            temperature: 0.4,
            expectJson: true,
            cacheable: false,
        ));

        $json = $result->json();

        if ($json === null || ! is_string($json['subject'] ?? null) || ! is_string($json['body'] ?? null)) {
            throw new AiCognitiveException('The model did not return a usable draft.');
        }

        return ['subject' => mb_substr(trim($json['subject']), 0, 200), 'body' => trim($json['body']), 'locale' => $locale];
    }
}
