<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Platform\Identity\Application\FieldPolicyResolver;
use Symfony\Component\HttpFoundation\Response;

/** Lists the fields masked or hidden for the caller in `meta.masked_fields[]` of a JSON response. */
final class AppendMaskedFieldsMeta
{
    public function __construct(private readonly FieldPolicyResolver $fields) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->fields->reset();
        $response = $next($request);
        $masked = $this->fields->appliedFields();

        if ($masked !== [] && $response instanceof JsonResponse) {
            $data = $response->getData(true);

            if (is_array($data)) {
                $data['meta'] = [...(is_array($data['meta'] ?? null) ? $data['meta'] : []), 'masked_fields' => $masked];
                $response->setData($data);
            }
        }

        return $response;
    }
}
