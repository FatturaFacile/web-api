<?php

declare(strict_types=1);

namespace Modules\Childcare\Models;

/**
 * A form every child must return, signed or not (enrolment form, photo consent, medication authorisation).
 */
final class DocumentRequirement extends ChildcareRecord
{
    protected $table = 'cc_document_requirements';

    protected function casts(): array
    {
        return ['requires_signature' => 'boolean', 'is_mandatory' => 'boolean'];
    }
}
