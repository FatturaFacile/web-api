<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services\Mjml;

use Modules\MarketingAutomation\Models\Campaign;

/**
 * Renders the template of a campaign per locale (structured blocks, or raw MJML) and caches the compiled HTML.
 */
final readonly class CampaignTemplateService
{
    public const array LOCALES = ['it', 'de', 'fr', 'en'];

    public function __construct(
        private EmailBlockRenderer $blocks,
        private MjmlCompiler $compiler,
    ) {}

    /**
     * @param  array<string, mixed>  $template
     * @return array{subject: string, preheader: string, mjml: string, html: string, engine: string}
     */
    public function render(array $template, string $locale, ?string $variant = null): array
    {
        if ($variant !== null && isset($template['variants'][$variant])) {
            $template = array_replace($template, (array) $template['variants'][$variant]);
        }

        $subject = $this->pick($template['subject'] ?? '', $locale);
        $preheader = $this->pick($template['preheader'] ?? '', $locale);
        $raw = $this->pick($template['mjml'] ?? '', $locale);

        $mjml = $raw !== '' ? $raw : $this->blocks->toMjml((array) ($template['blocks'] ?? []), $locale, $subject, $preheader);
        $compiled = $this->compiler->compile($mjml);

        return ['subject' => $subject, 'preheader' => $preheader, 'mjml' => $mjml, 'html' => $compiled['html'], 'engine' => $compiled['engine']];
    }

    /**
     * Compiles all four locales and stores the HTML cache on the campaign.
     */
    public function compileAndCache(Campaign $campaign): Campaign
    {
        $template = (array) $campaign->template_mjml_json;
        $compiled = [];
        foreach (self::LOCALES as $locale) {
            $compiled[$locale] = $this->render($template, $locale)['html'];
        }

        $template['compiled'] = $compiled;
        $campaign->forceFill(['template_mjml_json' => $template])->save();

        return $campaign;
    }

    private function pick(mixed $value, string $locale): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (! is_array($value)) {
            return '';
        }
        foreach ([$locale, 'it', 'en', 'de', 'fr'] as $candidate) {
            if (isset($value[$candidate]) && is_string($value[$candidate]) && $value[$candidate] !== '') {
                return $value[$candidate];
            }
        }

        return '';
    }
}
