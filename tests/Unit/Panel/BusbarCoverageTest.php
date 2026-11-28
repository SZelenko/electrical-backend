<?php

declare(strict_types=1);

namespace App\Tests\Unit\Panel;

use App\Panel\BusbarCoverage;
use App\Panel\BusbarCoverageReason;
use App\Panel\Content\BusbarDto;
use App\Panel\Content\RailDto;
use App\Tests\Support\PanelContentBuilder as B;
use App\Tests\Support\PanelShapes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type Element from PanelShapes
 * @phpstan-import-type Block from PanelShapes
 */
#[CoversClass(BusbarCoverage::class)]
final class BusbarCoverageTest extends TestCase
{
    /**
     * @param list<Element> $elements
     * @param list<Block>   $blocks
     *
     * @return array{RailDto, BusbarDto}
     */
    private static function railWith(array $elements, array $blocks, int $anchor, int $count): array
    {
        $content = B::toContent([
            'railWidthModules' => 60,
            'inputCableSide' => 'top',
            'outputCableSide' => 'bottom',
            'inputCables' => [B::inputCable(10)],
            'outputCables' => [],
            'terminalRow' => [],
            'bottomTerminalRow' => [],
            'rails' => [B::rail(30, $elements, $blocks, 0, [B::busbar(60, $anchor, $count)])],
            'wires' => [],
        ]);

        return [$content->rails[0], $content->rails[0]->busbars[0]];
    }

    /** @param list<string> $ids */
    private function assertDevices(BusbarCoverage $coverage, array $ids): void
    {
        self::assertSame($ids, array_map(static fn($d): string => $d->id, $coverage->devices));
    }

    public function testWalkOfAdjacentDevicesIsValidSuccess(): void
    {
        [$rail, $busbar] = self::railWith([B::element(31, 0), B::element(32, 1), B::element(33, 2)], [], 31, 3);

        $coverage = BusbarCoverage::walk(BusbarCoverage::railItems($rail), $busbar);

        self::assertSame(BusbarCoverageReason::None, $coverage->reason);
        $this->assertDevices($coverage, [B::uuid(31), B::uuid(32), B::uuid(33)]);
    }

    public function testWalkStartsAtTheAnchorAndStopsAtCountSuccess(): void
    {
        [$rail, $busbar] = self::railWith([B::element(31, 0), B::element(32, 1), B::element(33, 2), B::element(34, 3)], [], 32, 2);

        $coverage = BusbarCoverage::walk(BusbarCoverage::railItems($rail), $busbar);

        self::assertSame(BusbarCoverageReason::None, $coverage->reason);
        $this->assertDevices($coverage, [B::uuid(32), B::uuid(33)]);
    }

    public function testWalkFollowsOffsetOrderNotListOrderSuccess(): void
    {
        [$rail, $busbar] = self::railWith([B::element(33, 2), B::element(31, 0), B::element(32, 1)], [], 31, 3);

        $coverage = BusbarCoverage::walk(BusbarCoverage::railItems($rail), $busbar);

        self::assertSame(BusbarCoverageReason::None, $coverage->reason);
        $this->assertDevices($coverage, [B::uuid(31), B::uuid(32), B::uuid(33)]);
    }

    public function testWalkAllowsLeadingGapAndWideDevicesSuccess(): void
    {
        // the anchor does not have to start at module 0; widths are summed, not counted
        [$rail, $busbar] = self::railWith([B::element(31, 5, 2, 2), B::element(32, 7, 3, 3)], [], 31, 2);

        $coverage = BusbarCoverage::walk(BusbarCoverage::railItems($rail), $busbar);

        self::assertSame(BusbarCoverageReason::None, $coverage->reason);
        $this->assertDevices($coverage, [B::uuid(31), B::uuid(32)]);
    }

    public function testWalkOverAllSwappedDevicesIsValidSuccess(): void
    {
        $a = B::element(31, 0);
        $b = B::element(32, 1);
        $a['sidesSwapped'] = $b['sidesSwapped'] = true;
        [$rail, $busbar] = self::railWith([$a, $b], [], 31, 2);

        self::assertSame(BusbarCoverageReason::None, BusbarCoverage::walk(BusbarCoverage::railItems($rail), $busbar)->reason);
    }

    public function testWalkIgnoresItemsBeforeTheAnchorSuccess(): void
    {
        [$rail, $busbar] = self::railWith([B::ats(30, 0, 2), B::element(31, 2), B::element(32, 3)], [B::block(40, 10)], 31, 2);

        $coverage = BusbarCoverage::walk(BusbarCoverage::railItems($rail), $busbar);

        self::assertSame(BusbarCoverageReason::None, $coverage->reason);
        $this->assertDevices($coverage, [B::uuid(31), B::uuid(32)]);
    }

    /**
     * @param list<Element> $elements
     * @param list<Block>   $blocks
     * @param list<string>  $devices
     */
    #[DataProvider('invalidWalks')]
    public function testInvalidWalkError(array $elements, array $blocks, int $anchor, int $count, BusbarCoverageReason $reason, array $devices): void
    {
        [$rail, $busbar] = self::railWith($elements, $blocks, $anchor, $count);

        $coverage = BusbarCoverage::walk(BusbarCoverage::railItems($rail), $busbar);

        self::assertSame($reason, $coverage->reason);
        $this->assertDevices($coverage, $devices);
    }

    /** @return iterable<string, array{list<Element>, list<Block>, int, int, BusbarCoverageReason, list<string>}> */
    public static function invalidWalks(): iterable
    {
        yield 'too few devices to the rail end' => [
            [B::element(31, 0), B::element(32, 1)], [], 31, 3, BusbarCoverageReason::TooFew, [B::uuid(31), B::uuid(32)],
        ];
        yield 'anchor is the last item' => [
            [B::element(31, 0)], [], 31, 2, BusbarCoverageReason::TooFew, [B::uuid(31)],
        ];
        yield 'an ATS is in the way' => [
            [B::element(31, 0), B::ats(32, 1, 2), B::element(33, 3)], [], 31, 3, BusbarCoverageReason::ForeignItem, [B::uuid(31)],
        ];
        yield 'a terminal block is in the way' => [
            [B::element(31, 0), B::element(33, 2)], [B::block(40, 1)], 31, 3, BusbarCoverageReason::ForeignItem, [B::uuid(31)],
        ];
        yield 'a terminal block follows directly' => [
            [B::element(31, 0)], [B::block(40, 1)], 31, 2, BusbarCoverageReason::ForeignItem, [B::uuid(31)],
        ];
        yield 'a free module between devices' => [
            [B::element(31, 0), B::element(32, 2)], [], 31, 2, BusbarCoverageReason::Gap, [B::uuid(31)],
        ];
        yield 'a gap after a wide device' => [
            [B::element(31, 0, 2, 2), B::element(32, 3)], [], 31, 2, BusbarCoverageReason::Gap, [B::uuid(31)],
        ];
        yield 'overlapping devices count as a gap' => [
            [B::element(31, 0, 2, 2), B::element(32, 1)], [], 31, 2, BusbarCoverageReason::Gap, [B::uuid(31)],
        ];
        yield 'the anchor is an ATS' => [
            [B::ats(31, 0, 2), B::element(32, 2)], [], 31, 2, BusbarCoverageReason::AnchorMissing, [],
        ];
        yield 'the anchor does not exist' => [
            [B::element(32, 0), B::element(33, 1)], [], 999, 2, BusbarCoverageReason::AnchorMissing, [],
        ];
        yield 'the anchor id is a terminal block' => [
            [B::element(32, 0), B::element(33, 1)], [B::block(40, 5)], 40, 2, BusbarCoverageReason::AnchorMissing, [],
        ];
    }

    public function testMixedSidesSwappedKeepsTheWholeCoveredSetError(): void
    {
        $b = B::element(32, 1);
        $b['sidesSwapped'] = true;
        [$rail, $busbar] = self::railWith([B::element(31, 0), $b, B::element(33, 2)], [], 31, 3);

        $coverage = BusbarCoverage::walk(BusbarCoverage::railItems($rail), $busbar);

        self::assertSame(BusbarCoverageReason::MixedSides, $coverage->reason);
        $this->assertDevices($coverage, [B::uuid(31), B::uuid(32), B::uuid(33)]);
    }

    public function testMixedSidesSwappedOnTheAnchorOnlyError(): void
    {
        $a = B::element(31, 0);
        $a['sidesSwapped'] = true;
        [$rail, $busbar] = self::railWith([$a, B::element(32, 1)], [], 31, 2);

        self::assertSame(BusbarCoverageReason::MixedSides, BusbarCoverage::walk(BusbarCoverage::railItems($rail), $busbar)->reason);
    }

    public function testStructuralReasonWinsOverMixedSidesError(): void
    {
        $a = B::element(31, 0);
        $a['sidesSwapped'] = true;
        [$rail, $busbar] = self::railWith([$a, B::element(32, 3)], [], 31, 2);

        self::assertSame(BusbarCoverageReason::Gap, BusbarCoverage::walk(BusbarCoverage::railItems($rail), $busbar)->reason);
    }
}
