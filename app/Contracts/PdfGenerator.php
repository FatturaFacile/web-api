<?php

declare(strict_types=1);

namespace App\Contracts;

interface PdfGenerator
{
    /**
     * Render a Blade view into a PDF document.
     *
     * @param  array<string, mixed>  $data  view data
     * @return string the binary PDF content
     */
    public function render(string $view, array $data = []): string;
}
