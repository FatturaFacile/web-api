<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use DOMDocument;

/**
 * Renders a FatturaPA file in the readable form accountants know. `ministerial` applies the official Agenzia delle
 * Entrate stylesheet that matches the format (ordinary, public administration, simplified); `assosoftware` applies
 * `resources/xsl/FoglioStileAssoSoftware.xsl` when that file is installed. Without the PHP xsl extension, or without
 * the stylesheet, the built-in HTML renderer is used, so a file is always readable.
 */
final class FatturaPaStylesheetRenderer
{
    public const array Styles = ['ministerial', 'assosoftware'];

    public function __construct(private readonly FatturaPaHtmlRenderer $fallback, private readonly P7mExtractor $p7m) {}

    public function render(string $content, string $style = 'ministerial'): string
    {
        $xml = str_starts_with(ltrim($content), '<') ? $content : $this->p7m->extract($content);
        $sheet = $this->stylesheetPath($xml, $style);

        if ($sheet === null || ! class_exists(\XSLTProcessor::class)) {
            return $this->fallback->render($xml);
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $source = new DOMDocument;
            $stylesheet = new DOMDocument;

            if (! $source->loadXML($xml, LIBXML_NONET) || ! $stylesheet->load($sheet, LIBXML_NONET)) {
                return $this->fallback->render($xml);
            }

            $processor = new \XSLTProcessor;
            $processor->importStylesheet($stylesheet);
            $html = $processor->transformToXml($source);

            return $html === false || $html === null ? $this->fallback->render($xml) : $html;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function stylesheetPath(string $xml, string $style): ?string
    {
        $dir = dirname(__DIR__, 2).'/resources/xsl/';

        if ($style === 'assosoftware') {
            $path = $dir.'FoglioStileAssoSoftware.xsl';

            return is_file($path) ? $path : null;
        }

        $format = preg_match('/versione="(FPR12|FPA12|FSM10)"/', $xml, $match) === 1 ? $match[1] : 'FPR12';
        $file = match ($format) {
            'FPA12' => 'fatturapa_pa_v1.2.3.xsl',
            'FSM10' => 'fatturapa_vfsm10_v1.0.2.xsl',
            default => 'fatturapa_ordinaria_v1.2.3.xsl',
        };

        return $dir.$file;
    }
}
