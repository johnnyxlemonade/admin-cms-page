<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Template;

use Lemonade\Framework\Container\ContainerInterface;
use Lemonade\Framework\Container\TaggedServicesInterface;
use LogicException;

/**
 * Provides the general page template and host-contributed templates
 */
final readonly class PageTemplateProvider implements PageTemplateProviderInterface
{
    public const string DEFAULT_KEY = 'default';

    /**
     * Creates the page template provider with access to tagged host contributions
     */
    public function __construct(private ContainerInterface $container) {}

    /**
     * Returns the general template followed by host-contributed templates
     *
     * @return non-empty-list<PageTemplateDefinition>
     */
    public function templates(): array
    {
        if (!$this->container instanceof TaggedServicesInterface) {
            throw new LogicException('Page templates require a container with tagged services.');
        }

        $templates = [new PageTemplateDefinition(self::DEFAULT_KEY, 'page.templates.default')];
        $codes = [self::DEFAULT_KEY => true];

        foreach ($this->hostTemplates($this->container) as $template) {
            if (isset($codes[$template->getKey()])) {
                throw new LogicException(sprintf('Page template code "%s" is registered more than once.', $template->getKey()));
            }

            $templates[] = $template;
            $codes[$template->getKey()] = true;
        }

        return $templates;
    }

    /**
     * Returns templates contributed by host catalogs and individual definitions
     *
     * @return list<PageTemplateDefinition>
     */
    private function hostTemplates(TaggedServicesInterface $container): array
    {
        $templates = [];

        foreach ($container->tagged(PageTemplateCatalogInterface::class) as $serviceId => $catalog) {
            if (!$catalog instanceof PageTemplateCatalogInterface) {
                throw new LogicException(sprintf(
                    'Tagged page template catalog "%s" must be a %s.',
                    $serviceId,
                    PageTemplateCatalogInterface::class,
                ));
            }

            foreach ($catalog->templates() as $template) {
                if (!$template instanceof PageTemplateDefinition) {
                    throw new LogicException(sprintf(
                        'Page template catalog "%s" must return only %s instances.',
                        $serviceId,
                        PageTemplateDefinition::class,
                    ));
                }

                $templates[] = $template;
            }
        }

        foreach ($container->tagged(PageTemplateDefinition::class) as $serviceId => $template) {
            if (!$template instanceof PageTemplateDefinition) {
                throw new LogicException(sprintf(
                    'Tagged page template "%s" must be a %s.',
                    $serviceId,
                    PageTemplateDefinition::class,
                ));
            }

            $templates[] = $template;
        }

        return $templates;
    }
}
