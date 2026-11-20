<?php

declare(strict_types=1);

namespace Modules\Academy\Commands;

use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Modules\Academy\Domain\Enums\AcademyLocale;
use Modules\Academy\Models\AcademyCertificate;
use Modules\Academy\Models\AcademyTexts;

/**
 * Renders the certificate as a PDF carrying a QR code that points to the public verification endpoint. The wording comes
 * from the translated texts of the track (seeded content), in the language requested and its fallbacks.
 */
final readonly class IssueAcademyCertificatePdfCommand
{
    public static function verificationUrl(string $certificateCode): string
    {
        return url('/api/v1/academy/certificates/verify/'.rawurlencode($certificateCode));
    }

    public function execute(AcademyCertificate $certificate, AcademyLocale $locale): string
    {
        $track = $certificate->track()->firstOrFail();
        $texts = $track->translations_json;
        $qr = (new PngWriter)->write(new QrCode(
            data: self::verificationUrl($certificate->certificate_code),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 220,
            margin: 0,
            roundBlockSizeMode: RoundBlockSizeMode::Enlarge,
        ))->getDataUri();

        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $pick = static fn (string $field): string => AcademyTexts::pick($texts, $field, $locale);

        $html = '<html><head><meta charset="utf-8"><style>'
            .'body{font-family:DejaVu Sans,sans-serif;text-align:center;color:#1f2937;border:6px double #0f4c81;padding:40px}'
            .'h1{font-size:34px;margin:20px 0 10px}h2{font-size:26px;margin:24px 0}p{font-size:15px;margin:6px 0}'
            .'.code{font-family:monospace;font-size:13px;margin-top:14px}'
            .'</style></head><body>'
            .'<h1>'.$e($pick('certificate_heading')).'</h1>'
            .'<p>'.$e($pick('certificate_awarded_to')).'</p>'
            .'<h2>'.$e($certificate->holder_name).'</h2>'
            .'<p>'.$e($pick('certificate_body')).'</p>'
            .'<h2>'.$e($pick('title')).'</h2>'
            .'<p>'.$e($certificate->issued_at->toDateString()).' · XP '.$certificate->total_xp.'</p>'
            .'<p><img src="'.$qr.'" width="130" height="130" alt=""></p>'
            .'<p>'.$e($pick('certificate_verify_label')).'</p>'
            .'<p class="code">'.$e($certificate->certificate_code).'</p>'
            .'<p class="code">'.$e(self::verificationUrl($certificate->certificate_code)).'</p>'
            .'</body></html>';

        return Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output();
    }
}
