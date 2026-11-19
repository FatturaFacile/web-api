<?php

declare(strict_types=1);

namespace Modules\Analytics\Export;

/**
 * Draws a static chart snapshot of a pivot as SVG for the PDF export. Only fixed palette colors, numbers computed here and
 * escaped labels reach the markup. Bar-like types (bar, stacked bar, combo) are bars, line and area are polylines, pie and
 * donut are slices of the first series.
 */
final class SvgChartRenderer
{
    private const array Palette = ['#2f6fed', '#18a058', '#f0a020', '#d03050', '#8a5cf6', '#2db7b5'];

    private const int Width = 720;

    private const int Height = 280;

    private const int MaxCategories = 12;

    private const int MaxSeries = 5;

    /**
     * @param  array{type: string, categories: list<string>, series: list<array{name: string, data: list<mixed>}>}  $chart
     */
    public function render(array $chart): string
    {
        $categories = array_slice($chart['categories'], 0, self::MaxCategories);
        $series = array_map(static fn (array $item): array => ['name' => $item['name'], 'data' => array_map(static fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0, array_slice($item['data'], 0, self::MaxCategories))], array_slice($chart['series'], 0, in_array($chart['type'], ['pie', 'donut'], true) ? 1 : self::MaxSeries));

        if ($categories === [] || $series === []) {
            return '';
        }

        $body = in_array($chart['type'], ['pie', 'donut'], true) ? $this->pie($categories, $series[0]['data'], $chart['type'] === 'donut') : $this->axes($chart['type'], $categories, $series);
        $legend = $this->legend($chart['type'], $categories, $series);

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.self::Width.'" height="'.self::Height.'" viewBox="0 0 '.self::Width.' '.self::Height.'"><rect width="100%" height="100%" fill="#ffffff"/>'.$body.$legend.'</svg>';
    }

    /**
     * @param  list<string>  $categories
     * @param  list<array{name: string, data: list<float>}>  $series
     */
    private function axes(string $type, array $categories, array $series): string
    {
        $left = 60;
        $top = 16;
        $width = self::Width - $left - 16;
        $height = self::Height - $top - 70;
        $stacked = $type === 'stacked_bar';
        $maxima = [];

        foreach ($categories as $index => $_) {
            $maxima[] = $stacked ? array_sum(array_map(static fn (array $item): float => max(0.0, $item['data'][$index] ?? 0.0), $series)) : max(array_map(static fn (array $item): float => $item['data'][$index] ?? 0.0, $series));
        }

        $max = max(1.0, ...$maxima);
        $svg = '<line x1="'.$left.'" y1="'.($top + $height).'" x2="'.($left + $width).'" y2="'.($top + $height).'" stroke="#999"/>'.'<line x1="'.$left.'" y1="'.$top.'" x2="'.$left.'" y2="'.($top + $height).'" stroke="#999"/>';
        $svg .= '<text x="'.($left - 6).'" y="'.($top + 8).'" font-size="9" text-anchor="end" fill="#555">'.e($this->short($max)).'</text><text x="'.($left - 6).'" y="'.($top + $height).'" font-size="9" text-anchor="end" fill="#555">0</text>';
        $step = $width / count($categories);

        foreach ($categories as $index => $label) {
            $x = $left + $index * $step;
            $svg .= '<text x="'.round($x + $step / 2, 1).'" y="'.($top + $height + 14).'" font-size="9" text-anchor="middle" fill="#555">'.e(mb_strimwidth($label, 0, 14, '…')).'</text>';
        }

        if (in_array($type, ['line', 'area'], true)) {
            foreach ($series as $number => $item) {
                $points = [];

                foreach ($item['data'] as $index => $value) {
                    $points[] = round($left + $index * $step + $step / 2, 1).','.round($top + $height - ($value / $max) * $height, 1);
                }

                $color = self::Palette[$number % count(self::Palette)];

                if ($type === 'area') {
                    $svg .= '<polygon points="'.round($left + $step / 2, 1).','.($top + $height).' '.implode(' ', $points).' '.round($left + (count($points) - 1) * $step + $step / 2, 1).','.($top + $height).'" fill="'.$color.'" fill-opacity="0.25"/>';
                }

                $svg .= '<polyline points="'.implode(' ', $points).'" fill="none" stroke="'.$color.'" stroke-width="2"/>';
            }

            return $svg;
        }

        $groupWidth = $step * 0.7;

        foreach ($categories as $index => $_) {
            $base = $top + $height;

            foreach ($series as $number => $item) {
                $value = max(0.0, $item['data'][$index] ?? 0.0);
                $barHeight = ($value / $max) * $height;
                $barWidth = $stacked ? $groupWidth : $groupWidth / count($series);
                $x = $left + $index * $step + ($step - $groupWidth) / 2 + ($stacked ? 0 : $number * $barWidth);
                $y = $stacked ? $base - $barHeight : $top + $height - $barHeight;
                $svg .= '<rect x="'.round($x, 1).'" y="'.round($y, 1).'" width="'.round($barWidth, 1).'" height="'.round($barHeight, 1).'" fill="'.self::Palette[$number % count(self::Palette)].'"/>';

                if ($stacked) {
                    $base -= $barHeight;
                }
            }
        }

        return $svg;
    }

    /**
     * @param  list<string>  $categories
     * @param  list<float>  $data
     */
    private function pie(array $categories, array $data, bool $donut): string
    {
        $total = array_sum(array_map(static fn (float $value): float => max(0.0, $value), $data));

        if ($total <= 0) {
            return '';
        }

        $cx = 160.0;
        $cy = 130.0;
        $radius = 105.0;
        $angle = -M_PI / 2;
        $svg = '';

        foreach ($data as $index => $value) {
            $slice = max(0.0, $value) / $total * 2 * M_PI;

            if ($slice <= 0) {
                continue;
            }

            $end = $angle + $slice;
            $large = $slice > M_PI ? 1 : 0;
            $color = self::Palette[$index % count(self::Palette)];

            if ($slice >= 2 * M_PI - 0.0001) {
                $svg .= '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$radius.'" fill="'.$color.'"/>';
            } else {
                $svg .= '<path d="M'.$cx.','.$cy.' L'.round($cx + $radius * cos($angle), 2).','.round($cy + $radius * sin($angle), 2).' A'.$radius.','.$radius.' 0 '.$large.' 1 '.round($cx + $radius * cos($end), 2).','.round($cy + $radius * sin($end), 2).' Z" fill="'.$color.'"/>';
            }

            $angle = $end;
        }

        return $svg.($donut ? '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.($radius * 0.55).'" fill="#ffffff"/>' : '');
    }

    /**
     * @param  list<string>  $categories
     * @param  list<array{name: string, data: list<float>}>  $series
     */
    private function legend(string $type, array $categories, array $series): string
    {
        $pie = in_array($type, ['pie', 'donut'], true);
        $names = $pie ? $categories : array_column($series, 'name');
        $svg = '';
        $x = $pie ? 340 : 60;
        $y = $pie ? 40 : self::Height - 28;

        foreach ($names as $index => $name) {
            $svg .= '<rect x="'.$x.'" y="'.$y.'" width="10" height="10" fill="'.self::Palette[$index % count(self::Palette)].'"/><text x="'.($x + 14).'" y="'.($y + 9).'" font-size="9" fill="#333">'.e(mb_strimwidth((string) $name, 0, 28, '…')).'</text>';

            if ($pie) {
                $y += 16;
            } else {
                $x += 150;
            }
        }

        return $svg;
    }

    private function short(float $value): string
    {
        return match (true) {
            $value >= 1_000_000 => round($value / 1_000_000, 1).'M',
            $value >= 1_000 => round($value / 1_000, 1).'k',
            default => (string) round($value, 1),
        };
    }
}
