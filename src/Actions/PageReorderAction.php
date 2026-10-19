<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Actions;

use DomainException;
use Lemonade\Admin\Action\Contract\ModuleActionHandlerInterface;
use Lemonade\Admin\Action\Exception\ModuleActionException;
use Lemonade\Admin\Action\ModuleActionResult;
use Lemonade\Admin\Http\AdminErrorCode;
use Lemonade\Admin\Identity\LocalActorGuard;
use Lemonade\Admin\Identity\LocalActorRequiredException;
use Lemonade\Cms\Page\Services\PageService;
use Lemonade\Framework\Http\HttpStatus;

/**
 * Prevadi posun stranky mezi sourozenci na canonical Admin action response
 */
final readonly class PageReorderAction implements ModuleActionHandlerInterface
{
    /**
     * Nastavuje smer posunu a sdilenou actor hranici
     */
    public function __construct(
        private PageService $pages,
        private LocalActorGuard $actors,
        private int $direction,
    ) {}

    /**
     * Posune existujici stranku o jednu pozici
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
            $this->pages->reorder(
                actor: $actor,
                pageId: $id,
                direction: $this->direction,
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

        return ModuleActionResult::success('page.actions.reordered');
    }
}
