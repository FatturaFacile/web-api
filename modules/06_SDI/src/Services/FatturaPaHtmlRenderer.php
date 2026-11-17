<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * Renders a FatturaPA file as a read-only HTML page, labelled with the element names of the official schema.
 * Every value is escaped, and attachments are listed by name only: the document is never executed or fetched.
 */
final class FatturaPaHtmlRenderer
{
    /**
     * @throws InvalidArgumentException when the content is not a FatturaPA document
     */
    public function render(string $xml): string
    {
        $document = new DOMDocument;

        if (! @$document->loadXML($xml, LIBXML_NONET) || $document->documentElement?->localName !== 'FatturaElettronica') {
            throw new InvalidArgumentException('Not a FatturaPA document.');
        }

        $root = $document->documentElement;
        $sections = [];

        foreach ($this->children($root) as $part) {
            if ($part->localName === 'FatturaElettronicaHeader') {
                foreach ($this->children($part) as $block) {
                    $sections[] = $this->section($block->localName, $block);
                }

                continue;
            }

            foreach ($this->children($part) as $block) {
                $sections[] = $this->section($block->localName, $block);
            }
        }

        return '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'">'
            .'<title>FatturaPA</title><style>body{font:14px/1.4 sans-serif;margin:1.5rem;color:#222}h2{font-size:1rem;margin:1.2rem 0 .3rem;border-bottom:1px solid #ccc}'
            .'dl{display:grid;grid-template-columns:max-content 1fr;gap:.15rem 1rem;margin:.2rem 0}dt{color:#666}dd{margin:0}.sub{margin-left:1rem}</style></head><body>'
            .'<h1>'.$this->e((string) $root->getAttribute('versione')).'</h1>'.implode('', $sections).'</body></html>';
    }

    private function section(string $title, DOMElement $element): string
    {
        return '<section><h2>'.$this->e($title).'</h2>'.$this->body($element).'</section>';
    }

    private function body(DOMElement $element): string
    {
        if ($element->localName === 'Allegati') {
            return $this->attachment($element);
        }

        $rows = '';
        $nested = '';

        foreach ($this->children($element) as $child) {
            if ($child->localName === 'Attachment') {
                continue;
            }

            if ($this->children($child) === []) {
                $rows .= '<dt>'.$this->e($child->localName).'</dt><dd>'.$this->e(trim($child->textContent)).'</dd>';
            } else {
                $nested .= '<div class="sub"><strong>'.$this->e($child->localName).'</strong>'.$this->body($child).'</div>';
            }
        }

        return ($rows !== '' ? '<dl>'.$rows.'</dl>' : '').$nested;
    }

    private function attachment(DOMElement $element): string
    {
        $name = '';
        $format = '';

        foreach ($this->children($element) as $child) {
            $name = $child->localName === 'NomeAttachment' ? trim($child->textContent) : $name;
            $format = $child->localName === 'FormatoAttachment' ? trim($child->textContent) : $format;
        }

        return '<dl><dt>NomeAttachment</dt><dd>'.$this->e($name).'</dd><dt>FormatoAttachment</dt><dd>'.$this->e($format).'</dd></dl>';
    }

    /**
     * @return list<DOMElement>
     */
    private function children(DOMElement $element): array
    {
        $children = [];

        foreach ($element->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $children[] = $node;
            }
        }

        return $children;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
