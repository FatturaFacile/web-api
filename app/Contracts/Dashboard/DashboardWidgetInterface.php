<?php

declare(strict_types=1);

namespace App\Contracts\Dashboard;

/**
 * A building block of the home dashboard. Widgets are registered in the `WidgetRegistry`, placed on a 12 column grid
 * by the user and queried for their aggregated data.
 */
interface DashboardWidgetInterface
{
    /**
     * Unique identifier, for example `revenue_kpi`.
     */
    public function getId(): string;

    public function getTitle(): string;

    /**
     * How the client should draw the widget: `kpi_card`, `line_chart`, `bar_chart` or `data_table`.
     */
    public function getType(): string;

    /**
     * Grid size used when the widget is placed without an explicit one.
     *
     * @return array{w: int, h: int}
     */
    public function getDefaultDimensions(): array;

    /**
     * Options the user may configure per widget instance: option name => `type` (`enum` or `integer`), `default` and
     * either `values` or `min`/`max`.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getConfigurableOptions(): array;

    /**
     * Validation errors (option => message) of the given settings; empty when they are acceptable.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, string>
     */
    public function validateSettings(array $settings): array;

    /**
     * The aggregated data of the current tenant, computed with the given settings (defaults fill the gaps).
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function getData(array $settings = []): array;
}
