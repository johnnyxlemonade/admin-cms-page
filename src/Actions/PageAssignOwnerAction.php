<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Actions;

use DomainException;
use Lemonade\Admin\Action\Contract\ModuleActionAccessPolicyInterface;
use Lemonade\Admin\Action\Contract\ModuleActionHandlerInterface;
use Lemonade\Admin\Action\Exception\ModuleActionException;
use Lemonade\Admin\Action\ModuleActionResult;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Http\AdminErrorCode;
use Lemonade\Admin\Identity\LocalActorGuard;
use Lemonade\Admin\Identity\LocalActorRequiredException;
use Lemonade\Cms\Page\Models\PageModel;
use Lemonade\Cms\Page\Services\PageService;
use Lemonade\Framework\Http\HttpStatus as HttpStatusCode;

/**
 * Transfers page ownership in a separate audited operation
 */
final readonly class PageAssignOwnerAction implements ModuleActionHandlerInterface, ModuleActionAccessPolicyInterface
{
    /**
     * Creates the action with ownership and authorization dependencies
     */
    public function __construct(
        private PageService $pages,
        private PageModel $model,
        private LocalActorGuard $actors,
        private AuthorizationService $authorization,
    ) {}

    /**
     * Requires assignment permission and access to the page
     */
    public function accessDecision(?int $id): bool
    {
        return $id !== null
            && $this->authorization->hasPermission('cms.page.assign_owner')
            && $this->authorization->canForOwner(
                'cms.page.view',
                'cms.page.view_own',
                $this->model->ownerId($id),
            );
    }

    /**
     * Validates the target and transfers ownership with an audit event
     *
     * @param array<string,mixed> $payload
     */
    public function execute(?int $id, array $payload): ModuleActionResult
    {
        if ($id === null) {
            throw new ModuleActionException(
                HttpStatusCode::NOT_FOUND,
                AdminErrorCode::NOT_FOUND,
                'Page not found.',
            );
        }
        $ownerId = filter_var($payload['owner_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($ownerId) || $ownerId < 1) {
            return ModuleActionResult::invalid(['owner_id' => 'page.validation.owner_invalid']);
        }

        try {
            $this->pages->transferOwner(
                $this->actors->requireAuditActor(),
                $id,
                $ownerId,
            );
        } catch (LocalActorRequiredException $exception) {
            throw new ModuleActionException(
                HttpStatusCode::FORBIDDEN,
                AdminErrorCode::LOCAL_ACTOR_REQUIRED,
                $exception->getMessage(),
            );
        } catch (DomainException $exception) {
            return ModuleActionResult::invalid(['owner_id' => $exception->getMessage()]);
        }

        return ModuleActionResult::success(
            'page.actions.owner_assigned',
            refreshPage: true,
        );
    }
}
