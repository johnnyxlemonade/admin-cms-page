<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit\Services;

use DomainException;
use Lemonade\Admin\Audit\AuditActor;
use Lemonade\Admin\Event\TransactionalEventProcessor;
use Lemonade\Admin\Identity\LocalUserDirectory;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Cms\Page\Models\PageModel;
use Lemonade\Cms\Page\Publication\PagePublicationPlanResolver;
use Lemonade\Cms\Page\Routing\PageSubtreeRouteMapper;
use Lemonade\Cms\Page\Services\PageService;
use Lemonade\Cms\Page\Template\PageTemplateDefinition;
use Lemonade\Cms\Page\Template\PageTemplateProviderInterface;
use Lemonade\Cms\Routing\Cms\CmsRouteReservationService;
use Lemonade\Framework\Cache\CacheManager;
use Lemonade\Framework\Cache\Store\ArrayCacheItemPool;
use Lemonade\Framework\Database\Connection\ConnectionInterface;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use Lemonade\Framework\Database\DatabaseResultInterface;
use Lemonade\Framework\Support\Slug\Slugger;
use PHPUnit\Framework\TestCase;

/**
 * Overuje save rozhodnuti Pages bez databazovych write integraci
 */
final class PageServiceValidationTest extends TestCase
{
    /**
     * Skutecny no-op neotevre auditovanou transakci ani neprovede write
     */
    public function testNoOpSaveWithoutPublicationInputDoesNotEnterAuditedMutation(): void
    {
        $translation = $this->translation();
        $translation['summary'] = 'Úvodní text';
        $service = $this->service(
            currentTemplate: 'content',
            translation: $translation,
            routePath: 'kontakt',
        );
        $payload = $this->payload();
        $payload['summary'] = 'Úvodní text';
        unset($payload['publication_state'], $payload['published_at']);

        $service->save(AuditActor::user(1), 7, $payload);

        self::expectNotToPerformAssertions();
    }

    /**
     * Existujici mutace zustava editovatelna i po deaktivaci locale
     */
    public function testExistingDisabledLocaleMayRemainUnchanged(): void
    {
        $service = $this->service(
            currentTemplate: 'content',
            translation: $this->translation(locale: 'de'),
            routePath: 'kontakt',
        );

        $service->save(AuditActor::user(1), 7, $this->payload(locale: 'de'));

        self::expectNotToPerformAssertions();
    }

    /**
     * Nova mutace v disabled locale se odmita pred zahajenim write transakce
     */
    public function testNewDisabledLocaleIsRejected(): void
    {
        $service = $this->service(
            currentTemplate: 'content',
            translation: null,
            routePath: null,
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('page.validation.locale_invalid');

        $service->save(AuditActor::user(1), 7, $this->payload(locale: 'de'));
    }

    /**
     * Ulozenou nedostupnou sablonu lze zachovat na draftu
     */
    public function testUnavailableTemplateMayBeKeptOnDraft(): void
    {
        $service = $this->service(
            currentTemplate: 'removed-template',
            translation: $this->translation(),
            routePath: 'kontakt',
        );

        $service->save(
            AuditActor::user(1),
            7,
            $this->payload(templateKey: 'removed-template'),
        );

        self::expectNotToPerformAssertions();
    }

    /**
     * Nove prirazeni nedostupne sablony se odmita
     */
    public function testUnavailableTemplateCannotBeAssigned(): void
    {
        $service = $this->service(
            currentTemplate: 'content',
            translation: $this->translation(),
            routePath: 'kontakt',
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('page.validation.template_invalid');

        $service->save(
            AuditActor::user(1),
            7,
            $this->payload(templateKey: 'removed-template'),
        );
    }

    /**
     * Publikace se vzdy odmita pokud ulozena sablona uz neni dostupna
     */
    public function testUnavailableTemplateCannotBePublished(): void
    {
        $service = $this->service(
            currentTemplate: 'removed-template',
            translation: $this->translation(),
            routePath: 'kontakt',
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('page.validation.template_invalid');

        $service->save(
            AuditActor::user(1),
            7,
            $this->payload(templateKey: 'removed-template', publicationState: 2),
        );
    }

    /**
     * @param array<string,mixed>|null $translation
     */
    private function service(
        string $currentTemplate,
        ?array $translation,
        ?string $routePath,
    ): PageService {
        $rows = [
            [
                'id' => 7,
                'parent_id' => null,
                'sort_order' => 0,
                'template_key' => $currentTemplate,
                'deleted_at' => null,
            ],
            $translation,
        ];
        if ($translation !== null) {
            $rows[] = $routePath === null ? null : ['path' => $routePath];
        }
        $driver = $this->driver($rows);
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('select')->willReturnCallback(
            static function (string $sql): array {
                if (str_contains($sql, 'system_language')) {
                    return [['code' => 'cs', 'name' => 'Cestina', 'is_default' => 1]];
                }

                return [['allowed' => 1]];
            },
        );
        $database = new Database($connection, $driver);

        return new PageService(
            pages: new PageModel($driver),
            languages: new LanguageRegistry($database, new CacheManager(new ArrayCacheItemPool())),
            routes: $this->instanceWithoutConstructor(CmsRouteReservationService::class),
            slugger: new Slugger(),
            events: $this->instanceWithoutConstructor(TransactionalEventProcessor::class),
            templates: new PageServiceTemplateProviderStub(),
            subtreeRoutes: new PageSubtreeRouteMapper(),
            publicationPlans: new PagePublicationPlanResolver(),
            users: new LocalUserDirectory($database),
        );
    }

    /**
     * @param list<array<string,mixed>|null> $rows
     */
    private function driver(array $rows): DatabaseDriverInterface
    {
        $results = [];
        foreach ($rows as $row) {
            $result = $this->createMock(DatabaseResultInterface::class);
            $result->method('result_array')->willReturn($row === null ? [] : [$row]);
            $results[] = $result;
        }
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(
            static fn(string $identifier): string => $identifier,
        );
        $driver->method('query')->willReturnCallback(
            static function () use (&$results): DatabaseResultInterface {
                $result = array_shift($results);
                if (!$result instanceof DatabaseResultInterface) {
                    throw new \LogicException('No-op save attempted an unexpected database operation.');
                }

                return $result;
            },
        );

        return $driver;
    }

    /**
     * @return array<string,int|string|null>
     */
    private function translation(string $locale = 'cs'): array
    {
        return [
            'id' => 11,
            'page_id' => 7,
            'locale' => $locale,
            'title' => 'Kontakt',
            'summary' => null,
            'content' => null,
            'page_title' => null,
            'meta_description' => null,
            'publication_state' => 1,
            'published_at' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(
        string $locale = 'cs',
        string $templateKey = 'content',
        int $publicationState = 1,
    ): array {
        return [
            'locale' => $locale,
            'title' => 'Kontakt',
            'summary' => '',
            'content' => '',
            'page_title' => '',
            'meta_description' => '',
            'parent_id' => '',
            'template_key' => $templateKey,
            'publication_state' => (string) $publicationState,
            'published_at' => '',
        ];
    }

    /**
     * Vytvori final collaborator ktery v dane code path nesmi byt volan
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function instanceWithoutConstructor(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}

/**
 * Poskytuje jedinou dostupnou host content sablonu
 */
final readonly class PageServiceTemplateProviderStub implements PageTemplateProviderInterface
{
    public function templates(): array
    {
        return [new PageTemplateDefinition('content', 'page.template.content')];
    }
}
