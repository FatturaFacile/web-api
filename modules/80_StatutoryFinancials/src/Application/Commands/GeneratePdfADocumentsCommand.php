<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

/**
 * Generates the PDF/A-1b / PDF/A-2b documents of the deposit package and verifies each of them before storing it:
 * the management report, the board minutes, the shareholders minutes and the consolidated dossier.
 */
final readonly class GeneratePdfADocumentsCommand
{
    /**
     * @param  list<string>  $kinds  management_report, board_minutes, shareholders_minutes, dossier
     * @param  array{meeting_date?: string, meeting_place?: string, chair?: string, secretary?: string, attendees?: list<string>}  $meeting
     */
    public function __construct(public string $dossierId, public array $kinds, public array $meeting = [], public ?string $userId = null) {}
}
