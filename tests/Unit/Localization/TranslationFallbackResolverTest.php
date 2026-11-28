<?php

declare(strict_types=1);

use Modules\Platform\Localization\Domain\Contracts\TranslationLookup;
use Modules\Platform\Localization\Domain\Services\TranslationFallbackResolver;
use Modules\Platform\Localization\Domain\ValueObjects\Locale;
use Psr\Log\AbstractLogger;

const TENANT_A = 'tenant-a';

/**
 * A lookup over an array keyed "tenant|locale|key" (tenant empty for the system dictionary).
 *
 * @param  array<string, string>  $rows
 */
function inMemoryLookup(array $rows): TranslationLookup
{
    return new class($rows) implements TranslationLookup
    {
        /** @param array<string, string> $rows */
        public function __construct(private readonly array $rows) {}

        public function find(?string $tenantId, string $locale, string $key): ?string
        {
            return $this->rows[($tenantId ?? '').'|'.$locale.'|'.$key] ?? null;
        }
    };
}

/**
 * @return object{records: list<array{message: string, context: array<string, mixed>}>}
 */
function recordingLogger(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        /** @var list<array{message: string, context: array<string, mixed>}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = ['message' => (string) $message, 'context' => $context];
        }
    };
}

it('prefers the tenant override of the exact locale over everything else', function (): void {
    $resolver = new TranslationFallbackResolver(inMemoryLookup([
        TENANT_A.'|de-CH|invoicing.title' => 'Tenant exakt',
        TENANT_A.'|de|invoicing.title' => 'Tenant Sprache',
        '|de-CH|invoicing.title' => 'System exakt',
        '|de|invoicing.title' => 'System Sprache',
        '|en|invoicing.title' => 'System English',
    ]), recordingLogger());

    expect($resolver->resolve('invoicing.title', Locale::from('de-CH'), TENANT_A))->toBe('Tenant exakt');
});

it('falls back to the tenant override of the base language', function (): void {
    $resolver = new TranslationFallbackResolver(inMemoryLookup([
        TENANT_A.'|de|invoicing.title' => 'Tenant Sprache',
        '|de-CH|invoicing.title' => 'System exakt',
        '|de|invoicing.title' => 'System Sprache',
    ]), recordingLogger());

    expect($resolver->resolve('invoicing.title', Locale::from('de-CH'), TENANT_A))->toBe('Tenant Sprache');
});

it('falls back to the system text of the exact locale', function (): void {
    $resolver = new TranslationFallbackResolver(inMemoryLookup([
        '|de-CH|invoicing.title' => 'System exakt',
        '|de|invoicing.title' => 'System Sprache',
        '|en|invoicing.title' => 'System English',
    ]), recordingLogger());

    expect($resolver->resolve('invoicing.title', Locale::from('de-CH'), TENANT_A))->toBe('System exakt');
});

it('falls back to the system text of the base language', function (): void {
    $resolver = new TranslationFallbackResolver(inMemoryLookup([
        '|de|invoicing.title' => 'System Sprache',
        '|en|invoicing.title' => 'System English',
    ]), recordingLogger());

    expect($resolver->resolve('invoicing.title', Locale::from('de-CH'), TENANT_A))->toBe('System Sprache');
});

it('falls back to the system English text', function (): void {
    $resolver = new TranslationFallbackResolver(inMemoryLookup(['|en|invoicing.title' => 'System English']), recordingLogger());

    expect($resolver->resolve('invoicing.title', Locale::from('fr-CH'), TENANT_A))->toBe('System English');
});

it('returns the key itself and logs a structured warning when nothing is found', function (): void {
    $logger = recordingLogger();
    $resolver = new TranslationFallbackResolver(inMemoryLookup([]), $logger);

    expect($resolver->resolve('invoicing.unknown', Locale::from('it-CH'), TENANT_A))->toBe('invoicing.unknown')
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toBe('i18n.missing_translation_key')
        ->and($logger->records[0]['context'])->toBe(['key' => 'invoicing.unknown', 'locale' => 'it-CH', 'tenant_id' => TENANT_A]);
});

it('never returns an empty string: an empty stored text counts as missing', function (): void {
    $resolver = new TranslationFallbackResolver(inMemoryLookup([
        TENANT_A.'|it-CH|invoicing.title' => '',
        '|it|invoicing.title' => 'Fattura',
    ]), recordingLogger());

    expect($resolver->resolve('invoicing.title', Locale::from('it-CH'), TENANT_A))->toBe('Fattura');
});

it('reports a missing key once per scope', function (): void {
    $logger = recordingLogger();
    $resolver = new TranslationFallbackResolver(inMemoryLookup([]), $logger);

    $resolver->resolve('a.b', Locale::from('en'), null);
    $resolver->resolve('a.b', Locale::from('en'), null);

    expect($logger->records)->toHaveCount(1);
});

it('does not read a tenant dictionary when there is no tenant, and never repeats an identical lookup', function (): void {
    $resolver = new TranslationFallbackResolver(inMemoryLookup([]), recordingLogger());

    $chain = array_map(static fn (array $step): string => ($step[0] ?? 'system').'/'.$step[1]->value(), $resolver->candidates(Locale::from('en'), null));

    expect($chain)->toBe(['system/en'])
        ->and(array_map(static fn (array $step): string => ($step[0] ?? 'system').'/'.$step[1]->value(), $resolver->candidates(Locale::from('de-CH'), TENANT_A)))
        ->toBe(['tenant-a/de-CH', 'tenant-a/de', 'system/de-CH', 'system/de', 'system/en']);
});
