<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Handlers;

use Modules\DigitalPreservation\Application\Commands\ConvertToPdfACommand;
use Modules\DigitalPreservation\Domain\Enums\AuditAction;
use Modules\DigitalPreservation\Infrastructure\Services\PdfAConformanceService;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationAuditLogger;

/**
 * Converts a document to PDF/A (or returns it unchanged when it already is an archival format).
 */
final readonly class ConvertToPdfAHandler
{
    public function __construct(private PdfAConformanceService $pdfa, private PreservationAuditLogger $audit) {}

    /**
     * @return array{content: string, mime: string, filename: string, level: ?string, pdfa: bool, converted: bool}
     */
    public function handle(ConvertToPdfACommand $command): array
    {
        $result = $this->pdfa->ensureArchival($command->content, $command->mime, $command->filename, $command->level);

        if ($result['converted']) {
            $this->audit->record(AuditAction::PdfaConverted, null, null, ['level' => $result['level'], 'file' => $command->filename], hash('sha256', $result['content']));
        }

        return $result;
    }
}
