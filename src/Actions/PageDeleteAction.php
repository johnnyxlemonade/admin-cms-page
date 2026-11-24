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
use Lemonade\Framework\Http\HttpStatus;

/**
 * Handles page soft deletion through the shared Admin action transport
 */
final readonly class PageDeleteAction implements ModuleActionHandlerInterface, ModuleActionAccessPolicyInterface
{
    /**
     * Creates the action with page lifecycle and actor dependencies
     */
    public function __construct(
        private PageService $pages,
        private LocalActorGuard $actors,
        private PageModel $model,
        private AuthorizationService $authorization,
    ) {}

    /**
     * Authorizes deletion using global or owner permission
     */
    public function accessDecision(?int $id): bool
    {
        return $id !== null && $this->authorization->canForOwner(
            'cms.page.delete',
            'cms.page.delete_own',
            $this->model->ownerId($id),
        );
    }

    /**
     * Soft-deletes an existing page
     *
     * @param array<string,mixed> $payload
     */
    public function execute(?int $id, array $payload): ModuleActionResult
    {
        if ($id === null) {
            throw new ModuleActionException(
                HttpStatus::NOT_FOUND,
                AdminErrorCode::NOT_FOUND,
                'Page not found.',
            );
        }

        try {
            $actor = $this->actors->requireAuditActor();
            $this->pages->delete(
                actor: $actor,
                pageId: $id,
            );
        } catch (LocalActorRequiredException $exception) {
            throw new ModuleActionException(
                HttpStatus::FORBIDDEN,
                AdminErrorCode::LOCAL_ACTOR_REQUIRED,
                $exception->getMessage(),
            );
        } catch (DomainException $exception) {
            return ModuleActionResult::invalid(['_form' => $exception->getMessage()]);
        }

        return ModuleActionResult::success('page.actions.deleted');
    }
}
