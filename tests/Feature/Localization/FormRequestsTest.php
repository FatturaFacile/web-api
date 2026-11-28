<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Modules\Platform\Localization\Http\Requests\DictionaryRequest;
use Modules\Platform\Localization\Http\Requests\ExportTranslationsRequest;
use Modules\Platform\Localization\Http\Requests\ImportTranslationsRequest;
use Modules\Platform\Localization\Http\Requests\ListTranslationsRequest;
use Modules\Platform\Localization\Http\Requests\UpdateLocaleSettingsRequest;
use Modules\Platform\Localization\Http\Requests\UpdateUserLocaleRequest;
use Modules\Platform\Localization\Http\Requests\UpsertResourceTranslationRequest;
use Modules\Platform\Localization\Http\Requests\UpsertTranslationRequest;

require_once __DIR__.'/LocalizationSupport.php';

/**
 * @param  array<string, mixed>  $data
 */
function requestErrors(FormRequest $request, array $data): array
{
    return Validator::make($data, $request->rules())->errors()->keys();
}

beforeEach(function (): void {
    locSetUp($this);
});

it('validates the profile locale', function (): void {
    $request = new UpdateUserLocaleRequest;

    expect(requestErrors($request, ['locale' => 'fr-CH']))->toBe([])
        ->and(requestErrors($request, ['locale' => 'de']))->toBe([])
        ->and(requestErrors($request, ['locale' => 'es-ES']))->toBe(['locale'])
        ->and(requestErrors($request, []))->toBe(['locale']);
});

it('validates the dictionary request', function (): void {
    expect(requestErrors(new DictionaryRequest, ['locale' => 'it-IT']))->toBe([])
        ->and(requestErrors(new DictionaryRequest, ['locale' => 'xx']))->toBe(['locale'])
        ->and((new DictionaryRequest)->authorize())->toBeTrue();
});

it('validates a translation override', function (): void {
    $request = new UpsertTranslationRequest;

    expect(requestErrors($request, ['locale' => 'de-CH', 'value' => 'Rechnung', 'key' => 'invoicing.title']))->toBe([])
        ->and(requestErrors($request, ['locale' => 'de-CH', 'value' => 'x', 'key' => 'nogroup']))->toBe(['key'])
        ->and(requestErrors($request, ['locale' => 'de-CH', 'value' => '', 'key' => 'a.b']))->toBe(['value'])
        ->and(requestErrors($request, ['locale' => 'de-CH', 'value' => str_repeat('x', 5001), 'key' => 'a.b']))->toBe(['value']);
});

it('validates the translation list filters', function (): void {
    $request = new ListTranslationsRequest;

    expect(requestErrors($request, ['filter' => ['locale' => 'de-CH', 'group' => 'invoicing', 'missing' => '1', 'search' => 'x'], 'page' => 2, 'per_page' => 50]))->toBe([])
        ->and(requestErrors($request, ['filter' => ['locale' => 'de-CH', 'group' => 'in voicing']]))->toBe(['filter.group'])
        ->and(requestErrors($request, ['filter' => ['group' => 'a']]))->toBe(['filter.locale'])
        ->and(requestErrors($request, ['filter' => ['locale' => 'de-CH'], 'per_page' => 500]))->toBe(['per_page']);
});

it('validates the import file: xlsx or csv up to 5 MB', function (): void {
    $request = new ImportTranslationsRequest;
    $rules = $request->rules();

    expect($rules['file'])->toContain('required', 'file', 'max:5120')
        ->and(collect($rules['file'])->contains(fn (string $rule): bool => str_starts_with($rule, 'mimes:') && str_contains($rule, 'xlsx') && str_contains($rule, 'csv')))->toBeTrue()
        ->and(requestErrors($request, ['file' => UploadedFile::fake()->createWithContent('a.csv', "key,en\na.b,x\n")]))->toBe([])
        ->and(requestErrors($request, ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]))->toBe(['file'])
        ->and(requestErrors($request, ['file' => UploadedFile::fake()->create('a.csv', 5121, 'text/csv')]))->toBe(['file']);
});

it('validates the export request', function (): void {
    $request = new ExportTranslationsRequest;

    expect(requestErrors($request, ['locales' => ['it-CH', 'de-CH'], 'format' => 'csv']))->toBe([])
        ->and(requestErrors($request, ['locales' => []]))->toBe(['locales'])
        ->and(requestErrors($request, ['locales' => ['it-CH', 'it-CH']]))->toContain('locales.0')
        ->and(requestErrors($request, ['locales' => ['it-CH'], 'format' => 'pdf']))->toBe(['format']);
});

it('validates the locale settings', function (): void {
    $request = new UpdateLocaleSettingsRequest;

    expect(requestErrors($request, ['active_locales' => ['it-CH'], 'default_locale' => 'it-CH']))->toBe([])
        ->and(requestErrors($request, ['active_locales' => ['it-CH'], 'default_locale' => 'it-CH', 'number_format' => ['decimal_separator' => ',', 'thousands_separator' => ' '], 'date_format' => 'dd/MM/yyyy']))->toBe([])
        ->and(requestErrors($request, ['active_locales' => ['it-CH'], 'default_locale' => 'it-CH', 'number_format' => ['decimal_separator' => ',', 'thousands_separator' => null]]))->toBe([])
        ->and(requestErrors($request, ['active_locales' => ['it-CH'], 'default_locale' => 'it-CH', 'number_format' => ['decimal_separator' => ';', 'thousands_separator' => '.']]))->toBe(['number_format.decimal_separator'])
        ->and(requestErrors($request, ['active_locales' => ['it-CH'], 'default_locale' => 'it-CH', 'date_format' => 'MM/dd/yy']))->toBe(['date_format']);
});

it('validates the master data translations payload', function (): void {
    $request = new UpsertResourceTranslationRequest;

    expect(requestErrors($request, ['translations' => ['de-CH' => ['name' => 'x', 'description' => null]]]))->toBe([])
        ->and(requestErrors($request, ['translations' => []]))->toBe(['translations'])
        ->and(requestErrors($request, ['translations' => ['de-CH' => 'text']]))->toBe(['translations.de-CH'])
        ->and(requestErrors($request, ['translations' => ['de-CH' => ['name' => str_repeat('x', 5001)]]]))->toBe(['translations.de-CH.name']);
});

it('authorizes writes for owners and administrators only', function (): void {
    $request = fn (User $user): UpsertTranslationRequest => tap(new UpsertTranslationRequest, fn (UpsertTranslationRequest $r) => $r->setUserResolver(fn () => $user));

    $admin = locActor($this, 'admin');
    $accountant = locActor($this, 'accountant');

    expect($request($this->owner)->authorize())->toBeTrue()
        ->and($request($admin)->authorize())->toBeTrue()
        ->and($request($accountant)->authorize())->toBeFalse();
});

it('authorizes reads for every member of the tenant', function (): void {
    $request = fn (User $user): ListTranslationsRequest => tap(new ListTranslationsRequest, fn (ListTranslationsRequest $r) => $r->setUserResolver(fn () => $user));

    $accountant = locActor($this, 'accountant');

    expect($request($accountant)->authorize())->toBeTrue();
});
