<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class SealWormBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
