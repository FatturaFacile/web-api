<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\Contact;

/**
 * Corporate hierarchy of customers (holding > subsidiaries > branches).
 */
class ContactHierarchyService
{
    private const MAX_DEPTH = 10;

    /**
     * True when `$parentId` is the contact itself or one of its descendants, i.e. assigning it as parent would
     * create a cycle.
     */
    public function wouldCreateCycle(Contact $contact, string $parentId): bool
    {
        if ($contact->id === $parentId) {
            return true;
        }

        $frontier = [$contact->id];

        for ($depth = 0; $depth < self::MAX_DEPTH && $frontier !== []; $depth++) {
            $children = Contact::whereIn('parent_id', $frontier)->pluck('id')->all();

            if (in_array($parentId, $children, true)) {
                return true;
            }

            $frontier = $children;
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function ancestors(Contact $contact): array
    {
        $chain = [];
        $current = $contact;

        for ($depth = 0; $depth < self::MAX_DEPTH && $current->parent_id !== null; $depth++) {
            $parent = Contact::find($current->parent_id);

            if ($parent === null) {
                break;
            }

            array_unshift($chain, $this->node($parent));
            $current = $parent;
        }

        return $chain;
    }

    /**
     * @return array<string, mixed>
     */
    public function tree(Contact $contact, int $depth = 0): array
    {
        $node = $this->node($contact);
        $node['children'] = $depth >= self::MAX_DEPTH
            ? []
            : Contact::where('parent_id', $contact->id)->orderBy('company_name')->get()
                ->map(fn (Contact $child): array => $this->tree($child, $depth + 1))
                ->all();

        return $node;
    }

    /**
     * IDs of the contact and all of its descendants (group scope for consolidated figures).
     *
     * @return array<int, string>
     */
    public function groupIds(Contact $contact): array
    {
        $ids = [$contact->id];
        $frontier = [$contact->id];

        for ($depth = 0; $depth < self::MAX_DEPTH && $frontier !== []; $depth++) {
            $frontier = Contact::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, mixed>
     */
    private function node(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'name' => $contact->company_name ?: trim("{$contact->first_name} {$contact->last_name}"),
            'hierarchy_type' => $contact->hierarchy_type,
            'country' => $contact->country,
            'vat_number' => $contact->vat_number,
            'uid_number' => $contact->uid_number,
        ];
    }
}
