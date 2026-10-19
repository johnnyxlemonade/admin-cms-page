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
 * Obnovuje smazanou stranku jako draft
 */
final readonly class PageRestoreAction implements ModuleActionHandlerInterface
{
    /**
     * Nastavuje lifecycle service a canonical actor guard
     */
    public function __construct(
        private PageService $pages,
        private LocalActorGuard $actors,
    ) {}

    /**
     * Obnovi existujici stranku jako draft
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
            $this->pages->restore(
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

        return ModuleActionResult::success('page.actions.restored');
    }
}
