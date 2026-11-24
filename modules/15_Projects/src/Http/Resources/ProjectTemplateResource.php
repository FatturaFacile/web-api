<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectTemplate;

/**
 * API representation of a project template: identification and a summary of its structure.
 *
 * @mixin ProjectTemplate
 */
final class ProjectTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectTemplate $template */
        $template = $this->resource;
        $structure = (array) $template->default_wbs_structure;
        $nodes = array_key_exists('nodes', $structure) ? (array) $structure['nodes'] : $structure;

        return [
            'id' => $template->id,
            'code' => $template->code,
            'name' => $template->name,
            'description' => $template->description,
            'is_active' => $template->is_active,
            'node_count' => $this->countNodes($nodes),
            'dependency_count' => count((array) ($structure['dependencies'] ?? [])),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $nodes
     */
    private function countNodes(array $nodes): int
    {
        $count = 0;

        foreach ($nodes as $node) {
            $count += 1 + $this->countNodes((array) (is_array($node) ? ($node['children'] ?? []) : []));
        }

        return $count;
    }
}
