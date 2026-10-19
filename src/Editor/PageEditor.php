<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Editor;

use Lemonade\Admin\Audit\AuditActor;
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
final readonly class PageEditor implements EditorProviderInterface
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
    ) {}

    /**
     * Deklaruje transportni pole jedne lokalizovane mutation a globalnich nastaveni stranky
     */
    public function editorDefinition(): EditorDefinition
    {
        return new EditorDefinition(
            loadPermission: 'cms.page.view',
            savePermission: 'cms.page.edit',
            createPermission: 'cms.page.create',
            fields: [
                new EditorFieldDefinition(name: 'locale', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'title', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'content', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'page_title', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'meta_description', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'parent_id', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'template_key', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'url_segment', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'url_segment_edited', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'publication_state', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'published_at', type: 'text', readOnly: false, permission: null),
            ],
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
            $actor = AuditActor::user($this->actors->requireLocalUser()->id());
            $this->service->save(
                actor: $actor,
                pageId: $id,
                data: $data,
            );
        } catch (\RuntimeException $exception) {
            throw new EditorValidationException($exception->getMessage(), previous: $exception);
        }

        return new EditorSaveResult(
            data: ['id' => $id, 'editUrl' => $this->editUrl($id, (string) $data['locale'])],
            messageKey: 'page.editor.updated',
        );
    }

    /**
     * Zalozi minimalni draft v canonical vychozim content locale
     */
    public function create(array $data): EditorSaveResult
    {
        try {
            $actor = AuditActor::user($this->actors->requireLocalUser()->id());
            $locale = $this->languages->defaultLocale();
            $pageId = $this->service->createDraft(
                actor: $actor,
                locale: $locale,
            );
        } catch (\RuntimeException $exception) {
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
