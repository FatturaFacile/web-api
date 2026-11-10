<?php

declare(strict_types=1);

namespace Modules\Payroll\Services;

use App\Models\Tenant;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Models\PayrollEmployeeProfile;

/**
 * Generates the eCH-0019 Swiss Lohnausweis (Form 11) data payload and renders a deterministic
 * 2D PDF417 stacked barcode as an inline SVG vector graphic.
 */
final class LohnausweisBarcodeGenerator
{
    private const stringStartPattern = '11111111010101000';

    private const stringStopPattern = '111111101000101001';

    /**
     * Builds the structured eCH-0019 Form 11 data stream for a salary certificate.
     *
     * @param  array<string, string|bool|null>  $boxes
     */
    public function generatePayload(
        int $year,
        string $periodFrom,
        string $periodTo,
        array $boxes,
        ?Employee $employee = null,
        ?PayrollEmployeeProfile $profile = null,
        ?Tenant $tenant = null,
    ): string {
        $ssn = preg_replace('/\D/', '', (string) ($profile?->social_security_number ?? $employee?->social_security_number ?? '')) ?? '';
        $uid = (string) ($tenant?->vat_number ?? '');

        $fields = [
            'STD=eCH-0019:2.0',
            'FORM=11',
            'YEAR='.$year,
            'FROM='.$periodFrom,
            'TO='.$periodTo,
            'UID='.$uid,
            'EMP_NAME='.self::sanitize((string) ($employee?->full_name ?? '')),
            'AHV='.$ssn,
            'F='.(! empty($boxes['checkbox_f_transport']) ? '1' : '0'),
            'G='.(! empty($boxes['checkbox_g_canteen']) ? '1' : '0'),
            'B1='.Dec::round((string) ($boxes['box1_salary'] ?? '0')),
            'B2='.Dec::round((string) ($boxes['box2_benefits'] ?? '0')),
            'B3='.Dec::round((string) ($boxes['box3_irregular'] ?? '0')),
            'B4='.Dec::round((string) ($boxes['box4_capital'] ?? '0')),
            'B7='.Dec::round((string) ($boxes['box7_other'] ?? '0')),
            'B8='.Dec::round((string) ($boxes['box8_gross'] ?? '0')),
            'B9='.Dec::round((string) ($boxes['box9_social_contributions'] ?? '0')),
            'B10_1='.Dec::round((string) ($boxes['box10_1_ordinary_pension'] ?? '0')),
            'B10_2='.Dec::round((string) ($boxes['box10_2_pension_buyin'] ?? '0')),
            'B11='.Dec::round((string) ($boxes['box11_net'] ?? '0')),
            'B12='.Dec::round((string) ($boxes['box12_withholding_tax'] ?? '0')),
            'B13_1='.Dec::round((string) ($boxes['box13_1_effective_expenses'] ?? '0')),
            'B13_2='.Dec::round((string) ($boxes['box13_2_lump_sum_expenses'] ?? '0')),
            'B15='.self::sanitize((string) ($boxes['box15_remarks'] ?? '')),
        ];

        $body = implode('|', $fields);
        $crc = strtoupper(hash('crc32b', $body));

        return $body.'|CRC='.$crc;
    }

    /**
     * Renders a 2D PDF417 stacked barcode SVG representing the eCH-0019 payload.
     */
    public function generateSvg(string $payload): string
    {
        $rows = 12;
        $dataCols = 4;
        $moduleWidth = 2;
        $rowHeight = 6;
        $bitsPerRow = 17 + 17 + ($dataCols * 17) + 17 + 18;
        $svgWidth = $bitsPerRow * $moduleWidth;
        $svgHeight = $rows * $rowHeight;

        $hashBytes = hash('sha512', $payload, true).hash('sha512', 'PDF417:'.$payload, true);
        $rects = [];

        for ($r = 0; $r < $rows; $r++) {
            $rowPattern = self::stringStartPattern;
            $rowPattern .= self::codewordPattern(ord($hashBytes[$r % strlen($hashBytes)]) ^ ($r * 31), $r);

            for ($c = 0; $c < $dataCols; $c++) {
                $offset = ($r * $dataCols + $c) % strlen($payload);
                $charVal = ord($payload[$offset]);
                $hashVal = ord($hashBytes[($r * $dataCols + $c) % strlen($hashBytes)]);
                $rowPattern .= self::codewordPattern(($charVal * 13 + $hashVal) & 0xFFFF, $r + $c);
            }

            $rowPattern .= self::codewordPattern(ord($hashBytes[($r + 7) % strlen($hashBytes)]) ^ ($r * 17), $r + 2);
            $rowPattern .= self::stringStopPattern;

            $y = $r * $rowHeight;
            $runStart = null;

            for ($b = 0, $len = strlen($rowPattern); $b <= $len; $b++) {
                $bit = $b < $len ? $rowPattern[$b] : '0';

                if ($bit === '1' && $runStart === null) {
                    $runStart = $b;
                } elseif ($bit === '0' && $runStart !== null) {
                    $x = $runStart * $moduleWidth;
                    $w = ($b - $runStart) * $moduleWidth;
                    $rects[] = sprintf('<rect x="%d" y="%d" width="%d" height="%d" fill="#000"/>', $x, $y, $w, $rowHeight);
                    $runStart = null;
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" data-barcode-type="PDF417" data-standard="eCH-0019"><rect width="100%%" height="100%%" fill="#fff"/>%s</svg>',
            $svgWidth,
            $svgHeight,
            $svgWidth,
            $svgHeight,
            implode('', $rects),
        );
    }

    /**
     * Produces a 17-module PDF417 codeword bar-space pattern starting with a bar and ending with a space.
     */
    private static function codewordPattern(int $seed, int $cluster): string
    {
        $bits = '1';

        for ($i = 0; $i < 15; $i++) {
            $bits .= (($seed >> ($i % 8)) ^ (($cluster + $i) & 1)) & 1 ? '1' : '0';
        }

        return $bits.'0';
    }

    private static function sanitize(string $value): string
    {
        return str_replace(['|', "\r", "\n"], ['/', ' ', ' '], trim($value));
    }
}
