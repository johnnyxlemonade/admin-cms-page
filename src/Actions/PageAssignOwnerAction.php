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
 * Predava vlastnictvi stranky samostatnou auditovanou operaci
 */
final readonly class PageAssignOwnerAction implements ModuleActionHandlerInterface, ModuleActionAccessPolicyInterface
{
    /**
     * Nastavuje autorizaci, persistenci a ownership lifecycle stranek
     */
    public function __construct(
        private PageService $pages,
        private PageModel $model,
        private LocalActorGuard $actors,
        private AuthorizationService $authorization,
    ) {}

    /**
     * Vyzaduje assign permission a pristup ke konkretni strance
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
     * Overi cilove ID a provede auditovane predani vlastnictvi
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
