<?php

declare(strict_types=1);

namespace Modules\Observability\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Modules\Observability\Metrics\MetricsRegistry;
use Modules\Observability\Metrics\PrometheusRenderer;
use Modules\Observability\Metrics\ScrapeTimeCollector;

/**
 * Prometheus scrape endpoint (`/metrics`), guarded by AuthenticateMetricsScrape (bearer token and/or allowed addresses).
 */
final class MetricsController extends Controller
{
    public function __invoke(MetricsRegistry $registry, ScrapeTimeCollector $collector, PrometheusRenderer $renderer): Response
    {
        $collector->collect();

        return response($renderer->render($registry->collect()), 200, ['Content-Type' => PrometheusRenderer::CONTENT_TYPE, 'Cache-Control' => 'no-store']);
    }
}
