<?php

declare(strict_types=1);

use Database\Seeders\FrontendTranslationSeeder;
use Modules\Platform\Localization\Infrastructure\Console\FrontendKeyScanner;

/**
 * A throw-away frontend tree with the given files (path => content).
 *
 * @param  array<string, string>  $files
 */
function fakeFrontend(array $files): string
{
    $root = sys_get_temp_dir().'/i18n-frontend-'.bin2hex(random_bytes(4));

    foreach ($files as $path => $content) {
        $file = $root.'/src/'.$path;
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, $content);
    }

    return $root;
}

function removeTree(string $root): void
{
    if (! is_dir($root)) {
        return;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($root);
}

it('finds the static keys of templates and code', function (): void {
    $keys = (new FrontendKeyScanner)->keysIn(<<<'SRC'
        <h1>{{ 'settings.localization.title' | translate }}</h1>
        <input [placeholder]="'settings.translations.search' | translate: { count: 3 }" />
        this.i18n.translate('common.locale.en');
        const label = this.i18n.instant("common.locale.de_CH", { n: 1 });
        const other = i18n.translateIn(lang, 'document.translation.print');
        const dynamic = this.i18n.translate(`common.locale.${code}`);
        const notAKey = 'just a string' | translate;
        const single = this.i18n.translate('nokeyhere');
        SRC);

    expect($keys)->toEqualCanonicalizing([
        'settings.localization.title',
        'settings.translations.search',
        'common.locale.en',
        'common.locale.de_CH',
        'document.translation.print',
    ]);
});

it('ignores specs, declarations and dependencies when scanning a tree', function (): void {
    $root = fakeFrontend([
        'app/a.component.html' => "{{ 'real.key' | translate }}",
        'app/a.component.spec.ts' => "i18n.translate('spec.only.key');",
        'app/types.d.ts' => "translate('types.only.key');",
        'node_modules/x/index.ts' => "translate('vendor.key');",
    ]);

    try {
        expect((new FrontendKeyScanner)->scan($root.'/src'))->toBe(['real.key' => 'app/a.component.html']);
    } finally {
        removeTree($root);
    }
});

it('succeeds when every key the frontend uses is seeded in the four languages', function (): void {
    $root = fakeFrontend(['app/ok.component.html' => "{{ 'statutory.ch.vat_suffix' | translate }} {{ 'common.locale.de_CH' | translate }}"]);

    try {
        $this->artisan('i18n:check-missing', ['--frontend' => $root])->expectsOutputToContain('0 missing')->assertExitCode(0);
    } finally {
        removeTree($root);
    }
});

it('fails with a table of the missing keys and a non-zero exit code', function (): void {
    $root = fakeFrontend([
        'app/bad.component.html' => "{{ 'definitely.not.seeded.anywhere' | translate }}",
        'app/bad.component.ts' => "this.i18n.translate('another.missing.key');",
    ]);

    try {
        $this->artisan('i18n:check-missing', ['--frontend' => $root])
            ->expectsOutputToContain('definitely.not.seeded.anywhere')
            ->expectsOutputToContain('another.missing.key')
            ->expectsOutputToContain('it, en, de, fr')
            ->assertExitCode(1);
    } finally {
        removeTree($root);
    }
});

it('reports a frontend path that does not exist', function (): void {
    $this->artisan('i18n:check-missing', ['--frontend' => sys_get_temp_dir().'/no-such-frontend-'.bin2hex(random_bytes(4))])
        ->expectsOutputToContain('Frontend sources not found')
        ->assertExitCode(2);
});

it('knows the statutory and UI keys of the localization engine in every language', function (): void {
    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        expect(FrontendTranslationSeeder::dictionary($locale))->toHaveKey('statutory.it.natura.n1')->toHaveKey('settings.localization.title');
    }
});
