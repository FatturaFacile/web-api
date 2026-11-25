<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Pdf;

use Modules\StatutoryFinancials\Exceptions\StatutoryFinancialsException;

/**
 * Creates PDF/A documents with the embedded fonts and the sRGB output intent profile configured for the module.
 */
final class PdfADocumentFactory
{
    /** @var array<string, TtfFont> */
    private array $fonts = [];

    private ?string $icc = null;

    public function make(string $profile, string $title, string $author, string $subject, string $locale): PdfADocument
    {
        return new PdfADocument(
            profile: $profile,
            title: $title,
            author: $author,
            subject: $subject,
            language: match ($locale) {
                'de' => 'de-CH',
                'fr' => 'fr-CH',
                'en' => 'en-GB',
                default => 'it-IT',
            },
            regular: $this->font('regular', 'DejaVuSans'),
            bold: $this->font('bold', 'DejaVuSans-Bold'),
            iccProfile: $this->icc(),
        );
    }

    private function font(string $variant, string $name): TtfFont
    {
        return $this->fonts[$variant] ??= TtfFont::load((string) config('statutory_financials.pdf.font_'.$variant), $name);
    }

    private function icc(): string
    {
        if ($this->icc !== null) {
            return $this->icc;
        }

        $data = @file_get_contents((string) config('statutory_financials.pdf.icc_profile'));

        if ($data === false || $data === '') {
            throw new StatutoryFinancialsException('pdfa_profile_missing', 500);
        }

        return $this->icc = $data;
    }
}
