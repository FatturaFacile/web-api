<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Modules\Manufacturing\Support\MesProblem;

/**
 * Base of the requests of the MES Core: the caller is authenticated by the signature middleware, and a validation
 * failure is answered as an RFC 9457 problem instead of the default error shape.
 */
abstract class MesApiRequest extends FormRequest
{
    /**
     * The signature middleware authenticated the caller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Answers a validation failure as `application/problem+json`.
     */
    protected function failedValidation(Validator $validator): never
    {
        $errors = [];

        foreach ($validator->errors()->messages() as $field => $messages) {
            foreach ($messages as $message) {
                $errors[] = ['pointer' => '/'.str_replace('.', '/', $field), 'message' => $message];
            }
        }

        throw new HttpResponseException(MesProblem::response(422, 'request.invalid', 'Invalid request', 'The request is not valid.', false, $errors));
    }
}
