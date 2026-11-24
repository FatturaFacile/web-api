<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services\Mjml;

use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

/**
 * MJML to e-mail-client-safe HTML. Uses the real `mjml` binary when configured (MARKETING_MJML_BINARY) and falls back
 * to the built-in table renderer when node is not available (restricted environments, tests).
 */
final readonly class MjmlCompiler
{
    public function __construct(private FallbackMjmlRenderer $fallback) {}

    /**
     * @return array{html: string, engine: string}
     *
     * @throws InvalidArgumentException when the MJML is not valid
     */
    public function compile(string $mjml): array
    {
        $this->validate($mjml);

        $binary = (string) config('marketing_automation.mjml_binary', '');
        if ($binary !== '') {
            $result = Process::input($mjml)->timeout(30)->run([$binary, '-i', '-s']);
            if ($result->successful() && trim($result->output()) !== '') {
                return ['html' => $result->output(), 'engine' => 'mjml'];
            }
        }

        return ['html' => $this->fallback->render($mjml), 'engine' => 'fallback'];
    }

    /**
     * @throws InvalidArgumentException
     */
    public function validate(string $mjml): void
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $loaded = $document->loadXML($mjml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $document->documentElement?->nodeName !== 'mjml') {
            throw new InvalidArgumentException('Invalid MJML: the document must be well formed with an <mjml> root.');
        }
        if ($document->getElementsByTagName('mj-body')->length !== 1) {
            throw new InvalidArgumentException('Invalid MJML: exactly one <mj-body> is required.');
        }
    }
}
