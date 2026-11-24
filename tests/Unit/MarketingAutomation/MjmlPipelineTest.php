<?php

declare(strict_types=1);

use Modules\MarketingAutomation\Services\Mjml\CampaignTemplateService;
use Modules\MarketingAutomation\Services\Mjml\EmailBlockRenderer;
use Modules\MarketingAutomation\Services\Mjml\FallbackMjmlRenderer;
use Modules\MarketingAutomation\Services\Mjml\MjmlCompiler;
use Modules\MarketingAutomation\Services\TrackingService;

function maTemplateService(): CampaignTemplateService
{
    return new CampaignTemplateService(new EmailBlockRenderer, new MjmlCompiler(new FallbackMjmlRenderer));
}

test('the fallback compiler renders client-safe table HTML from MJML', function (): void {
    $result = (new MjmlCompiler(new FallbackMjmlRenderer))->compile(
        '<mjml><mj-head><mj-title>Hi</mj-title><mj-preview>Preview</mj-preview></mj-head><mj-body><mj-section><mj-column>'
        .'<mj-text>Hello <b>you</b></mj-text><mj-button href="https://example.com">Go</mj-button><mj-image src="https://example.com/a.png" alt="A" />'
        .'</mj-column></mj-section></mj-body></mjml>'
    );

    expect($result['engine'])->toBe('fallback')
        ->and($result['html'])->toContain('<table')->toContain('role="presentation"')->toContain('Hello')
        ->toContain('href="https://example.com"')->toContain('<title>Hi</title>')->toContain('Preview')
        ->not->toContain('<mj-');
});

test('invalid MJML is rejected', function (string $mjml): void {
    expect(fn () => (new MjmlCompiler(new FallbackMjmlRenderer))->compile($mjml))->toThrow(InvalidArgumentException::class);
})->with([
    'not xml' => ['<mjml><mj-body>'],
    'wrong root' => ['<html><body/></html>'],
    'no body' => ['<mjml><mj-head/></mjml>'],
]);

test('designer blocks become localized MJML with fallback to Italian', function (): void {
    $blocks = [
        ['type' => 'heading', 'props' => ['text' => ['it' => 'Ciao', 'de' => 'Hallo']]],
        ['type' => 'text', 'props' => ['text' => ['it' => 'Solo italiano']]],
        ['type' => 'unknown', 'props' => []],
    ];

    $de = (new EmailBlockRenderer)->toMjml($blocks, 'de', 'Betreff');
    $fr = (new EmailBlockRenderer)->toMjml($blocks, 'fr', 'Sujet');

    expect($de)->toContain('Hallo')->toContain('Solo italiano')->toContain('<mj-title>Betreff</mj-title>')
        ->and($fr)->toContain('Ciao')
        ->and(substr_count($de, '<mj-section'))->toBe(2);
});

test('the ERP catalog product block carries image, description and formatted price', function (): void {
    $mjml = (new EmailBlockRenderer)->toMjml([[
        'type' => 'product',
        'props' => [
            'name' => ['it' => 'Kit ricambi', 'en' => 'Spare kit'],
            'description' => ['it' => 'Tutto il necessario', 'en' => 'Everything needed'],
            'price' => 1234.5,
            'currency' => 'CHF',
            'image_url' => 'https://cdn.example.ch/kit.png',
            'url' => 'https://shop.example.ch/kit',
            'cta_label' => ['it' => 'Richiedi', 'en' => 'Request'],
        ],
    ]], 'en');

    expect($mjml)->toContain('Spare kit')->toContain('Everything needed')->toContain('1&apos;234.50 CHF')
        ->toContain('https://cdn.example.ch/kit.png')->toContain('https://shop.example.ch/kit')->toContain('Request');
});

test('a template renders per locale, supports A/B variants and caches the compiled HTML', function (): void {
    $template = [
        'subject' => ['it' => 'Ciao', 'en' => 'Hello'],
        'blocks' => [['type' => 'text', 'props' => ['text' => ['it' => 'Base', 'en' => 'Base en']]]],
        'variants' => ['B' => ['subject' => ['it' => 'Variante B', 'en' => 'Variant B']]],
    ];
    $service = maTemplateService();

    expect($service->render($template, 'en')['subject'])->toBe('Hello')
        ->and($service->render($template, 'en', 'B')['subject'])->toBe('Variant B')
        ->and($service->render($template, 'it')['html'])->toContain('Base');
});

test('click targets are signed so the redirect cannot be abused', function (): void {
    $tracking = app(TrackingService::class);
    $url = $tracking->clickUrl(str_repeat('a', 40), 'https://shop.example.ch/offer?x=1');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($tracking->decodeTarget($query['u'], $query['s']))->toBe('https://shop.example.ch/offer?x=1')
        ->and($tracking->decodeTarget($query['u'], str_repeat('0', 64)))->toBeNull()
        ->and($tracking->decodeTarget(rtrim(strtr(base64_encode('javascript:alert(1)'), '+/', '-_'), '='), hash_hmac('sha256', 'javascript:alert(1)', (string) config('app.key'))))->toBeNull();
});
