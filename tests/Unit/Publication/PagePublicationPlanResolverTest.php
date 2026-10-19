<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit\Publication;

use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\Page\Publication\PagePublicationPlanResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Overuje lokalizovany draft/publish lifecycle a publication udalosti
 */
final class PagePublicationPlanResolverTest extends TestCase
{
    /**
     * Prevede pozadovany stav na stabilni publication plan
     *
     * @param array<string,mixed>|null $existing
     */
    #[DataProvider('publicationCases')]
    public function testResolvesPublicationPlan(
        CmsPublicationState $requested,
        string $input,
        ?array $existing,
        CmsPublicationState $expectedState,
        ?string $expectedPublishedAt,
        bool $changed,
        bool $requiresPublicationEvent,
    ): void {
        $plan = (new PagePublicationPlanResolver())->resolve(
            $requested,
            $input,
            $existing,
            '2026-10-19 12:00:00',
        );

        self::assertSame($expectedState, $plan->state);
        self::assertSame($expectedPublishedAt, $plan->publishedAt);
        self::assertSame($changed, $plan->changed);
        self::assertSame($requiresPublicationEvent, $plan->requiresPublicationEvent);
    }

    /**
     * Poskytne create, publish, edit a unpublish lifecycle scenare
     *
     * @return iterable<string,array{CmsPublicationState,string,array<string,mixed>|null,CmsPublicationState,string|null,bool,bool}>
     */
    public static function publicationCases(): iterable
    {
        yield 'first save promotes new to draft' => [
            CmsPublicationState::New,
            '',
            ['publication_state' => CmsPublicationState::New->value, 'published_at' => null],
            CmsPublicationState::Draft,
            null,
            true,
            false,
        ];
        yield 'publishing draft uses now' => [
            CmsPublicationState::Published,
            '',
            ['publication_state' => CmsPublicationState::Draft->value, 'published_at' => null],
            CmsPublicationState::Published,
            '2026-10-19 12:00:00',
            true,
            true,
        ];
        yield 'ordinary edit preserves published metadata' => [
            CmsPublicationState::Published,
            '2026-10-18T10:30',
            ['publication_state' => CmsPublicationState::Published->value, 'published_at' => '2026-10-18 10:30:00'],
            CmsPublicationState::Published,
            '2026-10-18 10:30:00',
            false,
            false,
        ];
        yield 'unpublish requires permission' => [
            CmsPublicationState::Draft,
            '',
            ['publication_state' => CmsPublicationState::Published->value, 'published_at' => '2026-10-18 10:30:00'],
            CmsPublicationState::Draft,
            null,
            true,
            true,
        ];
    }
}
