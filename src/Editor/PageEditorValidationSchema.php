<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Editor;

use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Validation\ValidationSchema;

/**
 * Sklada validaci editoroveho payloadu CMS stranek
 */
final readonly class PageEditorValidationSchema
{
    /**
     * Nastavuje prekladovy zdroj chyb editorove validace
     */
    public function __construct(private TranslatorInterface $translator) {}

    /**
     * Ponechava create bez formulovych hodnot pro okamzite zalozeni draftu
     */
    public function forCreate(): ValidationSchema
    {
        return ValidationSchema::create();
    }

    /**
     * Vymezuje flat payload jedne lokalizovane mutation a globalnich nastaveni
     */
    public function forUpdate(): ValidationSchema
    {
        return ValidationSchema::create()
            ->field('locale', $this->translator->get('page.fields.locale'))
                ->required($this->translator->get('page.validation.locale_invalid'))
            ->field('title', $this->translator->get('page.fields.title'))
                ->required($this->translator->get('page.validation.title_required'))
                ->maxLength(180, $this->translator->get('page.validation.title_max_length'))
            ->field('page_title', $this->translator->get('page.fields.page_title'))
                ->maxLength(180, $this->translator->get('page.validation.page_title_max_length'))
            ->field('meta_description', $this->translator->get('page.fields.meta_description'))
                ->maxLength(255, $this->translator->get('page.validation.meta_description_max_length'))
            ->field('parent_id', $this->translator->get('page.fields.parent'))
            ->field('template_key', $this->translator->get('page.fields.template'))
                ->required($this->translator->get('page.validation.template_invalid'))
            ->field('publication_state', $this->translator->get('page.fields.publication_state'))
                ->inList(['0', '1', '2'], $this->translator->get('page.validation.publication_invalid'))
            ->field('published_at', $this->translator->get('page.fields.published_at'))
            ->field('content', $this->translator->get('page.fields.content'))
            ->end();
    }
}
