<?php

declare(strict_types=1);

namespace App\Tests\Functional\Panel;

use App\Tests\Support\PanelContentBuilder as B;
use App\Tests\Support\PanelShapes;

/** Soft delete (`DELETE /panels/{id}`), restore and permanent delete. */
/** @phpstan-import-type Content from PanelShapes */
final class TrashPanelTest extends PanelApiTestCase
{
    public function testSoftDeleteSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user, 'To trash');
        $this->saveContent($user, $panel['id'], B::sample());

        $response = $this->panelApi('DELETE', '/panels/' . $panel['id'], $user);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent(false));
        $stored = $this->panelRow($panel['id']);
        self::assertNotNull($stored?->deletedAt);
        self::assertSame(3, $stored->version, 'Create 1, save 2, trash 3.');
        self::assertSame(3, $this->countRows('panel_wire', $panel['id']), 'The content is kept in the trash.');
        self::assertSame([], $this->listBody($this->panelApi('GET', '/panels', $user))['member']);
        self::assertSame([$panel['id']], array_column($this->listBody($this->panelApi('GET', '/panels/trash', $user))['member'], 'id'));
    }

    public function testSoftDeleteFreesTheNameSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user, 'Name');
        $this->panelApi('DELETE', '/panels/' . $panel['id'], $user);

        self::assertSame(201, $this->panelApi('POST', '/panels', $user, ['name' => 'Name'])->getStatusCode());
    }

    public function testSoftDeleteTrashedPanelError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->panelApi('DELETE', '/panels/' . $panel['id'], $user);

        $response = $this->panelApi('DELETE', '/panels/' . $panel['id'], $user);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(2, $this->panelRow($panel['id'])?->version, 'No second bump.');
    }

    public function testSoftDeleteUnauthorizedError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        self::assertSame(401, $this->panelApi('DELETE', '/panels/' . $panel['id'], null)->getStatusCode());
        self::assertNull($this->panelRow($panel['id'])?->deletedAt);
    }

    public function testSoftDeleteForeignPanelError(): void
    {
        $panel = $this->createPanel($this->createVerifiedUser());

        $response = $this->panelApi('DELETE', '/panels/' . $panel['id'], $this->createVerifiedUser());

        self::assertSame(404, $response->getStatusCode());
        self::assertNull($this->panelRow($panel['id'])?->deletedAt);
    }

    public function testSoftDeleteMissingPanelError(): void
    {
        $user = $this->createVerifiedUser();

        self::assertSame(404, $this->panelApi('DELETE', '/panels/0198a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b', $user)->getStatusCode());
    }

    public function testRestoreSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user, 'Come back');
        $this->saveContent($user, $panel['id'], B::sample());
        $this->panelApi('DELETE', '/panels/' . $panel['id'], $user);

        $response = $this->panelApi('POST', '/panels/' . $panel['id'] . '/restore', $user);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->panelBody($response);
        self::assertSame($panel['id'], $data['id']);
        self::assertSame('Come back', $data['name']);
        self::assertNull($data['deletedAt'] ?? null);
        self::assertSame(4, $data['version'], 'Create 1, save 2, trash 3, restore 4.');
        self::assertCount(3, $data['content']['wires'], 'The content survived the trash.');
        self::assertNull($this->panelRow($panel['id'])?->deletedAt);
        self::assertSame([$panel['id']], array_column($this->listBody($this->panelApi('GET', '/panels', $user))['member'], 'id'));
        self::assertSame([], $this->listBody($this->panelApi('GET', '/panels/trash', $user))['member']);
    }

    public function testRestoreAfterRenameSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $first = $this->createPanel($user, 'Name');
        $this->panelApi('DELETE', '/panels/' . $first['id'], $user);
        $this->createPanel($user, 'name');
        $this->panelApi('PATCH', '/panels/' . $first['id'], $user, ['name' => 'Other name']);

        $response = $this->panelApi('POST', '/panels/' . $first['id'] . '/restore', $user);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Other name', $this->json($response)['name']);
    }

    public function testRestoreNameConflictError(): void
    {
        $user = $this->createVerifiedUser();
        $first = $this->createPanel($user, 'Name');
        $this->panelApi('DELETE', '/panels/' . $first['id'], $user);
        $this->createPanel($user, 'NAME');

        $response = $this->panelApi('POST', '/panels/' . $first['id'] . '/restore', $user);

        $problem = $this->assertPanelProblem($response, 409, 'panel_name_conflict');
        self::assertSame(2, $problem['currentVersion'] ?? null);
        self::assertNotNull($this->panelRow($first['id'])?->deletedAt, 'Still in the trash.');
    }

    public function testRestoreActivePanelError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        $response = $this->panelApi('POST', '/panels/' . $panel['id'] . '/restore', $user);

        $problem = $this->assertPanelProblem($response, 409, 'panel_not_in_trash');
        self::assertSame(1, $problem['currentVersion'] ?? null);
        self::assertSame(1, $this->panelRow($panel['id'])?->version);
    }

    public function testRestoreUnauthorizedError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->panelApi('DELETE', '/panels/' . $panel['id'], $user);

        self::assertSame(401, $this->panelApi('POST', '/panels/' . $panel['id'] . '/restore', null)->getStatusCode());
    }

    public function testRestoreForeignTrashedPanelIs404Not409Error(): void
    {
        $owner = $this->createVerifiedUser();
        $panel = $this->createPanel($owner, 'Name');
        $this->panelApi('DELETE', '/panels/' . $panel['id'], $owner);
        $intruder = $this->createVerifiedUser();
        // Even with a name conflict inside the intruder's own space, ownership is checked first.
        $this->createPanel($intruder, 'Name');

        $response = $this->panelApi('POST', '/panels/' . $panel['id'] . '/restore', $intruder);

        self::assertSame(404, $response->getStatusCode());
        self::assertNotNull($this->panelRow($panel['id'])?->deletedAt);
    }

    public function testRestoreForeignActivePanelIs404Not409Error(): void
    {
        $owner = $this->createVerifiedUser();
        $panel = $this->createPanel($owner);

        $response = $this->panelApi('POST', '/panels/' . $panel['id'] . '/restore', $this->createVerifiedUser());

        self::assertSame(404, $response->getStatusCode());
    }

    public function testRestoreMissingPanelError(): void
    {
        $user = $this->createVerifiedUser();

        self::assertSame(404, $this->panelApi('POST', '/panels/0198a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b/restore', $user)->getStatusCode());
    }

    public function testPermanentDeleteSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->saveContent($user, $panel['id'], B::sample());
        $other = $this->createPanel($user, 'Survivor');
        $this->saveContent($user, $other['id'], $this->otherContent());
        $this->panelApi('DELETE', '/panels/' . $panel['id'], $user);

        $response = $this->panelApi('DELETE', '/panels/' . $panel['id'] . '/permanent', $user);

        self::assertSame(204, $response->getStatusCode());
        self::assertFalse($this->panelExists($panel['id']));
        self::assertSame(404, $this->panelApi('GET', '/panels/' . $panel['id'], $user)->getStatusCode());
        foreach (['cable', 'din_rail', 'panel_wire'] as $table) {
            self::assertSame(0, $this->countRows($table, $panel['id']), "$table rows cascade away.");
        }
        self::assertSame(0, $this->sqlInt("SELECT COUNT(*) FROM element e JOIN din_rail r ON r.id = e.rail_id WHERE r.panel_id = ?", [$panel['id']]));
        self::assertSame([], $this->listBody($this->panelApi('GET', '/panels/trash', $user))['member']);
        // The other panel and its content are untouched.
        self::assertTrue($this->panelExists($other['id']));
        self::assertSame(3, $this->countRows('panel_wire', $other['id']));
    }

    public function testPermanentDeleteFreesContentIdsSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $first = $this->createPanel($user, 'First');
        $this->saveContent($user, $first['id'], B::sample());
        $this->panelApi('DELETE', '/panels/' . $first['id'], $user);
        $this->panelApi('DELETE', '/panels/' . $first['id'] . '/permanent', $user);
        $second = $this->createPanel($user, 'Second');

        $response = $this->putContent($user, $second['id'], B::sample());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testPermanentDeleteActivePanelError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        $response = $this->panelApi('DELETE', '/panels/' . $panel['id'] . '/permanent', $user);

        $problem = $this->assertPanelProblem($response, 409, 'panel_not_in_trash');
        self::assertSame(1, $problem['currentVersion'] ?? null);
        self::assertTrue($this->panelExists($panel['id']));
    }

    public function testPermanentDeleteTwiceError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->panelApi('DELETE', '/panels/' . $panel['id'], $user);
        self::assertSame(204, $this->panelApi('DELETE', '/panels/' . $panel['id'] . '/permanent', $user)->getStatusCode());

        self::assertSame(404, $this->panelApi('DELETE', '/panels/' . $panel['id'] . '/permanent', $user)->getStatusCode());
    }

    public function testPermanentDeleteUnauthorizedError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->panelApi('DELETE', '/panels/' . $panel['id'], $user);

        self::assertSame(401, $this->panelApi('DELETE', '/panels/' . $panel['id'] . '/permanent', null)->getStatusCode());
        self::assertTrue($this->panelExists($panel['id']));
    }

    public function testPermanentDeleteForeignTrashedPanelError(): void
    {
        $owner = $this->createVerifiedUser();
        $panel = $this->createPanel($owner);
        $this->panelApi('DELETE', '/panels/' . $panel['id'], $owner);

        $response = $this->panelApi('DELETE', '/panels/' . $panel['id'] . '/permanent', $this->createVerifiedUser());

        self::assertSame(404, $response->getStatusCode());
        self::assertTrue($this->panelExists($panel['id']));
    }

    public function testPermanentDeleteForeignActivePanelIs404Not409Error(): void
    {
        $owner = $this->createVerifiedUser();
        $panel = $this->createPanel($owner);

        $response = $this->panelApi('DELETE', '/panels/' . $panel['id'] . '/permanent', $this->createVerifiedUser());

        self::assertSame(404, $response->getStatusCode());
        self::assertTrue($this->panelExists($panel['id']));
    }

    public function testPermanentDeleteMissingPanelError(): void
    {
        $user = $this->createVerifiedUser();

        self::assertSame(404, $this->panelApi('DELETE', '/panels/0198a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b/permanent', $user)->getStatusCode());
    }

    /**
     * Another valid content with ids that do not collide with B::sample().
     *
     * @return Content
     */
    private function otherContent(): array
    {
        $c = B::sample();
        $json = json_encode($c, JSON_THROW_ON_ERROR);
        $json = (string) preg_replace('/00000000-0000-4000-8000-0000000000(\d\d)/', '00000000-0000-4000-8000-0000000001$1', $json);

        /** @var Content */
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
