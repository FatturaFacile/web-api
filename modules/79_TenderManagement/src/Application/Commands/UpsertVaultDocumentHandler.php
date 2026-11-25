<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Application\Commands;

use Modules\TenderManagement\Models\QualificationVaultDocument;
use Modules\TenderManagement\Services\VaultService;

final class UpsertVaultDocumentHandler
{
    public function __construct(private readonly VaultService $vault) {}

    public function handle(UpsertVaultDocumentCommand $command): QualificationVaultDocument
    {
        if ($command->documentId === null) {
            return $this->vault->create($command->data, $command->file, $command->userId);
        }

        $document = QualificationVaultDocument::query()->findOrFail($command->documentId);

        return $command->renew
            ? $this->vault->renew($document, $command->data, $command->file, $command->userId)
            : $this->vault->update($document, array_merge(['doc_type' => $document->doc_type->value], $command->data), $command->file, $command->userId);
    }
}
