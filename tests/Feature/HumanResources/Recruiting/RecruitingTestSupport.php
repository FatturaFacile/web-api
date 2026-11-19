<?php

declare(strict_types=1);

namespace Tests\Feature\HumanResources\Recruiting;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\JobOpening;
use Modules\HumanResources\Models\Position;
use Modules\HumanResources\Models\RecruitingSettings;

/**
 * Shared helpers of the recruiting feature tests (loaded with require_once, not a test file).
 */
function useTenant(Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
}

/**
 * Tenant with an authenticated member acting through Sanctum.
 *
 * @return array{0: Tenant, 1: User}
 */
function recruiterOfNewTenant(): array
{
    $tenant = Tenant::factory()->create();
    useTenant($tenant);

    $user = User::factory()->create();
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user, ['*']);

    return [$tenant, $user];
}

function recruitingActAs(User $user, Tenant $tenant): void
{
    Sanctum::actingAs($user, ['*']);
    useTenant($tenant);
}

function positionFor(Tenant $tenant, int $headcount = 5): Position
{
    useTenant($tenant);
    $department = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Engineering']);

    return Position::factory()->create([
        'tenant_id' => $tenant->id,
        'department_id' => $department->id,
        'headcount_budget' => $headcount,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function publishedOpening(Tenant $tenant, array $attributes = [], ?Position $position = null): JobOpening
{
    useTenant($tenant);
    $position ??= positionFor($tenant);

    return JobOpening::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'position_id' => $position->id,
        'department_id' => $position->department_id,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function careersSettings(Tenant $tenant, array $attributes = []): RecruitingSettings
{
    useTenant($tenant);

    return RecruitingSettings::factory()->create([
        'tenant_id' => $tenant->id,
        ...$attributes,
    ]);
}

/**
 * Minimal valid PDF whose content stream draws one text line per entry (uncompressed `Tj` operators).
 *
 * @param  list<string>  $lines
 */
function pdfCv(array $lines, string $fileName = 'cv.pdf'): UploadedFile
{
    $stream = "BT /F1 12 Tf 72 720 Td\n";
    foreach ($lines as $line) {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
        $stream .= "({$escaped}) Tj 0 -16 Td\n";
    }
    $stream .= 'ET';

    $pdf = "%PDF-1.4\n"
        ."1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
        ."2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
        ."3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> /Contents 4 0 R >> endobj\n"
        .'4 0 obj << /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream\nendobj\n"
        ."trailer << /Root 1 0 R >>\n%%EOF";

    return UploadedFile::fake()->createWithContent($fileName, $pdf);
}

/**
 * @return list<string>
 */
function sampleCvLines(): array
{
    return [
        'Giulia Verdi',
        'giulia.verdi@example.com +41 91 123 45 67',
        'Experience',
        'Acme SA, Lugano 2019 - 2024',
        'Education',
        'USI Lugano 2015 - 2018',
        'Skills',
        'PHP, Laravel, PostgreSQL, Docker',
        'Languages',
        'Italiano madrelingua, English C1',
    ];
}
