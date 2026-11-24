<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Public;

use DateTimeImmutable;
use Lemonade\Admin\File\FileMetadata;
use Lemonade\Admin\File\PublishedMediaInterface;

/**
 * Represents a publicly visible page translation with its system content and media
 */
final readonly class PublishedPage implements PublishedMediaInterface
{
    /**
     * Creates a complete public page projection
     *
     * @param list<FileMetadata> $gallery
     * @param list<FileMetadata> $attachments
     */
    public function __construct(
        private int $id,
        private ?int $parentId,
        private string $templateKey,
        private string $locale,
        private string $title,
        private ?string $summary,
        private ?string $content,
        private ?string $pageTitle,
        private ?string $metaDescription,
        private DateTimeImmutable $publishedAt,
        private DateTimeImmutable $updatedAt,
        private string $publicUrl,
        private ?FileMetadata $thumbnail,
        private array $gallery,
        private array $attachments,
    ) {}

    /**
     * Returns the stable page identifier
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Returns the parent identifier or null for a root page
     */
    public function getParentId(): ?int
    {
        return $this->parentId;
    }

    /**
     * Returns the host-interpreted template code
     */
    public function getTemplateKey(): string
    {
        return $this->templateKey;
    }

    /**
     * Returns the published content locale
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Returns the localized page title
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Returns the optional system summary
     */
    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /**
     * Returns the optional system rich-text content
     */
    public function getContent(): ?string
    {
        return $this->content;
    }

    /**
     * Returns the optional SEO page title
     */
    public function getPageTitle(): ?string
    {
        return $this->pageTitle;
    }

    /**
     * Returns the optional SEO meta description
     */
    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    /**
     * Returns the start of public visibility
     */
    public function getPublishedAt(): DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /**
     * Returns the last translation update time
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Returns the localized canonical URL path
     */
    public function getPublicUrl(): string
    {
        return $this->publicUrl;
    }

    /**
     * Returns the page thumbnail image when one is available
     */
    public function getThumbnail(): ?FileMetadata
    {
        return $this->thumbnail;
    }

    /**
     * Returns gallery images in their stored order
     *
     * @return list<FileMetadata>
     */
    public function getGallery(): array
    {
        return $this->gallery;
    }

    /**
     * Returns attachments in their stored order
     *
     * @return list<FileMetadata>
     */
    public function getAttachments(): array
    {
        return $this->attachments;
    }
}
