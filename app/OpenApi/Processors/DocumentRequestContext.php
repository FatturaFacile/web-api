<?php

declare(strict_types=1);

namespace App\OpenApi\Processors;

use App\Http\Middleware\AuthenticateScim;
use App\Http\Middleware\IdentifyTenant;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Routing\Router;
use OpenApi\Analysis;
use OpenApi\Annotations\Operation;
use OpenApi\Annotations\Parameter;
use OpenApi\Annotations\PathItem;
use OpenApi\Annotations\Response;
use OpenApi\Generator;

/**
 * Aligns every documented operation with the middleware of its real route, so that the specification cannot drift from
 * what the API enforces: tenant-scoped operations reference the reusable request context parameters and answer 400 and
 * 403 accordingly, and the security requirement of each operation matches its authentication: the session cookie
 * (`cookieAuth`, the default) or, only in the optional token mode, a Bearer token (`bearerAuth`); public operations
 * list none.
 */
final class DocumentRequestContext
{
    private const array METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /**
     * Reusable parameters of the request context, by parameter name.
     *
     * @var array<string, string>
     */
    private const array PARAMETERS = [
        'X-Tenant-Id' => '#/components/parameters/XTenantId',
        'X-User-Id' => '#/components/parameters/XUserId',
        'tenant_id' => '#/components/parameters/TenantIdQuery',
        'user_id' => '#/components/parameters/UserIdQuery',
    ];

    private const string FORBIDDEN_CONTEXT_NOTE = '. Also returned when the declared user_id differs from the access token or the user is not authorized on the tenant.';

    public function __invoke(Analysis $analysis): void
    {
        $middlewareByOperation = $this->middlewareByOperation();

        /** @var PathItem $pathItem */
        foreach ($analysis->openapi->paths as $pathItem) {
            foreach (self::METHODS as $method) {
                $operation = $pathItem->{$method};

                if (! $operation instanceof Operation) {
                    continue;
                }

                $middleware = $middlewareByOperation[strtoupper($method).' '.$this->normalizePath($pathItem->path)] ?? null;

                if ($middleware !== null) {
                    $this->documentOperation($operation, $middleware);
                }
            }
        }
    }

    /**
     * @param  list<string>  $middleware
     */
    private function documentOperation(Operation $operation, array $middleware): void
    {
        $isAuthenticated = $this->hasMiddleware($middleware, Authenticate::class);
        $isTenantScoped = $this->hasMiddleware($middleware, IdentifyTenant::class);

        $operation->security = match (true) {
            $isAuthenticated && $isTenantScoped => [['cookieAuth' => [], 'tenantHeader' => []], ['bearerAuth' => [], 'tenantHeader' => []]],
            $isAuthenticated => [['cookieAuth' => []], ['bearerAuth' => []]],
            $this->hasMiddleware($middleware, AuthenticateScim::class) => [['scimBearerAuth' => []]],
            default => [],
        };

        if ($isAuthenticated && $isTenantScoped) {
            $this->addContextParameters($operation);
            $this->addContextResponses($operation);
        }
    }

    private function addContextParameters(Operation $operation): void
    {
        $parameters = Generator::isDefault($operation->parameters) ? [] : $operation->parameters;
        $declared = array_map(fn (Parameter $parameter): string => (string) $parameter->name, $parameters);

        foreach (self::PARAMETERS as $name => $reference) {
            if (! in_array($name, $declared, true)) {
                $parameters[] = new Parameter(['ref' => $reference, '_context' => $operation->_context]);
            }
        }

        $operation->parameters = $parameters;
    }

    private function addContextResponses(Operation $operation): void
    {
        $responses = Generator::isDefault($operation->responses) ? [] : $operation->responses;
        $declared = array_map(fn (Response $response): string => (string) $response->response, $responses);

        if (! in_array('400', $declared, true)) {
            $responses[] = new Response(['response' => 400, 'ref' => '#/components/responses/MissingRequestContext', '_context' => $operation->_context]);
        }

        if (! in_array('403', $declared, true)) {
            $responses[] = new Response(['response' => 403, 'ref' => '#/components/responses/ForbiddenRequestContext', '_context' => $operation->_context]);
        }

        foreach ($responses as $response) {
            if ((string) $response->response === '403' && Generator::isDefault($response->ref)) {
                $response->description = rtrim((string) $response->description, '.').self::FORBIDDEN_CONTEXT_NOTE;
            }
        }

        usort($responses, fn (Response $first, Response $second): int => (string) $first->response <=> (string) $second->response);

        $operation->responses = $responses;
    }

    /**
     * Middleware of each route, indexed by HTTP method and normalized path.
     *
     * @return array<string, list<string>>
     */
    private function middlewareByOperation(): array
    {
        /** @var Router $router */
        $router = app('router');
        $middlewareByOperation = [];

        /** @var LaravelRoute $route */
        foreach ($router->getRoutes() as $route) {
            $middleware = array_map(strval(...), $router->gatherRouteMiddleware($route));

            foreach ($route->methods() as $method) {
                $middlewareByOperation[$method.' '.$this->normalizePath($route->uri())] = array_values($middleware);
            }
        }

        return $middlewareByOperation;
    }

    /**
     * @param  list<string>  $middleware
     */
    private function hasMiddleware(array $middleware, string $class): bool
    {
        foreach ($middleware as $entry) {
            if (str_starts_with($entry, $class)) {
                return true;
            }
        }

        return false;
    }

    private function normalizePath(string $path): string
    {
        return (string) preg_replace('/\{[^}]+\}/', '{}', '/'.ltrim($path, '/'));
    }
}
