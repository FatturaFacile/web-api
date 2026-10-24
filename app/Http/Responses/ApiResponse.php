<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

trait ApiResponse
{
    /**
     * Return a standardized success JSON response.
     *
     * @param  array<string, mixed>  $meta
     */
    public function successResponse(
        mixed $data = null,
        string $message = 'Operation successful',
        int $statusCode = Response::HTTP_OK,
        array $meta = []
    ): JsonResponse {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if (! empty($meta)) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Return a standardized RFC 7807 Problem Details error response.
     *
     * @param  array<string, mixed>  $invalidParams
     */
    public function problemDetailsResponse(
        string $title,
        string $detail,
        int $status = Response::HTTP_BAD_REQUEST,
        ?string $type = null,
        ?string $instance = null,
        array $invalidParams = []
    ): JsonResponse {
        $type ??= "https://httpstatuses.com/{$status}";
        $instance ??= request()->getRequestUri();

        $problem = [
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => $instance,
        ];

        if (! empty($invalidParams)) {
            $problem['invalid_params'] = $invalidParams;
        }

        return response()->json($problem, $status, [
            'Content-Type' => 'application/problem+json',
        ]);
    }
}
