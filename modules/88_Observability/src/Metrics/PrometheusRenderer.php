<?php

declare(strict_types=1);

namespace Modules\Observability\Metrics;

/**
 * Prometheus text exposition format 0.0.4.
 */
final class PrometheusRenderer
{
    public const string CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    /**
     * @param  array<string, array{type: MetricType, help: string, series: list<array{labels: array<string, string>, value: float, buckets?: array<string, int>, sum?: float, count?: int}>}>  $metrics
     */
    public function render(array $metrics): string
    {
        $lines = [];

        foreach ($metrics as $name => $metric) {
            $lines[] = '# HELP '.$name.' '.str_replace(['\\', "\n"], ['\\\\', '\\n'], $metric['help']);
            $lines[] = '# TYPE '.$name.' '.$metric['type']->value;

            foreach ($metric['series'] as $series) {
                if ($metric['type'] === MetricType::Histogram) {
                    foreach ($series['buckets'] ?? [] as $bound => $count) {
                        $lines[] = $name.'_bucket'.$this->labels($series['labels'] + ['le' => $this->number((float) $bound)]).' '.$count;
                    }

                    $lines[] = $name.'_bucket'.$this->labels($series['labels'] + ['le' => '+Inf']).' '.($series['count'] ?? 0);
                    $lines[] = $name.'_sum'.$this->labels($series['labels']).' '.$this->number($series['sum'] ?? 0.0);
                    $lines[] = $name.'_count'.$this->labels($series['labels']).' '.($series['count'] ?? 0);

                    continue;
                }

                $lines[] = $name.$this->labels($series['labels']).' '.$this->number($series['value']);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $pairs = [];

        foreach ($labels as $key => $value) {
            $pairs[] = $key.'="'.str_replace(['\\', "\n", '"'], ['\\\\', '\\n', '\\"'], $value).'"';
        }

        return '{'.implode(',', $pairs).'}';
    }

    private function number(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }

        if (floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(number_format($value, 9, '.', ''), '0'), '.');
    }
}
