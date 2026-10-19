<?php

declare(strict_types=1);

use Lemonade\Admin\Editor\AdminEditor\AdminEditorDefinition;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorFieldRenderer;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorRenderContext;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorRenderer;
use Lemonade\Framework\View\ViewHelpers;

/**
 * @var AdminEditorDefinition $adminEditor
 * @var AdminEditorRenderContext $adminEditorContext
 * @var AdminEditorFieldRenderer $adminEditorFields
 * @var ViewHelpers $helpers
 */
?>
<?= (new AdminEditorRenderer($this, $helpers, $adminEditorFields))->render($adminEditor, $adminEditorContext) ?>
