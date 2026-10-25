<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * Validates incoming request Content-Length early during FormRequest lifecycle
 * to mitigate uncontrolled resource consumption (CWE-400 / php:S5689).
 */
trait ValidatesUploadContentLength
{
    /**
     * Guard against excessive request content lengths before processing.
     */
    protected function prepareForValidation(): void
    {
        $this->validateUploadContentLength();
    }

    /**
     * Verify and enforce upper boundary on incoming Content-Length.
     */
    protected function validateUploadContentLength(): void
    {
        $contentLength = (int) ($this->server('CONTENT_LENGTH') ?: $this->header('Content-Length', 0));
        $maxBytes = $this->maxContentLengthBytes();

        if ($contentLength > $maxBytes) {
            abort(413, 'Uploaded payload exceeds the maximum allowed content length.');
        }
    }

    /**
     * Get the maximum allowed content length in bytes.
     */
    protected function maxContentLengthBytes(): int
    {
        return property_exists($this, 'maxContentLengthBytes')
            ? $this->maxContentLengthBytes
            : 20 * 1024 * 1024;
    }
}
