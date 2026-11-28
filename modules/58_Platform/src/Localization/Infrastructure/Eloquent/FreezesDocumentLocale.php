<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Infrastructure\Eloquent;

use Illuminate\Validation\ValidationException;
use Modules\Platform\Localization\Infrastructure\Services\DocumentLocaleFreezer;

/**
 * Gives a sales or purchase document a frozen print language. The locale is resolved once, when the document is created
 * (contact document language, else company default), and can be changed only while the document is a draft: an issued
 * document is always printed in the language it was issued in.
 *
 * Models can override documentDraftStatuses() (default: draft), documentStatusAttribute() and documentContactId().
 */
trait FreezesDocumentLocale
{
    public static function bootFreezesDocumentLocale(): void
    {
        static::creating(static function (self $document): void {
            $locale = $document->getAttribute('locale');

            if (! is_string($locale) || $locale === '') {
                $document->setAttribute('locale', app(DocumentLocaleFreezer::class)->resolve(
                    $document->getAttribute('tenant_id') !== null ? (string) $document->getAttribute('tenant_id') : null,
                    $document->documentContactId(),
                ));
            }
        });

        static::updating(static function (self $document): void {
            if (! $document->isDirty('locale')) {
                return;
            }

            $status = (string) $document->getOriginal($document->documentStatusAttribute());

            if (! in_array($status, $document->documentDraftStatuses(), true)) {
                throw ValidationException::withMessages(['locale' => __('localization.document.locale_frozen')]);
            }
        });
    }

    public function initializeFreezesDocumentLocale(): void
    {
        // A guarded model (empty fillable list) already accepts the column: widening the list would close it for the rest
        if ($this->getFillable() !== []) {
            $this->mergeFillable(['locale']);
        }
    }

    /**
     * @return list<string>
     */
    public function documentDraftStatuses(): array
    {
        return ['draft'];
    }

    public function documentStatusAttribute(): string
    {
        return 'status';
    }

    /**
     * The contact (customer or supplier) whose document language the document inherits.
     */
    public function documentContactId(): ?string
    {
        $contactId = $this->getAttribute('contact_id');

        return is_string($contactId) && $contactId !== '' ? $contactId : null;
    }

    /**
     * Whether the print language can still be changed.
     */
    public function isDocumentLocaleEditable(): bool
    {
        return in_array((string) $this->getAttribute($this->documentStatusAttribute()), $this->documentDraftStatuses(), true);
    }
}
