<?php

declare(strict_types=1);

namespace App\Tests\Functional\Panel;

use PHPUnit\Framework\Attributes\DataProvider;

final class RenamePanelTest extends PanelApiTestCase
{
    public function testRenamePanelSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user, 'Old');
        $this->advanceClock(30);

        $response = $this->panelApi('PATCH', '/panels/' . $panel['id'], $user, ['name' => '  New name ']);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->panelBody($response);
        self::assertSame('New name', $data['name']);
        self::assertSame(2, $data['version']);
        self::assertNotSame($panel['updatedAt'], $data['updatedAt']);
        self::assertSame($panel['createdAt'], $data['createdAt']);
        self::assertEquals($panel['content'], $data['content'], 'Rename leaves the content untouched.');

        $stored = $this->panelRow($panel['id']);
        self::assertSame('New name', $stored?->name);
        self::assertSame('new name', $stored->nameKey);
        self::assertSame(2, $stored->version);
    }

    public function testRenameToSameNameInOtherCaseSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user, 'main');

        $response = $this->panelApi('PATCH', '/panels/' . $panel['id'], $user, ['name' => 'MAIN']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('MAIN', $this->json($response)['name']);
    }

    public function testRenameToSameNameSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user, 'Same');

        $response = $this->panelApi('PATCH', '/panels/' . $panel['id'], $user, ['name' => 'Same']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $this->json($response)['version']);
    }

    public function testRenameToNameOfOtherUsersPanelSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $this->createPanel($this->createVerifiedUser(), 'Taken elsewhere');
        $panel = $this->createPanel($user, 'Mine');

        $response = $this->panelApi('PATCH', '/panels/' . $panel['id'], $user, ['name' => 'Taken elsewhere']);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRenameTrashedPanelWithoutUniquenessCheckSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $trashed = $this->createPanel($user, 'Old');
        $this->panelApi('DELETE', '/panels/' . $trashed['id'], $user);
        $this->createPanel($user, 'Active');

        // The new name collides with an active panel, but a trashed panel is not part of the uniqueness set.
        $response = $this->panelApi('PATCH', '/panels/' . $trashed['id'], $user, ['name' => 'active']);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->panelBody($response);
        self::assertSame('active', $data['name']);
        self::assertNotNull($data['deletedAt'] ?? null, 'Still trashed.');
        self::assertSame(3, $data['version'], 'Create 1, trash 2, rename 3.');

        // ... and restoring it then hits the name conflict.
        $restore = $this->panelApi('POST', '/panels/' . $trashed['id'] . '/restore', $user);
        $this->assertPanelProblem($restore, 409, 'panel_name_conflict');
    }

    public function testRenameDuplicateNameError(): void
    {
        $user = $this->createVerifiedUser();
        $this->createPanel($user, 'Taken');
        $panel = $this->createPanel($user, 'Other');

        $response = $this->panelApi('PATCH', '/panels/' . $panel['id'], $user, ['name' => ' TAKEN ']);

        $problem = $this->assertPanelProblem($response, 409, 'panel_name_conflict');
        self::assertSame(1, $problem['currentVersion'] ?? null);
        $stored = $this->panelRow($panel['id']);
        self::assertSame('Other', $stored?->name);
        self::assertSame(1, $stored->version);
    }

    public function testRenameToNfcEquivalentOfOtherPanelError(): void
    {
        $user = $this->createVerifiedUser();
        $this->createPanel($user, "Caf\u{00E9}");
        $panel = $this->createPanel($user, 'Other');

        $response = $this->panelApi('PATCH', '/panels/' . $panel['id'], $user, ['name' => "Cafe\u{0301}"]);

        $this->assertPanelProblem($response, 409, 'panel_name_conflict');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['  '];
        yield '101 characters' => [str_repeat('x', 101)];
        yield 'leading NBSP' => ["\u{00A0}x"];
        yield 'trailing NBSP' => ["x\u{00A0}"];
    }

    #[DataProvider('invalidNames')]
    public function testRenameInvalidNameError(string $name): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user, 'Keep');

        $response = $this->panelApi('PATCH', '/panels/' . $panel['id'], $user, ['name' => $name]);

        self::assertSame('name', $this->violations($response)[0]['propertyPath']);
        $stored = $this->panelRow($panel['id']);
        self::assertSame('Keep', $stored?->name);
        self::assertSame(1, $stored->version);
    }

    public function testRenameMalformedBodyError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        foreach ([[], ['name' => 7], ['name' => null]] as $body) {
            $response = $this->panelApi('PATCH', '/panels/' . $panel['id'], $user, $body);
            self::assertSame(422, $response->getStatusCode());
            self::assertContains('name', $this->violationPaths($response));
        }
        self::assertSame(400, $this->rawPanelApi('PATCH', '/panels/' . $panel['id'], $user, '{"name":', self::MERGE_PATCH)->getStatusCode());
    }

    public function testRenameWrongContentTypeError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        $response = $this->rawPanelApi('PATCH', '/panels/' . $panel['id'], $user, '{"name":"x"}', 'application/json');

        self::assertSame(415, $response->getStatusCode());
    }

    public function testRenameUnauthorizedError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        self::assertSame(401, $this->panelApi('PATCH', '/panels/' . $panel['id'], null, ['name' => 'x'])->getStatusCode());
    }

    public function testRenameForeignPanelError(): void
    {
        $owner = $this->createVerifiedUser();
        $panel = $this->createPanel($owner, 'Owned');

        $response = $this->panelApi('PATCH', '/panels/' . $panel['id'], $this->createVerifiedUser(), ['name' => 'Hijacked']);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Owned', $this->panelRow($panel['id'])?->name);
    }

    public function testRenameForeignPanelWithTakenNameIs404Not409Error(): void
    {
        $owner = $this->createVerifiedUser();
        $panel = $this->createPanel($owner, 'Owned');
        $intruder = $this->createVerifiedUser();
        $this->createPanel($intruder, 'Hijacked');

        $response = $this->panelApi('PATCH', '/panels/' . $panel['id'], $intruder, ['name' => 'Hijacked']);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testRenameMissingPanelError(): void
    {
        $user = $this->createVerifiedUser();

        $response = $this->panelApi('PATCH', '/panels/0198a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b', $user, ['name' => 'x']);

        self::assertSame(404, $response->getStatusCode());
    }
}
