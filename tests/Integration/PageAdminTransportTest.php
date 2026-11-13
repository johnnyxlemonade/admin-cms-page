<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Integration;

use Lemonade\Admin\Action\ModuleActionDispatcher;
use Lemonade\Admin\Action\ModuleActionRegistry;
use Lemonade\Admin\Audit\AuditLogService;
use Lemonade\Admin\Auth\AuthenticationService;
use Lemonade\Admin\Auth\CurrentUserProvider;
use Lemonade\Admin\Auth\LocalAuthenticationProvider;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Cms\Content\CmsAdminRuntime;
use Lemonade\Admin\DataGrid\DataGridRegistry;
use Lemonade\Admin\Editor\EditorDispatcher;
use Lemonade\Admin\Editor\EditorRegistry;
use Lemonade\Admin\Editor\Lock\EditorLockManager;
use Lemonade\Admin\Editor\Lock\EditorLockOwnerReleaserInterface;
use Lemonade\Admin\Event\DomainEventDispatcher;
use Lemonade\Admin\Event\TransactionalEventProcessor;
use Lemonade\Admin\Http\AdminAuthorizationResponseHandler;
use Lemonade\Admin\Identity\LocalActorGuard;
use Lemonade\Admin\Identity\LocalUserDirectory;
use Lemonade\Admin\Localization\AdminUiLocale;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Admin\Module\AdminModuleAccessPolicy;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Module\ModuleProviderResolver;
use Lemonade\Admin\Navigation\AdminNavigationGroupRegistry;
use Lemonade\Admin\Page\ModulePageRegistry;
use Lemonade\Admin\Routing\AdminRoutingConfiguration;
use Lemonade\Cms\Page\CmsPageServiceProvider;
use Lemonade\Cms\Page\Migrations\CreateCmsPages;
use Lemonade\Cms\Page\ModuleManifest;
use Lemonade\Cms\Routing\Cms\CmsReservedPathGuard;
use Lemonade\Cms\Routing\Cms\CmsRouteCandidateResolver;
use Lemonade\Cms\Routing\Cms\CmsRouteRepository;
use Lemonade\Cms\Routing\Cms\CmsRouteReservationService;
use Lemonade\Cms\Routing\Locale\PublicLocaleRegistryInterface;
use Lemonade\Cms\Routing\Locale\PublicLocaleSnapshot;
use Lemonade\Framework\Cache\CacheManager;
use Lemonade\Framework\Cache\Store\ArrayCacheItemPool;
use Lemonade\Framework\Container\Container;
use Lemonade\Framework\Container\ScopeKind;
use Lemonade\Framework\Core\Http\ResponseBuilder;
use Lemonade\Framework\Database\Connection\DatabaseConfig;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\Driver\Mysql\MysqlConnection;
use Lemonade\Framework\Database\Driver\Mysql\MysqlDatabaseDriver;
use Lemonade\Framework\Database\Driver\Mysql\MysqlIdentifierEscaper;
use Lemonade\Framework\Database\Driver\Mysql\MysqlSchemaGrammar;
use Lemonade\Framework\Database\Driver\Mysql\MysqlSqlEscaper;
use Lemonade\Framework\Database\Instrumentation\Internal\DatabaseInstrumentation;
use Lemonade\Framework\Database\Schema\Schema;
use Lemonade\Framework\Database\Schema\SchemaCompiler;
use Lemonade\Framework\Database\Sql\IdentifierProtector;
use Lemonade\Framework\Http\Request\HttpRequestInspector;
use Lemonade\Framework\Http\Response\Responses;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\ControllerAction;
use Lemonade\Framework\Routing\Router;
use Lemonade\Framework\Routing\UrlGenerator;
use Lemonade\Framework\Security\Csrf\CsrfTokenManager;
use Lemonade\Framework\Session\Contract\SessionInterface;
use Lemonade\Framework\Validation\FormValidation;
use Lemonade\Framework\Validation\Rule\RuleRegistry;
use Lemonade\Framework\Validation\ValidationRuleResolver;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Overuje Pages pres HTTP, skutecny dispatcher a atomicke databazove mutace
 */
final class PageAdminTransportTest extends TestCase
{
    /**
     * Projde koncept, publikaci, sdilene invarianty, autorizaci a rollback
     */
    public function testDraftPublicationAndHierarchyThroughHttp(): void
    {
        $name = getenv('PAGE_INTEGRATION_DATABASE');
        self::assertIsString($name);
        self::assertStringStartsWith('cms_page_test_', $name);
        $config = DatabaseConfig::fromArray(['driver' => 'mysql', 'host' => 'db', 'database' => $name, 'username' => 'db', 'password' => 'db']);
        $connection = new MysqlConnection($config, new DatabaseInstrumentation());
        $escaper = new MysqlIdentifierEscaper('');
        $driver = new MysqlDatabaseDriver($connection, $config, $escaper, new IdentifierProtector($escaper));
        $db = new Database($connection, $driver);
        foreach ([
            'CREATE TABLE system_user (id BIGINT UNSIGNED PRIMARY KEY, email VARCHAR(255), first_name VARCHAR(255), last_name VARCHAR(255), active TINYINT, deleted_at DATETIME NULL)',
            'CREATE TABLE system_role (id BIGINT PRIMARY KEY, deleted_at DATETIME NULL, is_super_admin TINYINT)',
            'CREATE TABLE system_user_role (user_id BIGINT, role_id BIGINT)',
            'CREATE TABLE system_role_permission (role_id BIGINT, permission_id BIGINT)',
            'CREATE TABLE system_permission (id BIGINT PRIMARY KEY, code VARCHAR(255), name_key VARCHAR(255), module_code VARCHAR(255))',
            'CREATE TABLE system_user_permission (user_id BIGINT, permission_id BIGINT, effect VARCHAR(10))',
            'CREATE TABLE system_language (code VARCHAR(10) PRIMARY KEY, name VARCHAR(100), enabled TINYINT, is_default TINYINT, sort_order INT) DEFAULT CHARACTER SET utf8mb4',
            'CREATE TABLE system_audit_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_code VARCHAR(255), module_code VARCHAR(255), entity_type VARCHAR(255), entity_key VARCHAR(255), actor_type VARCHAR(30), actor_key VARCHAR(255), actor_user_id BIGINT UNSIGNED, payload TEXT, created_at DATETIME)',
            'CREATE TABLE cms_route (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, module_code VARCHAR(100), entity_id BIGINT UNSIGNED, locale VARCHAR(10), path VARCHAR(700), created_at DATETIME, updated_at DATETIME, deleted_at DATETIME NULL, UNIQUE KEY uq_cms_route_target (module_code,entity_id,locale), UNIQUE KEY uq_cms_route_locale_path (locale,path))',
            'CREATE TABLE admin_editor_lock (resource_type VARCHAR(100), resource_id VARCHAR(100), owner_key VARCHAR(100), owner_user_id BIGINT, created_at DATETIME, updated_at DATETIME, UNIQUE KEY (resource_type,resource_id))',
        ] as $sql) {
            $db->statement($sql);
        }
        $db->statement("INSERT INTO system_user VALUES (7, 'test@example.test', 'Test', 'User', 1, NULL)");
        $db->statement("INSERT INTO system_user VALUES (9, 'other@example.test', 'Other', 'User', 1, NULL)");
        $db->statement('INSERT INTO system_role VALUES (1, NULL, 1)');
        $db->statement('INSERT INTO system_user_role VALUES (7, 1)');
        $db->statement("INSERT INTO system_language VALUES ('cs','Czech',1,1,1), ('en','English',1,0,2)");
        (new CreateCmsPages())->up(new Schema(new SchemaCompiler(new MysqlSchemaGrammar(new MysqlSqlEscaper($escaper), $config)), $driver));
        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->willReturnCallback(static fn(string $key): mixed => $key === 'admin.auth.user_id' ? 7 : null);
        $audit = new AuditLogService($db);
        $authentication = new AuthenticationService(new LocalAuthenticationProvider($db), $session, $db, new CsrfTokenManager($session), $audit);
        $user = new CurrentUserProvider($authentication, $db);
        $authorization = new AuthorizationService($user, $db);
        $locks = new EditorLockManager($db, $user, $this->createMock(EditorLockOwnerReleaserInterface::class));
        $languages = new LanguageRegistry($db, new CacheManager(new ArrayCacheItemPool()));
        $runtime = new CmsAdminRuntime(new TransactionalEventProcessor($db, $audit, new DomainEventDispatcher(new NullLogger())), $languages, new LocalUserDirectory($db));
        $localeRegistry = new class implements PublicLocaleRegistryInterface {
            public function snapshot(): PublicLocaleSnapshot
            {
                return new PublicLocaleSnapshot('cs', ['cs', 'en'], ['cs', 'en']);
            }
        };
        $router = new Router();
        $router->getNamed('admin.cms.module.edit', '/admin/cms/{module}/{id}', ControllerAction::for(self::class, 'testDraftPublicationAndHierarchyThroughHttp'));
        $router->postNamed('admin.cms.module.ajax.entity', '/admin/cms/{module}/{id}/ajax', ControllerAction::for(self::class, 'testDraftPublicationAndHierarchyThroughHttp'));
        foreach ([
            'admin.cms.module.update' => '/admin/cms/{module}/{id}/save',
            'admin.cms.module.index' => '/admin/cms/{module}',
            'admin.file.chunk.start' => '/admin/files/{module}/{entity}/{usage}/start',
            'admin.file.chunk.append' => '/admin/files/{uploadId}/append',
            'admin.file.chunk.complete' => '/admin/files/{uploadId}/complete',
            'admin.file.chunk.abort' => '/admin/files/{uploadId}/abort',
            'admin.file.collection.index' => '/admin/files/{module}/{entity}/{usage}',
            'admin.file.collection.remove' => '/admin/files/{module}/{entity}/{usage}/{file}/remove',
            'admin.file.collection.reorder' => '/admin/files/{module}/{entity}/{usage}/reorder',
            'admin.file.collection.rename.modal' => '/admin/files/{module}/{entity}/{usage}/{file}/rename',
            'api.file.download' => '/api/file/download/{fileId}',
            'api.image.show' => '/images/{variant}/{fileId}',
        ] as $routeName => $path) {
            $router->getNamed($routeName, $path, ControllerAction::for(self::class, 'testDraftPublicationAndHierarchyThroughHttp'));
        }
        $guard = new CmsReservedPathGuard(new Router(), new ServerRequest('GET', '/'), $localeRegistry);
        $routes = new CmsRouteReservationService(new CmsRouteRepository($db), new CmsRouteCandidateResolver(), $guard);
        $modules = new AdminModuleRegistry(new AdminNavigationGroupRegistry());
        $modules->register(new ModuleManifest());
        $moduleRoutes = new AdminModuleRouteResolver($modules);
        $grids = new DataGridRegistry();
        $editors = new EditorRegistry();
        $pages = new ModulePageRegistry();
        $actions = new ModuleActionRegistry();
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('get')->willReturnCallback(static fn(string $key): string => $key);
        $container = new Container();
        foreach ([Database::class => $db, \Lemonade\Framework\Database\DatabaseDriverInterface::class => $driver, CmsAdminRuntime::class => $runtime, CmsRouteReservationService::class => $routes, LanguageRegistry::class => $languages, LocalActorGuard::class => new LocalActorGuard($user), AuthorizationService::class => $authorization, AdminModuleRouteResolver::class => $moduleRoutes, UrlGenerator::class => new UrlGenerator($router), TranslatorInterface::class => $translator] as $id => $service) {
            $container->instance($id, $service);
        }

        $templates = new class implements \Lemonade\Cms\Page\Template\PageTemplateProviderInterface {
            public function templates(): array
            {
                return [new \Lemonade\Cms\Page\Template\PageTemplateDefinition('content', 'Content')];
            }
        };
        $container->instance(\Lemonade\Cms\Page\Template\PageTemplateProviderInterface::class, $templates);
        $container->instance(TransactionalEventProcessor::class, new TransactionalEventProcessor($db, $audit, new DomainEventDispatcher(new NullLogger())));
        $container->instance(LocalUserDirectory::class, new LocalUserDirectory($db));
        $provider = new CmsPageServiceProvider();
        $provider->register($container);
        $editors->register('cms.page', \Lemonade\Cms\Page\Editor\PageEditor::class);
        $pages->registerEditor('cms.page', \Lemonade\Cms\Page\PageModulePageProvider::class);
        (new \Lemonade\Cms\Page\Actions\PageActionRegistrar($actions))->register();
        $scope = $container->beginScope(ScopeKind::Request);
        $resolver = new ModuleProviderResolver($grids, $editors, $pages, $actions, $scope);
        $validation = new FormValidation($translator, new ValidationRuleResolver(new RuleRegistry(), $scope));
        $dispatcher = new EditorDispatcher($modules, $resolver, $authorization, $validation, $locks);
        $container->instance(EditorDispatcher::class, $dispatcher);
        $transport = new ModuleActionDispatcher($moduleRoutes, new AdminModuleAccessPolicy($authorization, $user), $resolver, $authorization, $locks, $pages);
        $factory = new Psr17Factory();
        $controller = new \Lemonade\Admin\Action\Http\Controller\ModuleActionController(
            $transport,
            new AdminAuthorizationResponseHandler(new HttpRequestInspector(), new AdminRoutingConfiguration('/admin')),
            new AdminUiLocale($session, $translator),
            $translator,
            $pages,
            $moduleRoutes,
            new Responses(new ResponseBuilder($factory, $factory)),
            new UrlGenerator($router),
            $this->createMock(\Lemonade\Framework\Session\Flash\FlashBagInterface::class),
        );
        $create = function () use ($controller): int {
            $response = $controller->create('pages', $this->request('create', []));
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $json = $this->json($response);
            return (int) $json['data']['id'];
        };
        $save = function (int $id, array $payload, int $status = 200) use ($controller): array {
            $response = $controller->entity('pages', $id, $this->request('save', $payload));
            self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
            return $this->json($response);
        };
        $id = $create();
        self::assertSame('', ($db->table('cms_page')->where('id', $id)->first() ?? [])['template_key']);
        $empty = ['locale' => 'cs', 'title' => '', 'template_key' => ''];
        $save($id, $empty);
        self::assertSame([], $db->table('cms_route')->getArray());
        $snapshot = $this->snapshot($db);
        $save($id, $empty);
        self::assertSame($snapshot, $this->snapshot($db), 'Empty draft no-op must not write or audit');
        $invalid = $save($id, ['publication_state' => '2'] + $empty, 422);
        self::assertSame(['title', 'template_key'], array_keys($invalid['errors']));
        // Stejny formularovy payload musi zachovat pole i pri HTML renderingu editoru.
        $formRequest = (new ServerRequest('POST', '/admin/cms/pages/' . $id, ['Content-Type' => 'application/x-www-form-urlencoded']))
            ->withParsedBody(['publication_state' => '2'] + $empty);
        $outcome = $dispatcher->update('cms.page', $id, (new \Lemonade\Framework\Core\Http\RequestData($formRequest))->postAll());
        self::assertFalse($outcome->isSaved());
        $definition = (new \Lemonade\Cms\Page\Editor\PageAdminEditorDefinitionFactory(new UrlGenerator($router), $moduleRoutes))->create(
            $dispatcher->updateData('cms.page', $id)->data()['page'],
            'cs',
            true,
            ['' => 'Root'],
            ['' => 'No template', 'content' => 'Content'],
        );
        $formDefinition = \Lemonade\Admin\Editor\AdminEditor\AdminEditorBuilder::create('page-validation')
            ->form($definition->form())
            ->tab($definition->tabs()[0])
            ->tab($definition->tabs()[2])
            ->build();
        $html = (new \Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorRenderer())->render(
            $formDefinition,
            new \Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorRenderContext(
                values: ['publication_state' => '1'],
                oldInput: $outcome->input(),
                errors: $outcome->validation()->errors(),
            ),
        );
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        foreach (['title', 'template_key'] as $field) {
            $errors = $xpath->query('//*[@data-lemonade-field-error="' . $field . '"]');
            $required = $xpath->query('//*[@data-lemonade-field="' . $field . '" and @required]');
            self::assertNotFalse($errors);
            self::assertNotFalse($required);
            self::assertSame(1, $errors->length);
            self::assertSame(1, $required->length);
        }
        self::assertStringNotContainsString('data-lemonade-field-error="_form"', $html);
        self::assertSame($snapshot, $this->snapshot($db));
        $invalid = $save($id, ['summary' => ['invalid'], 'content' => ['invalid']] + $empty, 422);
        self::assertSame(['summary', 'content'], array_keys($invalid['errors']));
        $save($id, ['title' => 'Contact'] + $empty);
        $path = $db->table('cms_route')->where('entity_id', $id)->first();
        self::assertSame('contact', $path['path'] ?? null);
        $save($id, $empty);
        self::assertSame($path, $db->table('cms_route')->where('entity_id', $id)->first());
        $published = ['locale' => 'cs', 'title' => 'New contact', 'template_key' => 'content', 'publication_state' => '2', 'published_at' => '2028-01-01T12:00'];
        $save($id, $published);
        self::assertSame($path, $db->table('cms_route')->where('entity_id', $id)->first());
        $ordinary = $published;
        unset($ordinary['publication_state'], $ordinary['published_at']);
        $snapshot = $this->snapshot($db);
        $save($id, $ordinary);
        self::assertSame($snapshot, $this->snapshot($db));
        $invalid = $save($id, ['title' => '', 'template_key' => ''] + $ordinary, 422);
        self::assertSame(['title', 'template_key'], array_keys($invalid['errors']));
        self::assertSame($snapshot, $this->snapshot($db));
        foreach (['', '0', '2oops', []] as $state) {
            $save($id, ['publication_state' => $state] + $ordinary, 422);
        }
        $save($id, ['publication_state' => null] + $ordinary);
        self::assertSame($snapshot, $this->snapshot($db));

        $parent = $create();
        $parentDraft = ['locale' => 'cs', 'title' => 'Parent', 'template_key' => 'content'];
        $save($parent, $parentDraft);
        $snapshot = $this->snapshot($db);
        $invalid = $save($id, ['parent_id' => 'page-' . $parent] + $published, 422);
        self::assertArrayHasKey('parent_id', $invalid['errors']);
        self::assertSame($snapshot, $this->snapshot($db), 'Failed move must roll back URL changes as well');
        $save($parent, ['publication_state' => '2', 'published_at' => '2027-01-01T12:00'] + $parentDraft);
        $save($id, ['parent_id' => 'page-' . $parent] + $published);
        self::assertSame('parent/contact', ($db->table('cms_route')->where('entity_id', $id)->where('locale', 'cs')->first() ?? [])['path']);
        $save($parent, ['publication_state' => '1'] + $parentDraft, 422);
        $save($parent, ['parent_id' => 'page-' . $id] + $parentDraft, 422);

        // A draft in another locale must not invalidate a shared template or parent.
        $english = ['locale' => 'en', 'title' => '', 'template_key' => 'content', 'parent_id' => 'page-' . $parent];
        $save($id, $english);
        $save($id, ['template_key' => ''] + $english, 422);
        $draftParent = $create();
        $save($draftParent, ['title' => 'Draft parent'] + $parentDraft);
        $snapshot = $this->snapshot($db);
        $save($id, ['parent_id' => 'page-' . $draftParent] + $english, 422);
        self::assertSame($snapshot, $this->snapshot($db));

        // The real dispatcher must preserve publication for an editor without publish rights.
        $db->statement('UPDATE system_role SET is_super_admin = 0 WHERE id = 1');
        $db->statement("INSERT INTO system_permission VALUES (1,'cms.page.view_own','view','cms.page'), (2,'cms.page.edit_own','edit','cms.page')");
        $db->statement('INSERT INTO system_role_permission VALUES (1,1), (1,2)');
        $authorization->invalidate();
        $ordinary['parent_id'] = 'page-' . $parent;
        $save($id, $ordinary);
        self::assertSame(2, (int) ($db->table('cms_page_translation')->where('page_id', $id)->where('locale', 'cs')->first() ?? [])['publication_state']);
        $save($id, ['title' => ''] + $ordinary, 422);
        $save($id, ['publication_state' => '1'] + $ordinary, 403);
        $db->statement('UPDATE system_role SET is_super_admin = 1 WHERE id = 1');
        $authorization->invalidate();
        $save($id, ['publication_state' => '1', 'parent_id' => 'page-' . $parent] + $empty);
        self::assertContains('cms.page.unpublished', array_column($db->table('system_audit_log')->getArray(), 'event_code'));
        $snapshot = $this->snapshot($db);
        $save($id, ['publication_state' => '1', 'parent_id' => 'page-' . $parent] + $empty);
        self::assertSame($snapshot, $this->snapshot($db));
        $db->table('admin_editor_lock')->where('resource_type', 'cms.page')->where('resource_id', (string) $id)->set(['owner_user_id' => 9, 'owner_key' => 'user:9'])->update();
        $save($id, $empty, 409);

        $failing = new class implements \Lemonade\Admin\Audit\AuditLogWriterInterface {
            public function record(\Lemonade\Admin\Event\DomainEvent $event, \Lemonade\Admin\Audit\AuditActor $actor): void
            {
                throw new \RuntimeException('Audit failure');
            }
        };
        $service = new \Lemonade\Cms\Page\Services\PageService(
            new \Lemonade\Cms\Page\Models\PageModel($driver),
            $languages,
            $routes,
            new \Lemonade\Framework\Support\Slug\Slugger(),
            new TransactionalEventProcessor($db, $failing, new DomainEventDispatcher(new NullLogger())),
            $templates,
            new \Lemonade\Cms\Page\Routing\PageSubtreeRouteMapper(),
            new \Lemonade\Cms\Page\Publication\PagePublicationPlanResolver(),
            new LocalUserDirectory($db),
        );
        $snapshot = $this->snapshot($db);
        try {
            $service->save(\Lemonade\Admin\Audit\AuditActor::user(7), $id, ['locale' => 'en', 'title' => 'Must roll back', 'template_key' => 'content']);
            self::fail('Audit failure accepted');
        } catch (\RuntimeException $exception) {
            self::assertSame('Audit failure', $exception->getMessage());
        }
        self::assertSame($snapshot, $this->snapshot($db));
    }

    /**
     * Sestavi skutecny JSON HTTP pozadavek
     *
     * @param array<string,mixed> $payload
     */
    private function request(string $action, array $payload): ServerRequest
    {
        return new ServerRequest('POST', '/admin/cms/pages/ajax', ['Content-Type' => 'application/json', 'Accept' => 'application/json'], json_encode(['action' => $action, 'payload' => $payload], JSON_THROW_ON_ERROR));
    }

    /**
     * Dekoduje standardni odpoved Admin transportu
     *
     * @return array<string,mixed>
     */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        $json = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        return $json;
    }

    /**
     * Zachyti vsechny zapisovane tabulky vcetne rout a auditu
     *
     * @return array<string,list<array<string,mixed>>>
     */
    private function snapshot(Database $database): array
    {
        $snapshot = [];
        foreach (['cms_page', 'cms_page_translation', 'cms_route', 'system_audit_log'] as $table) {
            $snapshot[$table] = $database->table($table)->orderBy('id', 'ASC')->getArray();
        }
        return $snapshot;
    }
}
