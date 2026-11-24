<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Modules\ServiceManagement\Models\KnowledgeArticle;

/**
 * Every agent reads and writes internal articles; what customers see on the portal (a published public article) is
 * decided by a service manager.
 */
final class KnowledgeArticlePolicy
{
    use ResolvesTenantMembership;

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, KnowledgeArticle $article): bool
    {
        return $this->isMember($user) && $this->isOwnRecord($article);
    }

    public function create(User $user): bool
    {
        return $this->isMember($user);
    }

    public function update(User $user, KnowledgeArticle $article): bool
    {
        if (! $this->isMember($user) || ! $this->isOwnRecord($article)) {
            return false;
        }

        return ! ($article->is_published && $article->visibility === KnowledgeArticle::VisibilityPublic) || $this->publishPublicly($user);
    }

    public function delete(User $user, KnowledgeArticle $article): bool
    {
        return $this->update($user, $article);
    }

    /**
     * Publishing an article for customers.
     */
    public function publishPublicly(User $user): bool
    {
        return $this->hasRoleOrPermission($user, HelpdeskConfigurationPolicy::ManagerRoles, ['service.manage']);
    }
}
