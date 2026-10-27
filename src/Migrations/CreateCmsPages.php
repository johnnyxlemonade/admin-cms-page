<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Migrations;

use Lemonade\Framework\Database\Migration\MigrationInterface;
use Lemonade\Framework\Database\Schema\Blueprint\TableBlueprint;
use Lemonade\Framework\Database\Schema\Schema;

/**
 * Vytvari stabilni strom stranek a lokalizovane obsahove mutace
 */
final readonly class CreateCmsPages implements MigrationInterface
{
    /**
     * Vrati stabilni identifikator schema migrace
     */
    public static function identifier(): string
    {
        return '20261019120000_create_cms_pages';
    }

    /**
     * Vytvori presne dve tabulky CMS Page s databazovymi invarianty
     */
    public function up(Schema $schema): void
    {
        $schema->create('cms_page', static function (TableBlueprint $table): void {
            $table->id()->comment('Stabilni identifikator stranky');
            $table->unsignedBigInteger('created_by')->nullable()->comment('Puvodni lokalni tvurce stranky');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('Posledni lokalni uzivatel, ktery aggregate skutecne zmenil');
            $table->unsignedBigInteger('deleted_by')->nullable()->comment('Lokalni uzivatel, ktery provedl aktualni soft delete');
            $table->unsignedBigInteger('owner_id')->nullable()->comment('Aktualni vlastnik celeho page aggregate');
            $table->unsignedBigInteger('parent_id')->nullable()->comment('Volitelny rodic ve spolecne jazykove hierarchii');
            $table->unsignedInteger('sort_order')->default(0)->comment('Poradi mezi sourozenci');
            $table->string('template_key', 100)->comment('Hostem interpretovany klic sablony');
            $table->timestamps();
            $table->datetime('deleted_at')->nullable()->comment('Okamzik soft delete cele stranky');
            $table->index(['parent_id', 'sort_order', 'id'], 'idx_cms_page_parent_order');
            $table->index(['deleted_at'], 'idx_cms_page_deleted');
            $table->index(['template_key'], 'idx_cms_page_template');
            $table->index(['owner_id', 'deleted_at'], 'idx_cms_page_owner_deleted');
            $table->foreign('parent_id', 'fk_cms_page_parent')->references('id')->on('cms_page')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by', 'fk_cms_page_created_by')->references('id')->on('system_user')->nullOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'fk_cms_page_updated_by')->references('id')->on('system_user')->nullOnDelete()->restrictOnUpdate();
            $table->foreign('deleted_by', 'fk_cms_page_deleted_by')->references('id')->on('system_user')->nullOnDelete()->restrictOnUpdate();
            $table->foreign('owner_id', 'fk_cms_page_owner')->references('id')->on('system_user')->nullOnDelete()->restrictOnUpdate();
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
        }, ifNotExists: true);

        $schema->create('cms_page_translation', static function (TableBlueprint $table): void {
            $table->id()->comment('Identifikator lokalizovane mutace stranky');
            $table->unsignedBigInteger('page_id')->comment('Stabilni vlastnik lokalizovaneho obsahu');
            $table->string('locale', 10)->comment('Canonical kod content locale');
            $table->string('title', 180)->nullable()->comment('Lokalizovany nadpis stranky');
            $table->longText('summary')->nullable()->comment('Lokalizovany uvodni text stranky');
            $table->longText('content')->nullable()->comment('Lokalizovany obsah stranky');
            $table->string('page_title', 180)->nullable()->comment('Lokalizovany SEO titul');
            $table->string('meta_description', 255)->nullable()->comment('Lokalizovany SEO popis');
            $table->tinyInteger('publication_state')->unsigned()->default(0)->comment('New, draft nebo published lifecycle lokalizace');
            $table->datetime('published_at')->nullable()->comment('Okamzik verejne viditelnosti lokalizace');
            $table->timestamps();
            $table->unique(['page_id', 'locale'], 'uq_cms_page_translation_locale');
            $table->index(['locale', 'title'], 'idx_cms_page_translation_title');
            $table->index(['publication_state', 'published_at'], 'idx_cms_page_translation_visibility');
            $table->foreign('page_id', 'fk_cms_page_translation_page')->references('id')->on('cms_page')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('locale', 'fk_cms_page_translation_locale')->references('code')->on('system_language')->restrictOnDelete()->restrictOnUpdate();
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
        }, ifNotExists: true);
    }
}
