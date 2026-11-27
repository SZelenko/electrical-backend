<?php

declare(strict_types=1);

namespace App\Tests\Unit\Panel;

use App\Panel\Content\ShortCircuitGroup;
use App\Panel\PanelContentValidator;
use App\Panel\PanelValidationException;
use App\Panel\ShortCircuitChecker;
use App\Tests\Support\PanelShapes;
use App\Tests\Support\PanelContentBuilder as B;
use App\Tests\Support\SharedFixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Runs every case of the fixture shared with the frontend suite (`shared/fixtures/short-circuit-cases.json`). */
/**
 * @phpstan-import-type Content from PanelShapes
 */
#[CoversClass(ShortCircuitChecker::class)]
final class ShortCircuitCheckerTest extends TestCase
{
    /** @return iterable<string, array{Content, array<string, mixed>}> */
    public static function validCases(): iterable
    {
        foreach (SharedFixtures::shortCircuitCases() as $name => $case) {
            if (true === $case['expected']['valid']) {
                yield $name => [$case['panel'], $case['expected']];
            }
        }
    }

    /** @return iterable<string, array{Content, array<string, mixed>}> */
    public static function invalidCases(): iterable
    {
        foreach (SharedFixtures::shortCircuitCases() as $name => $case) {
            if (false === $case['expected']['valid']) {
                yield $name => [$case['panel'], $case['expected']];
            }
        }
    }

    public function testFixtureFileHasTheRequiredCasesSuccess(): void
    {
        $names = array_keys(SharedFixtures::shortCircuitCases());

        foreach ([
            'bridged_wires_of_one_input_cable',
            'two_input_cables_via_terminal_bar',
            'off_element_blocks_non_transit_pole',
            'off_element_conducts_through_transit_pole',
            'off_element_with_transit_neutral_phase_switched_no_short',
            'output_cable_wire_joins_two_sources',
            'output_cable_wire_alone_no_short',
            'chain_through_two_terminal_blocks',
            'unwired_panel',
            'single_input_wire_in_large_group_no_short',
            'sides_swapped_does_not_change_result_on',
            'sides_swapped_does_not_change_result_off',
            'wire_with_both_ends_on_the_same_terminal_is_rejected',
            'ats_state_a_sources_on_in0_and_in2_no_short',
            'ats_state_b_sources_on_in0_and_in2_no_short',
            'ats_state_off_sources_on_in0_and_in2_no_short',
            'ats_state_a_external_wire_out0_to_in2_short',
            'ats_state_b_separate_lines_no_short',
            'ats_state_b_out0_to_out1_wire_short',
            'ats_sides_swapped_does_not_change_result',
            'row_block_bar_joins_two_sources_short',
            'row_block_top_and_bottom_of_one_contact_are_one_node',
            'ats_output_pole_index_2_is_rejected',
            'ats_with_poles_is_rejected',
            'ats_width_1_is_rejected',
            'rail_block_13_contacts_is_rejected',
            'row_block_wrong_width_is_rejected',
            'contact_side_top_on_a_rail_block_is_rejected',
            'row_contact_top_to_bottom_same_node_is_rejected',
        ] as $required) {
            self::assertContains($required, $names);
        }
    }

    /**
     * @param Content              $panel
     * @param array<string, mixed> $expected
     */
    #[DataProvider('validCases')]
    public function testSharedCaseSuccess(array $panel, array $expected): void
    {
        $content = B::toContent($panel);

        // Every valid case is also valid input for the saver.
        new PanelContentValidator()->validate($content);

        $groups = new ShortCircuitChecker()->check($content);

        /** @var list<array{sources: list<string>, wireIds: list<string>}> $wanted */
        $wanted = $expected['groups'];

        self::assertEqualsCanonicalizing($this->normalizeExpected($wanted), $this->normalizeActual($groups));
    }

    /**
     * @param Content              $panel
     * @param array<string, mixed> $expected
     */
    #[DataProvider('invalidCases')]
    public function testSharedInvalidCaseError(array $panel, array $expected): void
    {
        try {
            new PanelContentValidator()->validate(B::toContent($panel));
            self::fail('The content must be rejected.');
        } catch (PanelValidationException $e) {
            $paths = array_map(static fn($v): string => $v->propertyPath, $e->violations);
            /** @var list<string> $expectedPaths */
            $expectedPaths = $expected['violations'];
            self::assertEqualsCanonicalizing($expectedPaths, array_values(array_unique($paths)));
        }
    }

    public function testGroupsAreSortedAndIndependentOfInputOrderSuccess(): void
    {
        // Two separate short circuits: (11,12) via wire 40 and (21,22) via wire 41, written in reverse order.
        $content = B::toContent([
            'inputCables' => [
                B::inputCable(20, [B::uuid(22), B::uuid(21)]),
                B::inputCable(10, [B::uuid(12), B::uuid(11)]),
            ],
            'outputCables' => [],
            'terminalRow' => [],
            'rails' => [],
            'wires' => [
                B::wire(41, B::cableWireEnd(22), B::cableWireEnd(21)),
                B::wire(40, B::cableWireEnd(12), B::cableWireEnd(11)),
            ],
        ]);

        $groups = new ShortCircuitChecker()->check($content);

        self::assertCount(2, $groups);
        self::assertSame([B::uuid(11), B::uuid(12)], $groups[0]->sources);
        self::assertSame([B::uuid(40)], $groups[0]->wireIds);
        self::assertSame([B::uuid(21), B::uuid(22)], $groups[1]->sources);
        self::assertSame([B::uuid(41)], $groups[1]->wireIds);
        self::assertCount(2, $groups[0]->terminals);
    }

    public function testTerminalsListEveryAttachedEndpointOnceSuccess(): void
    {
        $content = B::toContent([
            'inputCables' => [B::inputCable(10, [B::uuid(11), B::uuid(12)])],
            'outputCables' => [],
            'terminalRow' => [],
            'rails' => [B::rail(30, 24, [], [B::block(32, 0, 1, 4)])],
            'wires' => [
                B::wire(40, B::cableWireEnd(11), B::terminalEnd(32, 0)),
                B::wire(41, B::cableWireEnd(12), B::terminalEnd(32, 1)),
            ],
        ]);

        $groups = new ShortCircuitChecker()->check($content);

        self::assertCount(1, $groups);
        self::assertSame([B::uuid(40), B::uuid(41)], $groups[0]->wireIds);
        self::assertCount(4, $groups[0]->terminals);
    }

    public function testBothSidesOfOneContactAreListedOnceWithoutContactSideSuccess(): void
    {
        $content = B::toContent([
            'inputCables' => [B::inputCable(10, [B::uuid(11), B::uuid(12), B::uuid(13), B::uuid(14)])],
            'outputCables' => [],
            'terminalRow' => [B::rowBlock(50, 0, 2)],
            'rails' => [B::rail(30, 24, [], [B::block(32, 0, 1, 2)])],
            'wires' => [
                B::wire(40, B::cableWireEnd(11), B::terminalEnd(50, 0, 'top')),
                B::wire(41, B::cableWireEnd(12), B::terminalEnd(50, 0, 'bottom')),
                B::wire(42, B::cableWireEnd(13), B::terminalEnd(32, 0, 'left')),
                B::wire(43, B::cableWireEnd(14), B::terminalEnd(32, 0, 'right')),
            ],
        ]);

        $groups = new ShortCircuitChecker()->check($content);

        self::assertCount(2, $groups);
        foreach ([[50, 'row'], [32, 'rail']] as [$block]) {
            $matching = [];
            foreach ($groups as $group) {
                foreach ($group->terminals as $terminal) {
                    if (B::uuid($block) === $terminal->terminalBlockId) {
                        $matching[] = $terminal;
                    }
                }
            }
            self::assertCount(1, $matching);
            self::assertSame(0, $matching[0]->terminalIndex);
            self::assertNull($matching[0]->contactSide);
            self::assertSame('t:' . B::uuid($block) . ':0', $matching[0]->key());
        }
    }

    public function testEmptyContentHasNoGroupsSuccess(): void
    {
        self::assertSame([], new ShortCircuitChecker()->check(B::toContent(B::minimal())));
    }

    public function testPanelWithoutWiresHasNoGroupsEvenWithManySourcesSuccess(): void
    {
        $content = B::toContent([
            'inputCables' => [B::inputCable(10, [B::uuid(11), B::uuid(12), B::uuid(13), B::uuid(14)])],
            'outputCables' => [],
            'terminalRow' => [],
            'rails' => [],
            'wires' => [],
        ]);

        self::assertSame([], new ShortCircuitChecker()->check($content));
    }

    public function testThreeSourcesInOneGroupAreOneGroupSuccess(): void
    {
        $content = B::toContent([
            'inputCables' => [B::inputCable(10, [B::uuid(11), B::uuid(12), B::uuid(13)])],
            'outputCables' => [],
            'terminalRow' => [],
            'rails' => [],
            'wires' => [
                B::wire(40, B::cableWireEnd(11), B::cableWireEnd(12)),
                B::wire(41, B::cableWireEnd(12), B::cableWireEnd(13)),
            ],
        ]);

        $groups = new ShortCircuitChecker()->check($content);

        self::assertCount(1, $groups);
        self::assertSame([B::uuid(11), B::uuid(12), B::uuid(13)], $groups[0]->sources);
    }

    public function testSwitchedOffElementDoesNotConductSuccess(): void
    {
        // Same layout as a short circuit through a closed element, but the element is off.
        $element = B::element(31, 0, 1, 1, false);
        $content = B::toContent([
            'inputCables' => [B::inputCable(10, [B::uuid(11), B::uuid(12)])],
            'outputCables' => [],
            'terminalRow' => [],
            'rails' => [B::rail(30, 24, [$element])],
            'wires' => [
                B::wire(40, B::cableWireEnd(11), B::poleEnd(31, 0, 'input')),
                B::wire(41, B::cableWireEnd(12), B::poleEnd(31, 0, 'output')),
            ],
        ]);

        self::assertSame([], new ShortCircuitChecker()->check($content));

        $element['on'] = true;
        $content = B::toContent([
            'inputCables' => [B::inputCable(10, [B::uuid(11), B::uuid(12)])],
            'outputCables' => [],
            'terminalRow' => [],
            'rails' => [B::rail(30, 24, [$element])],
            'wires' => [
                B::wire(40, B::cableWireEnd(11), B::poleEnd(31, 0, 'input')),
                B::wire(41, B::cableWireEnd(12), B::poleEnd(31, 0, 'output')),
            ],
        ]);

        self::assertCount(1, new ShortCircuitChecker()->check($content));
    }

    /**
     * @param list<array{sources: list<string>, wireIds: list<string>}> $expected
     *
     * @return list<array{sources: list<string>, wireIds: list<string>}>
     */
    private function normalizeExpected(array $expected): array
    {
        return array_map(static function (array $g): array {
            $sources = $g['sources'];
            $wireIds = $g['wireIds'];
            sort($sources);
            sort($wireIds);

            return ['sources' => $sources, 'wireIds' => $wireIds];
        }, $expected);
    }

    /**
     * @param list<ShortCircuitGroup> $groups
     *
     * @return list<array{sources: list<string>, wireIds: list<string>}>
     */
    private function normalizeActual(array $groups): array
    {
        return array_map(static function (ShortCircuitGroup $g): array {
            $sources = $g->sources;
            $wireIds = $g->wireIds;
            sort($sources);
            sort($wireIds);

            return ['sources' => $sources, 'wireIds' => $wireIds];
        }, $groups);
    }
}
