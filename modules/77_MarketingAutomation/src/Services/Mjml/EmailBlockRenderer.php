<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services\Mjml;

/**
 * Turns the structured blocks of the visual designer into MJML for one locale. Every text prop is a map of locale to
 * string; the product block carries the data imported from the ERP catalog (name, description, price, image).
 */
final class EmailBlockRenderer
{
    private const array FALLBACK_LOCALES = ['it', 'en', 'de', 'fr'];

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    public function toMjml(array $blocks, string $locale, string $title = '', string $preheader = ''): string
    {
        $sections = '';
        foreach ($blocks as $block) {
            $sections .= $this->section($block, $locale);
        }

        return '<mjml><mj-head><mj-title>'.$this->x($title).'</mj-title><mj-preview>'.$this->x($preheader).'</mj-preview></mj-head>'
            .'<mj-body background-color="#f4f4f4">'.$sections.'</mj-body></mjml>';
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function section(array $block, string $locale): string
    {
        $props = (array) ($block['props'] ?? []);

        $content = match ((string) ($block['type'] ?? '')) {
            'heading' => '<mj-text font-size="24px" font-weight="bold" color="'.$this->x($this->scalar($props, 'color', '#111111')).'">'.$this->x($this->localized($props['text'] ?? '', $locale)).'</mj-text>',
            'text' => '<mj-text>'.$this->x($this->localized($props['text'] ?? '', $locale)).'</mj-text>',
            'image' => '<mj-image src="'.$this->x($this->scalar($props, 'src', '')).'" alt="'.$this->x($this->localized($props['alt'] ?? '', $locale)).'"'.(isset($props['href']) ? ' href="'.$this->x((string) $props['href']).'"' : '').' />',
            'button' => '<mj-button href="'.$this->x($this->scalar($props, 'href', '#')).'" background-color="'.$this->x($this->scalar($props, 'color', '#1d4ed8')).'">'.$this->x($this->localized($props['label'] ?? '', $locale)).'</mj-button>',
            'divider' => '<mj-divider />',
            'spacer' => '<mj-spacer height="'.$this->x($this->scalar($props, 'height', '20px')).'" />',
            'product' => $this->product($props, $locale),
            'html' => '<mj-raw>'.$this->scalar($props, 'html', '').'</mj-raw>',
            default => '',
        };

        return $content === '' ? '' : '<mj-section background-color="#ffffff"><mj-column>'.$content.'</mj-column></mj-section>';
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function product(array $props, string $locale): string
    {
        $price = isset($props['price']) ? number_format((float) $props['price'], 2, '.', "'").' '.$this->scalar($props, 'currency', '') : '';
        $out = '';
        if (($props['image_url'] ?? '') !== '') {
            $out .= '<mj-image src="'.$this->x((string) $props['image_url']).'" alt="'.$this->x($this->localized($props['name'] ?? '', $locale)).'" />';
        }
        $out .= '<mj-text font-size="18px" font-weight="bold">'.$this->x($this->localized($props['name'] ?? '', $locale)).'</mj-text>';
        $out .= '<mj-text>'.$this->x($this->localized($props['description'] ?? '', $locale)).'</mj-text>';
        if ($price !== '') {
            $out .= '<mj-text font-size="16px" color="#1d4ed8">'.$this->x(trim($price)).'</mj-text>';
        }
        if (($props['url'] ?? '') !== '' && ($props['cta_label'] ?? null) !== null) {
            $out .= '<mj-button href="'.$this->x((string) $props['url']).'">'.$this->x($this->localized($props['cta_label'], $locale)).'</mj-button>';
        }

        return $out;
    }

    private function localized(mixed $value, string $locale): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (! is_array($value)) {
            return '';
        }
        foreach ([$locale, ...self::FALLBACK_LOCALES] as $candidate) {
            if (isset($value[$candidate]) && is_string($value[$candidate]) && $value[$candidate] !== '') {
                return $value[$candidate];
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function scalar(array $props, string $key, string $default): string
    {
        return isset($props[$key]) && is_scalar($props[$key]) ? (string) $props[$key] : $default;
    }

    private function x(string $text): string
    {
        // Keep {{placeholders}} intact: they are replaced at send time.
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
