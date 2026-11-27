<?php

declare(strict_types=1);

namespace App\Tests\Functional\Panel;

use App\Entity\Panel\Panel;
use PHPUnit\Framework\Attributes\DataProvider;

final class CreatePanelTest extends PanelApiTestCase
{
    public function testCreatePanelSuccess(): void
    {
        $user = $this->createVerifiedUser();

        $response = $this->panelApi('POST', '/panels', $user, ['name' => 'Main panel']);

        self::assertSame(201, $response->getStatusCode());
        self::assertStringContainsString('application/ld+json', $response->getHeaders(false)['content-type'][0] ?? '');
        $data = $this->panelBody($response);
        self::assertSame('Panel', $this->json($response)['@type']);
        self::assertSame('/api/v1/panels/' . $data['id'], $this->json($response)['@id']);
        self::assertSame('Main panel', $data['name']);
        self::assertSame(1, $data['version']);
        self::assertNull($data['deletedAt'] ?? null);
        self::assertSame($data['createdAt'], $data['updatedAt']);
        self::assertSame([], $data['shortCircuits']);

        $content = $data['content'];
        self::assertSame([], $content['outputCables']);
        self::assertSame([], $content['rails']);
        self::assertSame([], $content['wires']);
        self::assertCount(1, $content['inputCables']);
        $cable = $content['inputCables'][0];
        self::assertEquals(230, $cable['voltage']);
        self::assertEquals(50, $cable['frequency']);
        self::assertEquals(4, $cable['crossSection']);
        self::assertSame(['brown', 'blue', 'green-yellow'], array_column($cable['wires'], 'colour'));

        // Persisted: row, owner, one input cable with three wires.
        $panel = $this->panelRow($data['id']);
        self::assertInstanceOf(Panel::class, $panel);
        self::assertSame($user->id->toRfc4122(), $panel->owner->id->toRfc4122());
        self::assertSame('main panel', $panel->nameKey);
        self::assertNull($panel->deletedAt);
        self::assertSame(1, $this->countRows('cable', $data['id']));
        self::assertSame(3, $this->sqlInt('SELECT COUNT(*) FROM cable_wire w JOIN cable c ON c.id = w.cable_id WHERE c.panel_id = ?', [$data['id']]));

        // The same content comes back from GET.
        $get = $this->panelBody($this->panelApi('GET', '/panels/' . $data['id'], $user));
        self::assertEquals($content, $get['content']);
    }

    public function testCreatePanelTrimsNameSuccess(): void
    {
        $user = $this->createVerifiedUser();

        $data = $this->createPanel($user, "  \tСхема щита \n");

        self::assertSame('Схема щита', $data['name']);
        self::assertSame('Схема щита', $this->panelRow($data['id'])?->name);
    }

    public function testCreatePanelNameBoundariesSuccess(): void
    {
        $user = $this->createVerifiedUser();

        self::assertSame('x', $this->createPanel($user, 'x')['name']);
        self::assertSame(str_repeat('x', 100), $this->createPanel($user, str_repeat('x', 100))['name']);
        // 100 code points, not bytes.
        self::assertSame(str_repeat('щ', 100), $this->createPanel($user, str_repeat('щ', 100))['name']);
        self::assertSame(str_repeat('😀', 100), $this->createPanel($user, str_repeat('😀', 100))['name']);
        // An inner NBSP is allowed; only leading/trailing separators are rejected.
        self::assertSame("a\u{00A0}b", $this->createPanel($user, "a\u{00A0}b")['name']);
    }

    public function testCreatePanelSameNameForDifferentUsersSuccess(): void
    {
        $alice = $this->createVerifiedUser();
        $bob = $this->createVerifiedUser();

        $this->createPanel($alice, 'Shared name');
        $response = $this->panelApi('POST', '/panels', $bob, ['name' => 'Shared name']);

        self::assertSame(201, $response->getStatusCode());
    }

    public function testCreatePanelWithNameOfTrashedPanelSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $old = $this->createPanel($user, 'Reused');
        self::assertSame(204, $this->panelApi('DELETE', '/panels/' . $old['id'], $user)->getStatusCode());

        $response = $this->panelApi('POST', '/panels', $user, ['name' => 'reused']);

        self::assertSame(201, $response->getStatusCode());
    }

    public function testCreatePanelUnauthorizedError(): void
    {
        $response = $this->panelApi('POST', '/panels', null, ['name' => 'Main']);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('token_invalid', $this->json($response)['type']);
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM panel'));
    }

    public function testCreatePanelGarbageTokenError(): void
    {
        $response = $this->api('POST', '/panels', ['name' => 'Main'], 'garbage.token.value', headers: ['Accept' => self::LD, 'Content-Type' => self::LD]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testCreatePanelDuplicateNameError(): void
    {
        $user = $this->createVerifiedUser();
        $this->createPanel($user, 'Main panel');

        $response = $this->panelApi('POST', '/panels', $user, ['name' => 'Main panel']);

        $problem = $this->assertPanelProblem($response, 409, 'panel_name_conflict');
        self::assertArrayNotHasKey('currentVersion', $problem, 'No panel is locked on create.');
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM panel'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function conflictingNames(): iterable
    {
        yield 'different case' => ['Щит', 'щИТ'];
        yield 'latin different case' => ['Main Panel', 'MAIN PANEL'];
        yield 'surrounding whitespace' => ['Main', "  Main\n"];
        yield 'NFC vs NFD' => ["Caf\u{00E9}", "Cafe\u{0301}"];
        yield 'NFD vs NFC' => ["Cafe\u{0301}", "CAF\u{00C9}"];
    }

    #[DataProvider('conflictingNames')]
    public function testCreatePanelEquivalentNameConflictError(string $first, string $second): void
    {
        $user = $this->createVerifiedUser();
        $this->createPanel($user, $first);

        $response = $this->panelApi('POST', '/panels', $user, ['name' => $second]);

        $this->assertPanelProblem($response, 409, 'panel_name_conflict');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'tab and newline only' => ["\t\n"];
        yield '101 characters' => [str_repeat('x', 101)];
        yield '101 code points' => [str_repeat('щ', 101)];
        yield 'leading NBSP' => ["\u{00A0}Main"];
        yield 'trailing NBSP' => ["Main\u{00A0}"];
        yield 'leading em space' => ["\u{2003}Main"];
        yield 'trailing ideographic space' => ["Main\u{3000}"];
        yield 'control character' => ["Ma\u{0007}in"];
    }

    #[DataProvider('invalidNames')]
    public function testCreatePanelInvalidNameError(string $name): void
    {
        $user = $this->createVerifiedUser();

        $response = $this->panelApi('POST', '/panels', $user, ['name' => $name]);

        self::assertSame('name', $this->violations($response)[0]['propertyPath']);
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM panel'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedBodies(): iterable
    {
        yield 'name is a number' => [['name' => 5]];
        yield 'name is null' => [['name' => null]];
        yield 'name is an array' => [['name' => ['a']]];
        yield 'name is missing' => [[]];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('malformedBodies')]
    public function testCreatePanelMalformedBodyError(array $body): void
    {
        $user = $this->createVerifiedUser();

        $response = $this->panelApi('POST', '/panels', $user, $body);

        self::assertSame(422, $response->getStatusCode());
        self::assertContains('name', $this->violationPaths($response));
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM panel'));
    }

    public function testCreatePanelMalformedJsonError(): void
    {
        $user = $this->createVerifiedUser();

        self::assertSame(400, $this->rawPanelApi('POST', '/panels', $user, '{"name": ')->getStatusCode());
        self::assertSame(400, $this->rawPanelApi('POST', '/panels', $user, '')->getStatusCode());
        self::assertSame(400, $this->rawPanelApi('POST', '/panels', $user, '"just a string"')->getStatusCode());
        self::assertSame(422, $this->rawPanelApi('POST', '/panels', $user, '[]')->getStatusCode());
    }

    public function testCreatePanelUnsupportedContentTypeError(): void
    {
        $user = $this->createVerifiedUser();

        $response = $this->rawPanelApi('POST', '/panels', $user, 'name=Main', 'application/x-www-form-urlencoded');

        self::assertSame(415, $response->getStatusCode());
    }
}
