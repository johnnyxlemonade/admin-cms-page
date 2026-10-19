<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit\Hierarchy;

use Lemonade\Cms\Page\Hierarchy\PageHierarchy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Overuje libovolne zanoreni a ochranu pred cykly
 */
final class PageHierarchyTest extends TestCase
{
    /**
     * Vytvori triurovnovy strom se samostatnym rootem
     */
    private function hierarchy(): PageHierarchy
    {
        return new PageHierarchy([
            ['id' => 1, 'parent_id' => null, 'sort_order' => 0],
            ['id' => 2, 'parent_id' => 1, 'sort_order' => 0],
            ['id' => 3, 'parent_id' => 2, 'sort_order' => 0],
            ['id' => 4, 'parent_id' => null, 'sort_order' => 1],
        ]);
    }

    /**
     * Vrati podstrom, predky, hloubku a cely les v preorder poradi
     */
    public function testReturnsArbitrarilyNestedSubtree(): void
    {
        self::assertSame([1, 2, 3], $this->hierarchy()->descendantIds(1));
        self::assertSame([2, 1], $this->hierarchy()->ancestorIds(3));
        self::assertSame(2, $this->hierarchy()->depth(3));
        self::assertSame([1, 2, 3, 4], $this->hierarchy()->preorder());
    }

    /**
     * Odmitne presun rodice pod jeho vlastniho potomka
     */
    public function testRejectsMoveBelowDescendant(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('page.validation.parent_cycle');

        $this->hierarchy()->assertMove(1, 3);
    }

    /**
     * Odmitne kandidata jehoz existujici retezec rodicu je uz cyklicky
     */
    public function testRejectsMoveBelowCorruptedCyclicBranch(): void
    {
        $hierarchy = new PageHierarchy([
            ['id' => 1, 'parent_id' => null, 'sort_order' => 0],
            ['id' => 4, 'parent_id' => 5, 'sort_order' => 0],
            ['id' => 5, 'parent_id' => 4, 'sort_order' => 0],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('page.validation.parent_cycle');

        $hierarchy->assertMove(1, 4);
    }

    /**
     * Radi sourozence stabilne podle poradi a ID bez zavislosti na vstupu
     */
    public function testPreorderIsDeterministicForUnsortedRows(): void
    {
        $hierarchy = new PageHierarchy([
            ['id' => 3, 'parent_id' => 1, 'sort_order' => 1],
            ['id' => 2, 'parent_id' => 1, 'sort_order' => 0],
            ['id' => 1, 'parent_id' => null, 'sort_order' => 0],
            ['id' => 4, 'parent_id' => 1, 'sort_order' => 1],
        ]);

        self::assertSame([1, 2, 3, 4], $hierarchy->preorder());
    }

    /**
     * Povoli presun do nezavisle vetve i na root uroven
     */
    public function testAllowsMoveToIndependentBranchOrRoot(): void
    {
        $this->hierarchy()->assertMove(3, 4);
        $this->hierarchy()->assertMove(3, null);

        self::expectNotToPerformAssertions();
    }

    /**
     * Povoli presun stranky 1 s potomky pod nezavisleho rodice 3
     */
    public function testAllowsReportedMoveFromPageOneBelowPageThree(): void
    {
        $hierarchy = new PageHierarchy([
            ['id' => 1, 'parent_id' => null, 'sort_order' => 0],
            ['id' => 2, 'parent_id' => 1, 'sort_order' => 0],
            ['id' => 3, 'parent_id' => null, 'sort_order' => 1],
        ]);

        $hierarchy->assertMove(1, 3);

        self::assertSame([1, 2], $hierarchy->descendantIds(1));
    }
}
