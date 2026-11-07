<?php

declare(strict_types=1);

namespace Modules\Associations\OpenApi;

use OpenApi\Attributes as OA;

/**
 * What the OpenAPI operations of the module have in common: the tag, the security of each audience, the path parameters
 * taken from the path, and the error responses.
 */
final class OperationArguments
{
    public const string Staff = 'staff';

    public const string Public = 'public';

    public const string Area = 'area';

    public const string Device = 'device';

    /**
     * @param  list<string>  $query  names of the optional query parameters
     * @return array<string, mixed>
     */
    public static function for(string $path, string $operationId, string $summary, string $audience, ?string $body, array $query, bool $multipart, bool $file, ?string $response = null): array
    {
        return [
            'path' => $path,
            'operationId' => $operationId,
            'summary' => $summary,
            'description' => $summary.'.',
            'security' => self::security($audience),
            'requestBody' => self::requestBody($body, $multipart),
            'tags' => ['Associations'],
            'parameters' => self::parameters($path, $query),
            'responses' => self::responses($audience, $file, $response),
        ];
    }

    /**
     * @return list<array<string, list<string>>>
     */
    private static function security(string $audience): array
    {
        return match ($audience) {
            self::Staff => [['bearerAuth' => []]],
            self::Area => [['associationAreaToken' => []]],
            self::Device => [['associationDeviceKey' => [], 'associationDeviceSecret' => []]],
            default => [],
        };
    }

    private static function requestBody(?string $schema, bool $multipart): ?OA\RequestBody
    {
        if ($schema === null) {
            return null;
        }

        $reference = "#/components/schemas/{$schema}";

        return new OA\RequestBody(
            required: true,
            content: $multipart
                ? new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: $reference))
                : new OA\JsonContent(ref: $reference),
        );
    }

    /**
     * @param  list<string>  $query
     * @return list<OA\Parameter>
     */
    private static function parameters(string $path, array $query): array
    {
        preg_match_all('/\{(\w+)\}/', $path, $matches);

        $inPath = array_map(static fn (string $name): OA\Parameter => new OA\Parameter(name: $name, in: 'path', required: true, schema: new OA\Schema(type: 'string')), $matches[1]);
        $inQuery = array_map(static fn (string $name): OA\Parameter => new OA\Parameter(name: $name, in: 'query', required: false, schema: new OA\Schema(type: 'string')), $query);

        return [...$inPath, ...$inQuery];
    }

    /**
     * @return list<OA\Response>
     */
    private static function responses(string $audience, bool $file, ?string $response = null): array
    {
        $success = match (true) {
            $file => new OA\Response(response: 200, description: 'The file', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))),
            $response !== null => new OA\Response(response: 200, description: 'Request completed', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: "#/components/schemas/{$response}"),
                new OA\Property(property: 'meta', type: 'object', nullable: true, additionalProperties: true),
            ], type: 'object')),
            default => new OA\Response(response: 200, description: 'Request completed', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        };

        $errors = [
            new OA\Response(ref: '#/components/responses/AssociationNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/AssociationConflict', response: 409),
            new OA\Response(ref: '#/components/responses/AssociationUnprocessable', response: 422),
        ];

        if ($audience !== self::Public) {
            array_unshift($errors, new OA\Response(ref: '#/components/responses/AssociationUnauthenticated', response: 401));
        }

        if ($audience === self::Staff) {
            array_unshift($errors, new OA\Response(ref: '#/components/responses/AssociationForbidden', response: 403));
        }

        return [$success, ...$errors];
    }
}
