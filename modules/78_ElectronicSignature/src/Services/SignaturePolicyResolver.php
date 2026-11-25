<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Services;

use Modules\ElectronicSignature\Enums\SignatureLevel;
use Modules\ElectronicSignature\Models\SignatureTemplate;

/**
 * Decides the signature tier a document needs: the rules of the configuration (document type and amount) refined by the
 * active templates of the company that carry a `min_amount` (a template without one is only a default workflow). The highest tier whose threshold the amount reaches applies.
 */
final class SignaturePolicyResolver
{
    public function minimumLevel(string $documentType, ?string $amount): SignatureLevel
    {
        $level = SignatureLevel::Fes;

        foreach ($this->rules($documentType) as $rule) {
            if ($this->reaches($amount, (string) $rule['min_amount']) && $rule['level']->rank() > $level->rank()) {
                $level = $rule['level'];
            }
        }

        return $level;
    }

    /**
     * The template that applies to the document: the active one of its type with the highest threshold the amount reaches.
     */
    public function template(string $documentType, ?string $amount): ?SignatureTemplate
    {
        return SignatureTemplate::query()
            ->where('document_type', $documentType)
            ->where('is_active', true)
            ->get()
            ->filter(fn (SignatureTemplate $template): bool => $template->min_amount === null || $this->reaches($amount, (string) $template->min_amount))
            ->sortByDesc(static fn (SignatureTemplate $template): string => str_pad(number_format((float) ($template->min_amount ?? 0), 2, '.', ''), 18, '0', STR_PAD_LEFT))
            ->first();
    }

    /**
     * @return list<array{min_amount: string, level: SignatureLevel}>
     */
    private function rules(string $documentType): array
    {
        $rules = [];

        foreach ((array) config("electronic_signature.policies.{$documentType}", []) as $rule) {
            $rules[] = ['min_amount' => (string) $rule['min_amount'], 'level' => SignatureLevel::from((string) $rule['level'])];
        }

        $templates = SignatureTemplate::query()->where('document_type', $documentType)->where('is_active', true)->whereNotNull('min_amount')->get();

        foreach ($templates as $template) {
            $rules[] = ['min_amount' => (string) $template->min_amount, 'level' => $template->required_level];
        }

        return $rules;
    }

    private function reaches(?string $amount, string $threshold): bool
    {
        return bccomp($amount ?? '0', $threshold, 2) >= 0;
    }
}
