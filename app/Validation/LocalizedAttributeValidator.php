<?php

declare(strict_types=1);

namespace App\Validation;

use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Validator that names a field in the language of the request.
 *
 * lang/<locale>/validation_attributes.php holds one translated name per field of the API, keyed by the last segment of
 * the field (company_name, street). Laravel only looks the field up by its full dotted key, which for address.street
 * would mean a nested array; here the last segment is tried too, so address.street and lines.0.street share one entry.
 * A name given by the request itself (attributes()) or by the full key still wins.
 */
class LocalizedAttributeValidator extends Validator
{
    /**
     * @param  string  $attribute
     */
    public function getDisplayableAttribute($attribute): string
    {
        $name = parent::getDisplayableAttribute($attribute);

        if ($name !== str_replace('_', ' ', Str::snake($attribute))) {
            return $name;
        }

        $leaf = Str::afterLast($attribute, '.');
        $key = "validation.attributes.{$leaf}";
        $translated = $this->translator->get($key);

        return is_string($translated) && $translated !== $key ? $translated : $name;
    }
}
