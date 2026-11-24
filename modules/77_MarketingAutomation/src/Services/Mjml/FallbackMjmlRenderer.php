<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services\Mjml;

use DOMElement;
use DOMNode;

/**
 * Minimal MJML subset (mj-section, mj-column, mj-text, mj-image, mj-button, mj-divider, mj-spacer, mj-raw) rendered
 * as nested tables with inline styles, the layout every client (Outlook, Gmail, Apple Mail) understands.
 */
final class FallbackMjmlRenderer
{
    public function render(string $mjml): string
    {
        $document = new \DOMDocument;
        $document->loadXML($mjml, LIBXML_NONET);

        $title = $this->textOf($document, 'mj-title');
        $preview = $this->textOf($document, 'mj-preview');
        $body = $document->getElementsByTagName('mj-body')->item(0);
        $background = $body instanceof DOMElement && $body->getAttribute('background-color') !== '' ? $body->getAttribute('background-color') : '#f4f4f4';

        $inner = '';
        if ($body instanceof DOMElement) {
            foreach ($body->childNodes as $child) {
                $inner .= $this->node($child);
            }
        }

        return '<!doctype html><html lang="und" xmlns="http://www.w3.org/1999/xhtml"><head><title>'.e($title).'</title>'
            .'<meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<!--[if mso]><xml><o:OfficeDocumentSettings><o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->'
            .'</head><body style="margin:0;padding:0;background-color:'.e($background).';">'
            .($preview !== '' ? '<div style="display:none;max-height:0;overflow:hidden;">'.e($preview).'</div>' : '')
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:'.e($background).';"><tr><td align="center">'
            .'<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">'.$inner.'</table>'
            .'</td></tr></table></body></html>';
    }

    private function textOf(\DOMDocument $document, string $tag): string
    {
        $node = $document->getElementsByTagName($tag)->item(0);

        return $node instanceof DOMNode ? $node->textContent : '';
    }

    private function node(DOMNode $node): string
    {
        if (! $node instanceof DOMElement) {
            return '';
        }

        return match ($node->nodeName) {
            'mj-section', 'mj-wrapper' => $this->section($node),
            'mj-text' => $this->block($node, '<div style="font-family:'.e($this->attr($node, 'font-family', 'Arial, Helvetica, sans-serif')).';font-size:'.e($this->attr($node, 'font-size', '14px')).';line-height:1.5;color:'.e($this->attr($node, 'color', '#222222')).';text-align:'.e($this->attr($node, 'align', 'left')).';">'.$this->innerHtml($node).'</div>'),
            'mj-image' => $this->block($node, '<img src="'.e($node->getAttribute('src')).'" alt="'.e($node->getAttribute('alt')).'" width="'.e(rtrim($this->attr($node, 'width', '560'), 'px')).'" style="display:block;border:0;max-width:100%;height:auto;">', $node->getAttribute('href')),
            'mj-button' => $this->block($node, '<a href="'.e($this->attr($node, 'href', '#')).'" style="display:inline-block;background-color:'.e($this->attr($node, 'background-color', '#1d4ed8')).';color:'.e($this->attr($node, 'color', '#ffffff')).';font-family:Arial,sans-serif;font-size:'.e($this->attr($node, 'font-size', '14px')).';text-decoration:none;padding:10px 25px;border-radius:'.e($this->attr($node, 'border-radius', '4px')).';">'.$this->innerHtml($node).'</a>'),
            'mj-divider' => $this->block($node, '<hr style="border:0;border-top:'.e($this->attr($node, 'border-width', '1px')).' solid '.e($this->attr($node, 'border-color', '#cccccc')).';margin:0;">'),
            'mj-spacer' => '<tr><td style="height:'.e($this->attr($node, 'height', '20px')).';line-height:'.e($this->attr($node, 'height', '20px')).';">&nbsp;</td></tr>',
            'mj-raw' => '<tr><td>'.$this->innerHtml($node).'</td></tr>',
            default => '',
        };
    }

    private function section(DOMElement $section): string
    {
        $columns = [];
        foreach ($section->childNodes as $child) {
            if ($child instanceof DOMElement && $child->nodeName === 'mj-column') {
                $columns[] = $child;
            } elseif ($child instanceof DOMElement && $child->nodeName === 'mj-section') {
                return $this->section($child);
            }
        }

        $width = $columns === [] ? 100 : (int) floor(100 / count($columns));
        $cells = '';
        foreach ($columns as $column) {
            $content = '';
            foreach ($column->childNodes as $child) {
                $content .= $this->node($child);
            }
            $cells .= '<td width="'.$width.'%" valign="top" style="width:'.$width.'%;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'.$content.'</table></td>';
        }

        return '<tr><td style="background-color:'.e($this->attr($section, 'background-color', '#ffffff')).';padding:'.e($this->attr($section, 'padding', '20px 0')).';">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'.$cells.'</tr></table></td></tr>';
    }

    private function block(DOMElement $node, string $html, string $href = ''): string
    {
        if ($href !== '') {
            $html = '<a href="'.e($href).'" style="text-decoration:none;">'.$html.'</a>';
        }

        return '<tr><td align="'.e($this->attr($node, 'align', 'left')).'" style="padding:'.e($this->attr($node, 'padding', '10px 25px')).';">'.$html.'</td></tr>';
    }

    private function innerHtml(DOMElement $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?? '';
        }

        return $html;
    }

    private function attr(DOMElement $node, string $name, string $default): string
    {
        return $node->hasAttribute($name) ? $node->getAttribute($name) : $default;
    }
}
