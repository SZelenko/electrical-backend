<?php

declare(strict_types=1);

namespace App\Tests\Functional\Panel;

use App\Tests\Support\PanelShapes;
use App\Tests\Support\PanelContentBuilder as B;
use App\Tests\Support\SharedFixtures;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @phpstan-import-type Content from PanelShapes
 */
final class SavePanelContentTest extends PanelApiTestCase
{
    public function testSaveContentSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user, 'Main');
        $this->advanceClock(60);

        $response = $this->putContent($user, $panel['id'], B::sample(), 1);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->panelBody($response);
        self::assertSame($panel['id'], $data['id']);
        self::assertSame('Main', $data['name']);
        self::assertSame(2, $data['version']);
        self::assertNotSame($panel['updatedAt'], $data['updatedAt']);
        self::assertSame($panel['createdAt'], $data['createdAt']);
        self::assertSame([], $data['shortCircuits']);
        self::assertSame([B::uuid(10)], array_column($data['content']['inputCables'], 'id'));
        self::assertSame([B::uuid(20)], array_column($data['content']['outputCables'], 'id'));

        // Persisted state: the old default cable is gone, the new tree is in.
        self::assertSame(2, $this->panelRow($panel['id'])?->version);
        self::assertSame(2, $this->countRows('cable', $panel['id']));
        self::assertSame(1, $this->countRows('din_rail', $panel['id']));
        self::assertSame(3, $this->countRows('panel_wire', $panel['id']));
        self::assertSame(2, $this->sqlInt('SELECT COUNT(*) FROM element_pole p JOIN element e ON e.id = p.element_id JOIN din_rail r ON r.id = e.rail_id WHERE r.panel_id = ?', [$panel['id']]));
        self::assertSame(2, $this->sqlInt('SELECT COUNT(*) FROM panel_wire_bend b JOIN panel_wire w ON w.id = b.wire_id WHERE w.panel_id = ?', [$panel['id']]));
        // The same content comes from GET.
        $get = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user));
        self::assertSame(2, $get['version']);
        self::assertEquals($data['content'], $get['content']);
        self::assertSame($data['updatedAt'], $get['updatedAt']);
    }

    public function testSaveContentReplacesWholeTreeSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->saveContent($user, $panel['id'], B::sample());

        $data = $this->saveContent($user, $panel['id'], B::minimal());

        self::assertSame(3, $data['version']);
        self::assertSame(1, $this->countRows('cable', $panel['id']));
        self::assertSame(0, $this->countRows('din_rail', $panel['id']));
        self::assertSame(0, $this->countRows('panel_wire', $panel['id']));
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM element'));
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM terminal_block'));
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM panel_wire_bend'));
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM cable_wire'));
    }

    public function testSaveContentReportsShortCircuitAsWarningSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = [
            'inputCables' => [B::inputCable(10, [B::uuid(11), B::uuid(12)])],
            'outputCables' => [],
            'terminalRow' => [],
            'rails' => [],
            'wires' => [B::wire(40, B::cableWireEnd(11), B::cableWireEnd(12))],
        ];

        $response = $this->putContent($user, $panel['id'], $content);

        self::assertSame(200, $response->getStatusCode(), 'A short circuit is a warning, not an error.');
        $data = $this->panelBody($response);
        self::assertSame(2, $data['version']);
        self::assertCount(1, $data['shortCircuits']);
        $group = $data['shortCircuits'][0];
        self::assertEqualsCanonicalizing([B::uuid(11), B::uuid(12)], $group['sources']);
        self::assertSame([B::uuid(40)], $group['wireIds']);
        self::assertCount(2, $group['terminals']);
        self::assertSame('cableWire', $group['terminals'][0]['kind']);
        // Saved all the same.
        self::assertSame(1, $this->countRows('panel_wire', $panel['id']));
        self::assertCount(1, $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user))['shortCircuits']);
    }

    /**
     * @param Content              $panelContent
     * @param array<string, mixed> $expected
     */
    #[DataProvider('validFixtureCases')]
    public function testSharedFixtureCasesSuccess(array $panelContent, array $expected): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        $data = $this->saveContent($user, $panel['id'], $panelContent);

        /** @var list<array{sources: list<string>, wireIds: list<string>}> $groups */
        $groups = $expected['groups'];
        $wanted = self::sets($groups);
        $actual = self::sets($data['shortCircuits']);
        self::assertEqualsCanonicalizing($wanted, $actual);
    }

    /**
     * @param Content              $panelContent
     * @param array<string, mixed> $expected
     */
    #[DataProvider('invalidFixtureCases')]
    public function testSharedFixtureInvalidCasesError(array $panelContent, array $expected): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        $violations = $this->violations($this->putContent($user, $panel['id'], $panelContent));

        $paths = array_values(array_unique(array_column($violations, 'propertyPath')));
        self::assertEqualsCanonicalizing($expected['violations'], $paths);
        self::assertSame(1, $this->panelRow($panel['id'])?->version);
    }

    /** @return iterable<string, array{Content, array<string, mixed>}> */
    public static function validFixtureCases(): iterable
    {
        foreach (SharedFixtures::shortCircuitCases() as $name => $case) {
            if (true === $case['expected']['valid']) {
                yield $name => [$case['panel'], $case['expected']];
            }
        }
    }

    /** @return iterable<string, array{Content, array<string, mixed>}> */
    public static function invalidFixtureCases(): iterable
    {
        foreach (SharedFixtures::shortCircuitCases() as $name => $case) {
            if (false === $case['expected']['valid']) {
                yield $name => [$case['panel'], $case['expected']];
            }
        }
    }

    /**
     * @param list<array{sources: list<string>, wireIds: list<string>}> $groups
     *
     * @return list<array{sources: list<string>, wireIds: list<string>}>
     */
    private static function sets(array $groups): array
    {
        $result = [];
        foreach ($groups as $g) {
            $sources = $g['sources'];
            $wireIds = $g['wireIds'];
            sort($sources);
            sort($wireIds);
            $result[] = ['sources' => $sources, 'wireIds' => $wireIds];
        }

        return $result;
    }

    public function testSaveContentStaleVersionError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->saveContent($user, $panel['id'], B::sample()); // version is now 2

        $response = $this->putContent($user, $panel['id'], B::minimal(), 1);

        $problem = $this->assertPanelProblem($response, 409, 'panel_version_conflict');
        self::assertSame(2, $problem['currentVersion'] ?? null);
        // Nothing changed.
        self::assertSame(2, $this->panelRow($panel['id'])?->version);
        self::assertSame(3, $this->countRows('panel_wire', $panel['id']));
    }

    public function testSaveContentFutureVersionError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        $response = $this->putContent($user, $panel['id'], B::minimal(), 5);

        $problem = $this->assertPanelProblem($response, 409, 'panel_version_conflict');
        self::assertSame(1, $problem['currentVersion'] ?? null);
    }

    public function testSaveContentAfterRenameWithOldVersionError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->panelApi('PATCH', '/panels/' . $panel['id'], $user, ['name' => 'Renamed']); // version 2

        $response = $this->putContent($user, $panel['id'], B::minimal(), 1);

        $problem = $this->assertPanelProblem($response, 409, 'panel_version_conflict');
        self::assertSame(2, $problem['currentVersion'] ?? null);
    }

    public function testSaveContentTwiceWithTheSameVersionError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        self::assertSame(200, $this->putContent($user, $panel['id'], B::minimal(), 1)->getStatusCode());

        $response = $this->putContent($user, $panel['id'], B::sample(), 1);

        $this->assertPanelProblem($response, 409, 'panel_version_conflict');
        self::assertSame(0, $this->countRows('panel_wire', $panel['id']), 'The losing save did not write.');
    }

    public function testTwoSavesWithinOneSecondBumpTheVersionEachTimeSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user); // the clock is frozen: everything happens in the same second

        $first = $this->saveContent($user, $panel['id'], B::minimal(), 1);
        $second = $this->saveContent($user, $panel['id'], B::sample(), 2);
        $third = $this->saveContent($user, $panel['id'], B::minimal(), 3);

        self::assertSame(2, $first['version']);
        self::assertSame(3, $second['version']);
        self::assertSame(4, $third['version']);
        self::assertSame($panel['updatedAt'], $second['updatedAt'], 'Same second, yet the version still moved.');
        self::assertSame(4, $this->storedVersion($panel['id']));
    }

    public function testConsecutiveSavesWithIdenticalIdsInOneEntityManagerSuccess(): void
    {
        $this->client->getKernelBrowser()->disableReboot();
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        $first = $this->saveContent($user, $panel['id'], B::sample());
        $second = $this->saveContent($user, $panel['id'], B::sample());
        $third = $this->saveContent($user, $panel['id'], B::sample());
        $get = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user));

        self::assertSame([2, 3, 4], [$first['version'], $second['version'], $third['version']]);
        self::assertEquals($first['content'], $second['content']);
        self::assertEquals($first['content'], $get['content']);
        self::assertSame(3, $this->countRows('panel_wire', $panel['id']));
        self::assertSame(2, $this->countRows('cable', $panel['id']));
    }

    public function testSaveContentIdsReusedInAnotherPanelOfTheSameUserError(): void
    {
        $user = $this->createVerifiedUser();
        $first = $this->createPanel($user, 'First');
        $second = $this->createPanel($user, 'Second');
        $this->saveContent($user, $first['id'], B::sample());

        $response = $this->putContent($user, $second['id'], B::sample());

        $problem = $this->assertPanelProblem($response, 409, 'panel_content_id_conflict');
        self::assertSame(1, $problem['currentVersion'] ?? null);
        self::assertSame(1, $this->panelRow($second['id'])?->version);
    }

    public function testSaveContentIdsOfAnotherUsersPanelError(): void
    {
        $victim = $this->createVerifiedUser();
        $attacker = $this->createVerifiedUser();
        $victimPanel = $this->createPanel($victim, 'Victim');
        $this->saveContent($victim, $victimPanel['id'], B::sample());
        $before = $this->panelBody($this->panelApi('GET', '/panels/' . $victimPanel['id'], $victim));
        $attackerPanel = $this->createPanel($attacker, 'Attacker');

        $reusedCableId = B::with(B::minimal(), ['inputCables.0.id' => B::uuid(10)]); // the victim's cable id
        foreach (['one reused cable id' => $reusedCableId, 'full copy of the content' => B::sample()] as $label => $content) {
            $response = $this->putContent($attacker, $attackerPanel['id'], $content);

            $problem = $this->assertPanelProblem($response, 409, 'panel_content_id_conflict');
            self::assertSame(1, $problem['currentVersion'] ?? null, $label . ': carries the attacker panel\'s version');
        }

        // The other panel is unchanged, and so is the attacker's own.
        $after = $this->panelBody($this->panelApi('GET', '/panels/' . $victimPanel['id'], $victim));
        self::assertEquals($before, $after);
        self::assertSame(1, $this->panelRow($attackerPanel['id'])?->version);
        self::assertSame(3, $this->countRows('panel_wire', $victimPanel['id']));
    }

    public function testSaveContentIdConflictRollsBackTheOldContentError(): void
    {
        $user = $this->createVerifiedUser();
        $other = $this->createPanel($user, 'Other');
        $this->saveContent($user, $other['id'], B::sample());
        $mine = $this->createPanel($user, 'Mine');
        $mineBefore = $this->panelBody($this->panelApi('GET', '/panels/' . $mine['id'], $user));

        $this->putContent($user, $mine['id'], B::sample());

        $mineAfter = $this->panelBody($this->panelApi('GET', '/panels/' . $mine['id'], $user));
        self::assertEquals($mineBefore, $mineAfter, 'The default input cable is still there.');
    }

    public function testSaveContentWireEndpointOfAnotherUsersPanelError(): void
    {
        $victim = $this->createVerifiedUser();
        $attacker = $this->createVerifiedUser();
        $victimPanel = $this->createPanel($victim, 'Victim');
        $this->saveContent($victim, $victimPanel['id'], B::sample());
        $before = $this->panelBody($this->panelApi('GET', '/panels/' . $victimPanel['id'], $victim));
        $attackerPanel = $this->createPanel($attacker, 'Attacker');

        // The wire's ends point at the victim's element pole, terminal block and cable wire ids,
        // none of which is declared in the attacker's own payload.
        $content = B::minimal();
        $content['inputCables'][0] = B::inputCable(510, [B::uuid(511)]);
        $content['wires'] = [
            B::wire(520, B::cableWireEnd(511), B::poleEnd(31, 0, 'input')),
            B::wire(521, B::terminalEnd(32, 0), B::cableWireEnd(11)),
            B::wire(522, B::cableWireEnd(511), B::cableWireEnd(21)),
        ];

        $violations = $this->violations($this->putContent($attacker, $attackerPanel['id'], $content));

        $paths = array_column($violations, 'propertyPath');
        self::assertContains('content.wires[0].end', $paths);
        self::assertContains('content.wires[1].start', $paths);
        self::assertContains('content.wires[1].end', $paths);
        self::assertContains('content.wires[2].end', $paths);
        self::assertSame(1, $this->panelRow($attackerPanel['id'])?->version);
        self::assertSame(0, $this->countRows('panel_wire', $attackerPanel['id']));
        self::assertEquals($before, $this->panelBody($this->panelApi('GET', '/panels/' . $victimPanel['id'], $victim)));
    }

    public function testSaveContentInvalidContentKeepsStoredContentError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $stored = $this->saveContent($user, $panel['id'], B::sample());
        $invalid = B::with(B::sample(), ['rails.0.elements[]' => B::element(33, 0, 1)]);

        $this->violations($this->putContent($user, $panel['id'], $invalid));

        $get = $this->panelBody($this->panelApi('GET', '/panels/' . $panel['id'], $user));
        self::assertSame(2, $get['version']);
        self::assertEquals($stored['content'], $get['content']);
    }

    public function testSaveContentOnTrashedPanelError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->panelApi('DELETE', '/panels/' . $panel['id'], $user);

        $response = $this->putContent($user, $panel['id'], B::sample());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->countRows('panel_wire', $panel['id']));
    }

    public function testSaveContentUnauthorizedError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        $response = $this->panelApi('PUT', '/panels/' . $panel['id'] . '/content', null, ['version' => 1, 'content' => B::minimal()]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testSaveContentForeignPanelIs404BeforeVersionCheckError(): void
    {
        $owner = $this->createVerifiedUser();
        $panel = $this->createPanel($owner);

        // Wrong version AND foreign: ownership wins, no version leak.
        $response = $this->putContent($this->createVerifiedUser(), $panel['id'], B::minimal(), 99);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('currentVersion', $response->getContent(false));
        self::assertSame(1, $this->panelRow($panel['id'])?->version);
    }

    public function testSaveContentMissingPanelError(): void
    {
        $user = $this->createVerifiedUser();

        $response = $this->putContent($user, '0198a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b', B::minimal(), 1);

        self::assertSame(404, $response->getStatusCode());
    }
}
