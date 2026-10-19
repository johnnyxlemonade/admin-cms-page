<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit\Editor;

use Lemonade\Cms\Page\Editor\PageEditor;
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

        self::assertSame('cms.page.publish', $fields['publication_state']);
        self::assertSame('cms.page.publish', $fields['published_at']);
        self::assertNull($fields['title']);
        self::assertArrayNotHasKey('url_segment', $fields);
        self::assertArrayNotHasKey('url_segment_edited', $fields);
    }
}
