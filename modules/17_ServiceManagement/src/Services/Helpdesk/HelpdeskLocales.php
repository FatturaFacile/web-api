<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

/**
 * The content locales of the helpdesk: canned responses and knowledge base articles are written in one of them. Swiss
 * Italian, German and French, English and Italian of Italy.
 */
final class HelpdeskLocales
{
    /**
     * @var list<string>
     */
    public const array All = ['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT'];

    /**
     * The language of the UI dictionaries (it, de, fr, en) a content locale belongs to.
     */
    public static function language(string $locale): string
    {
        return explode('-', $locale)[0];
    }
}
