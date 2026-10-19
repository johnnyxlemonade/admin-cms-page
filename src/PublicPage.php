<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page;

/**
 * Nese publikovanou lokalizaci stranky do host renderingu
 */
final readonly class PublicPage
{
    /**
     * Nastavuje verejne publikovatelnou projekci jedne lokalizace
     */
    public function __construct(
        private int $id,
        private ?int $parentId,
        private string $templateKey,
        private string $locale,
        private string $title,
        private ?string $content,
        private ?string $pageTitle,
        private ?string $metaDescription,
        private string $publishedAt,
    ) {}

    /**
     * Vrati stabilni identitu stranky
     */
    public function id(): int
    {
        return $this->id;
    }

    /**
     * Vrati volitelneho rodice ve spolecne hierarchii
     */
    public function parentId(): ?int
    {
        return $this->parentId;
    }

    /**
     * Vrati hostem interpretovany klic sablony
     */
    public function templateKey(): string
    {
        return $this->templateKey;
    }

    /**
     * Vrati content locale publikovane mutace
     */
    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * Vrati verejny nadpis stranky
     */
    public function title(): string
    {
        return $this->title;
    }

    /**
     * Vrati volitelny rich-text obsah stranky
     */
    public function content(): ?string
    {
        return $this->content;
    }

    /**
     * Vrati volitelny SEO titul
     */
    public function pageTitle(): ?string
    {
        return $this->pageTitle;
    }

    /**
     * Vrati volitelny SEO popis
     */
    public function metaDescription(): ?string
    {
        return $this->metaDescription;
    }

    /**
     * Vrati pocatek verejne viditelnosti
     */
    public function publishedAt(): string
    {
        return $this->publishedAt;
    }
}
