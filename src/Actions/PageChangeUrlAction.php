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
 * Prevadi explicitni zmenu URL stranky na canonical Admin action
 */
final readonly class PageChangeUrlAction implements ModuleActionHandlerInterface, ModuleActionAccessPolicyInterface
{
    /**
     * Nastavuje stromove routy a lokalni auditni identitu
     */
    public function __construct(
        private PageService $pages,
        private LocalActorGuard $actors,
        private PageModel $model,
        private AuthorizationService $authorization,
    ) {}

    /**
     * Overi editaci URL globalnim nebo vlastnickym opravnenim
     */
    public function accessDecision(?int $id): bool
    {
        return $id !== null && $this->authorization->canForOwner(
            'cms.page.edit',
            'cms.page.edit_own',
            $this->model->ownerId($id),
        );
    }

    /**
     * Ulozi pouze vlastni URL segment a atomicky prepocita podstrom
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
        $locale = $payload['locale'] ?? null;
        $segment = $payload['url_segment'] ?? null;
        if (!is_string($locale) || !is_string($segment)) {
            return ModuleActionResult::invalid(['url_segment' => 'admin.url_segment.invalid']);
        }

        try {
            $urlSegment = $this->pages->changeUrlSegment(
                $this->actors->requireAuditActor(),
                $id,
                $locale,
                $segment,
            );
        } catch (LocalActorRequiredException $exception) {
            throw new ModuleActionException(
                HttpStatus::FORBIDDEN,
                AdminErrorCode::LOCAL_ACTOR_REQUIRED,
                $exception->getMessage(),
            );
        } catch (DomainException $exception) {
            return ModuleActionResult::invalid(['url_segment' => $exception->getMessage()]);
        }

        return ModuleActionResult::success(
            'admin.url_segment.updated',
            ['urlSegment' => $urlSegment],
        );
    }
}
