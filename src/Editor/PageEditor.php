<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Editor;

use DomainException;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Cms\Content\CmsPublicationEditorValues;
use Lemonade\Admin\Editor\Contract\EditorAccessPolicyInterface;
use Lemonade\Admin\Editor\Contract\EditorFieldAccessPolicyInterface;
use Lemonade\Admin\Editor\Contract\EditorProviderInterface;
use Lemonade\Admin\Editor\EditorDefinition;
use Lemonade\Admin\Editor\EditorFieldDefinition;
use Lemonade\Admin\Editor\EditorSaveResult;
use Lemonade\Admin\Editor\Exception\EditorEntityNotFoundException;
use Lemonade\Admin\Editor\Exception\EditorValidationException;
use Lemonade\Admin\Identity\LocalActorGuard;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Cms\Page\Models\PageModel;
use Lemonade\Cms\Page\Services\PageService;
use Lemonade\Framework\Routing\UrlGenerator;
use Lemonade\Framework\Validation\ValidationSchema;

/**
 * Propojuje CMS Page full-page editor s domenovymi mutacemi
 */
final readonly class PageEditor implements
    EditorProviderInterface,
    EditorAccessPolicyInterface,
    EditorFieldAccessPolicyInterface
{
    /**
     * Nastavuje Pages persistence, mutation service, content locales a auditni identitu editoru
     */
    public function __construct(
        private PageModel $pages,
        private PageService $service,
        private LanguageRegistry $languages,
        private LocalActorGuard $actors,
        private PageEditorValidationSchema $validation,
        private AdminModuleRouteResolver $routes,
        private UrlGenerator $urls,
        private AuthorizationService $authorization,
    ) {}

    /**
     * Deklaruje transportni pole jedne lokalizovane mutation a globalnich nastaveni stranky
     */
    public function editorDefinition(): EditorDefinition
    {
        return new EditorDefinition(
            loadPermission: 'cms.page.view_own',
            savePermission: 'cms.page.edit_own',
            createPermission: 'cms.page.create',
            fields: [
                new EditorFieldDefinition(name: 'locale', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'title', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'summary', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'content', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'page_title', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'meta_description', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'parent_id', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'template_key', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'publication_state', type: 'text', readOnly: false, permission: 'cms.page.publish_own'),
                new EditorFieldDefinition(name: 'published_at', type: 'text', readOnly: false, permission: 'cms.page.publish_own'),
            ],
        );
    }

    /**
     * Overi globalni nebo vlastnicky pristup ke cteni stranky
     */
    public function loadAccessDecision(int $id): bool
    {
        return $this->authorization->canForOwner(
            'cms.page.view',
            'cms.page.view_own',
            $this->pages->ownerId($id),
        );
    }

    /**
     * Overi globalni nebo vlastnicky pristup k beznemu ulozeni stranky
     */
    public function saveAccessDecision(int $id): bool
    {
        return $this->authorization->canForOwner(
            'cms.page.edit',
            'cms.page.edit_own',
            $this->pages->ownerId($id),
        );
    }

    /**
     * Overi publish pole proti vlastnikovi root stranky
     */
    public function fieldAccessDecision(int $id, string $field): ?bool
    {
        if (!in_array($field, ['publication_state', 'published_at'], true)) {
            return null;
        }

        return $this->authorization->canForOwner(
            'cms.page.publish',
            'cms.page.publish_own',
            $this->pages->ownerId($id),
        );
    }

    /**
     * Nacte existujici Page aggregate a dostupne content locales pro editaci
     */
    public function updateData(int $id): array
    {
        $page = $this->pages->findForEditor($id);
        if ($page === null) {
            throw new EditorEntityNotFoundException('Page not found.');
        }

        return ['page' => $page, 'locales' => $this->pages->editorLocales($id)];
    }

    /**
     * Vrati prazdny editorovy stav pro nepouzitou fallback create route
     */
    public function createData(): array
    {
        return [
            'page' => ['id' => null, 'translations' => []],
            'locales' => $this->languages->enabledLocales(),
        ];
    }

    /**
     * Vrati prazdnou validaci pro okamzite zalozeni draftu bez vstupu uzivatele
     */
    public function createValidationSchema(): ValidationSchema
    {
        return $this->validation->forCreate();
    }

    /**
     * Vrati validaci globalnich nastaveni a jedne content mutation
     */
    public function updateValidationSchema(int $id): ValidationSchema
    {
        return $this->validation->forUpdate();
    }

    /**
     * Ulozi globalni nastaveni a prave jednu zvolenou jazykovou mutaci
     */
    public function update(int $id, array $data): EditorSaveResult
    {
        try {
            $actor = $this->actors->requireAuditActor();
            $this->service->save(
                actor: $actor,
                pageId: $id,
                data: $data,
            );
        } catch (DomainException $exception) {
            throw new EditorValidationException($exception->getMessage(), previous: $exception);
        }

        return new EditorSaveResult(
            data: [
                'id' => $id,
                'editUrl' => $this->editUrl($id, (string) $data['locale']),
                'editorFields' => isset($data['publication_state'])
                    ? CmsPublicationEditorValues::forLocale($this->updateData($id)['page']['translations'], (string) $data['locale'])
                    : [],
            ],
            messageKey: 'page.editor.updated',
        );
    }

    /**
     * Zalozi minimalni draft v canonical vychozim content locale
     */
    public function create(array $data): EditorSaveResult
    {
        try {
            $actor = $this->actors->requireAuditActor();
            $locale = $this->languages->defaultLocale();
            $pageId = $this->service->createDraft(
                actor: $actor,
                locale: $locale,
            );
        } catch (DomainException $exception) {
            throw new EditorValidationException($exception->getMessage(), previous: $exception);
        }

        return new EditorSaveResult(
            data: [
                'id' => $pageId,
                'editUrl' => $this->editUrl($pageId, $locale),
            ],
            messageKey: 'page.editor.created',
        );
    }

    /**
     * Vytvori canonical edit URL se zachovanym content locale
     */
    private function editUrl(int $pageId, string $locale): string
    {
        return $this->urls->route(
            $this->routes->managementRouteName('pages', 'edit'),
            ['module' => 'pages', 'id' => $pageId, 'locale' => $locale],
        );
    }
}
