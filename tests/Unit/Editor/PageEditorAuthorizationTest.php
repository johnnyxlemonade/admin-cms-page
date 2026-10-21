<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit\Editor;

use Lemonade\Admin\Auth\AuthenticatedUser;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Authorization\CurrentPrincipalProviderInterface;
use Lemonade\Admin\Identity\LocalActorGuard;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Cms\Page\Editor\PageEditor;
use Lemonade\Cms\Page\Editor\PageEditorValidationSchema;
use Lemonade\Cms\Page\Models\PageModel;
use Lemonade\Cms\Page\Services\PageService;
use Lemonade\Framework\Database\Connection\ConnectionInterface;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use Lemonade\Framework\Database\DatabaseResultInterface;
use Lemonade\Framework\Routing\UrlGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Drzi publish vstupy za samostatnou transportni permission hranici
 */
final class PageEditorAuthorizationTest extends TestCase
{
    public function testEditingPermissionDoesNotAuthorizePublicationFields(): void
    {
        /** @var PageEditor $editor */
        $editor = (new \ReflectionClass(PageEditor::class))->newInstanceWithoutConstructor();
        $fields = [];
        foreach ($editor->editorDefinition()->fields() as $field) {
            $fields[$field->name()] = $field->permission();
        }

        self::assertSame('cms.page.publish_own', $fields['publication_state']);
        self::assertSame('cms.page.publish_own', $fields['published_at']);
        self::assertNull($fields['title']);
        self::assertArrayNotHasKey('owner_id', $fields);
        self::assertArrayNotHasKey('url_segment', $fields);
        self::assertArrayNotHasKey('url_segment_edited', $fields);
    }

    public function testOwnPermissionsRejectForeignEntityAndKeepPublishSeparate(): void
    {
        $own = $this->editor(42, ['cms.page.view_own', 'cms.page.edit_own']);
        self::assertTrue($own->loadAccessDecision(7));
        self::assertTrue($own->saveAccessDecision(7));
        self::assertFalse($own->fieldAccessDecision(7, 'publication_state'));

        $publisher = $this->editor(42, ['cms.page.view_own', 'cms.page.edit_own', 'cms.page.publish_own']);
        self::assertTrue($publisher->fieldAccessDecision(7, 'publication_state'));

        $foreign = $this->editor(9, ['cms.page.view_own', 'cms.page.edit_own', 'cms.page.publish_own']);
        self::assertFalse($foreign->loadAccessDecision(7));
        self::assertFalse($foreign->saveAccessDecision(7));
        self::assertFalse($foreign->fieldAccessDecision(7, 'publication_state'));
    }

    /** @param list<string> $permissions */
    private function editor(int $ownerId, array $permissions): PageEditor
    {
        $result = $this->createMock(DatabaseResultInterface::class);
        $result->method('row_array')->willReturn(['owner_id' => $ownerId]);
        $result->method('result_array')->willReturn([['owner_id' => $ownerId]]);
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(static fn(string $identifier): string => $identifier);
        $driver->method('query')->willReturn($result);
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('select')->willReturnCallback(
            static function (string $sql) use ($permissions): array {
                if (!str_contains($sql, 'system_role_permission')) {
                    return [];
                }

                return array_map(
                    static fn(string $code): array => ['code' => $code, 'name_key' => $code, 'module_code' => 'cms.page'],
                    $permissions,
                );
            },
        );
        $principal = $this->createMock(CurrentPrincipalProviderInterface::class);
        $principal->method('currentUser')->willReturn(new AuthenticatedUser(42, 'owner@example.test'));

        return new PageEditor(
            new PageModel($driver),
            $this->instanceWithoutConstructor(PageService::class),
            $this->instanceWithoutConstructor(LanguageRegistry::class),
            $this->instanceWithoutConstructor(LocalActorGuard::class),
            $this->instanceWithoutConstructor(PageEditorValidationSchema::class),
            $this->instanceWithoutConstructor(AdminModuleRouteResolver::class),
            $this->instanceWithoutConstructor(UrlGenerator::class),
            new AuthorizationService($principal, new Database($connection, $driver)),
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function instanceWithoutConstructor(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
