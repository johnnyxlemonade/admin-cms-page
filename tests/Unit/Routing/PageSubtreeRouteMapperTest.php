<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit\Routing;

use DomainException;
use Lemonade\Cms\Page\Routing\PageSubtreeRouteMapper;
use PHPUnit\Framework\TestCase;

/**
 * Overuje lokalizovany presun celeho podstromu bez noveho vyhodnoceni URL
 */
final class PageSubtreeRouteMapperTest extends TestCase
{
    /**
     * Zachova leaf casti cest ve vsech lokalizacich podstromu
     */
    public function testReplacesEveryLocalePrefixAndPreservesDescendantSuffixes(): void
    {
        $replacements = (new PageSubtreeRouteMapper())->replacements(
            [
                ['entity_id' => 10, 'locale' => 'cs', 'path' => 'obec'],
                ['entity_id' => 11, 'locale' => 'cs', 'path' => 'obec/kontakt'],
                ['entity_id' => 10, 'locale' => 'en', 'path' => 'municipality'],
                ['entity_id' => 11, 'locale' => 'en', 'path' => 'municipality/contact'],
            ],
            [
                'cs' => ['old' => 'obec', 'new' => 'region/obec'],
                'en' => ['old' => 'municipality', 'new' => 'region/municipality'],
            ],
        );

        self::assertSame([
            ['entityId' => 10, 'locale' => 'cs', 'path' => 'region/obec'],
            ['entityId' => 11, 'locale' => 'cs', 'path' => 'region/obec/kontakt'],
            ['entityId' => 10, 'locale' => 'en', 'path' => 'region/municipality'],
            ['entityId' => 11, 'locale' => 'en', 'path' => 'region/municipality/contact'],
        ], $replacements);
    }

    /**
     * Odmitne route ktera nepatri pod deklarovany puvodni prefix
     */
    public function testRejectsRouteOutsideDeclaredLocalePrefix(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('page.validation.route_inconsistent');

        (new PageSubtreeRouteMapper())->replacements(
            [['entity_id' => 11, 'locale' => 'cs', 'path' => 'jina/cesta']],
            ['cs' => ['old' => 'obec', 'new' => 'region/obec']],
        );
    }
}
