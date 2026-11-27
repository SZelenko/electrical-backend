<?php

declare(strict_types=1);

namespace App\Tests\Functional\Panel;

use App\Entity\User;
use App\Tests\Support\PanelContentBuilder;

final class ListPanelsTest extends PanelApiTestCase
{
    public function testListOwnActivePanelsSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $other = $this->createVerifiedUser();
        $mine = $this->createPanel($user, 'Mine');
        $trashed = $this->createPanel($user, 'Trashed');
        $this->createPanel($other, 'Foreign');
        $this->panelApi('DELETE', '/panels/' . $trashed['id'], $user);

        $response = $this->panelApi('GET', '/panels', $user);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->listBody($response);
        self::assertSame('Collection', $this->json($response)['@type']);
        self::assertSame(1, $data['totalItems']);
        self::assertCount(1, $data['member']);
        $item = $data['member'][0];
        self::assertSame($mine['id'], $item['id']);
        self::assertSame('Mine', $item['name']);
        self::assertSame(1, $item['version']);
        self::assertArrayHasKey('createdAt', $item);
        self::assertArrayHasKey('updatedAt', $item);
        self::assertNull($item['deletedAt'] ?? null);
        self::assertArrayNotHasKey('content', $item, 'The list is lean: no content.');
        self::assertArrayNotHasKey('shortCircuits', $item);
    }

    public function testListIsEmptyForNewUserSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $this->createPanel($this->createVerifiedUser(), 'Foreign');

        $data = $this->listBody($this->panelApi('GET', '/panels', $user));

        self::assertSame(0, $data['totalItems']);
        self::assertSame([], $data['member']);
    }

    public function testListOrderedByUpdatedAtDescendingSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $a = $this->createPanel($user, 'A');
        $this->advanceClock(10);
        $b = $this->createPanel($user, 'B');
        $this->advanceClock(10);
        $c = $this->createPanel($user, 'C');
        self::assertSame([$c['id'], $b['id'], $a['id']], $this->listedIds('/panels', $user));

        // Saving content touches updatedAt: A jumps to the top.
        $this->advanceClock(10);
        $this->saveContent($user, $a['id'], PanelContentBuilder::minimal());
        self::assertSame([$a['id'], $c['id'], $b['id']], $this->listedIds('/panels', $user));

        // A rename touches it too.
        $this->advanceClock(10);
        $this->panelApi('PATCH', '/panels/' . $b['id'], $user, ['name' => 'B2']);
        self::assertSame([$b['id'], $a['id'], $c['id']], $this->listedIds('/panels', $user));
    }

    public function testListTieBreaksByIdDescendingSuccess(): void
    {
        $user = $this->createVerifiedUser();
        // Same second (the clock is frozen): ordered by id descending.
        $ids = [$this->createPanel($user, 'A')['id'], $this->createPanel($user, 'B')['id'], $this->createPanel($user, 'C')['id']];
        rsort($ids);

        self::assertSame($ids, $this->listedIds('/panels', $user));
    }

    public function testListPaginationSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $ids = [];
        for ($i = 1; $i <= 5; ++$i) {
            $ids[] = $this->createPanel($user, "Panel $i")['id'];
            $this->advanceClock(5);
        }
        $ids = array_reverse($ids); // newest first

        $page1 = $this->listBody($this->panelApi('GET', '/panels?itemsPerPage=2&page=1', $user));
        $page2 = $this->listBody($this->panelApi('GET', '/panels?itemsPerPage=2&page=2', $user));
        $page3 = $this->listBody($this->panelApi('GET', '/panels?itemsPerPage=2&page=3', $user));
        $page4 = $this->listBody($this->panelApi('GET', '/panels?itemsPerPage=2&page=4', $user));

        foreach ([$page1, $page2, $page3, $page4] as $page) {
            self::assertSame(5, $page['totalItems']);
        }
        self::assertSame(\array_slice($ids, 0, 2), array_column($page1['member'], 'id'));
        self::assertSame(\array_slice($ids, 2, 2), array_column($page2['member'], 'id'));
        self::assertSame(\array_slice($ids, 4, 2), array_column($page3['member'], 'id'));
        self::assertSame([], $page4['member']);
        self::assertArrayHasKey('view', $page1);
    }

    public function testListDefaultPageSizeIs30Success(): void
    {
        $user = $this->createVerifiedUser();
        for ($i = 1; $i <= 31; ++$i) {
            $this->createPanel($user, "Panel $i");
        }

        $data = $this->listBody($this->panelApi('GET', '/panels', $user));

        self::assertSame(31, $data['totalItems']);
        self::assertCount(30, $data['member']);
    }

    public function testListUnauthorizedError(): void
    {
        $response = $this->panelApi('GET', '/panels', null);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('token_invalid', $this->json($response)['type']);
    }

    public function testListPageSizeIsCappedAt100Success(): void
    {
        $user = $this->createVerifiedUser();
        for ($i = 1; $i <= 101; ++$i) {
            $this->createPanel($user, "Panel $i");
        }

        $data = $this->listBody($this->panelApi('GET', '/panels?itemsPerPage=1000', $user));

        self::assertSame(101, $data['totalItems']);
        self::assertCount(100, $data['member']);
    }

    public function testTrashListSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $other = $this->createVerifiedUser();
        $active = $this->createPanel($user, 'Active');
        $a = $this->createPanel($user, 'A');
        $b = $this->createPanel($user, 'B');
        $foreign = $this->createPanel($other, 'Foreign');
        $this->panelApi('DELETE', '/panels/' . $a['id'], $user);
        $this->advanceClock(10);
        $this->panelApi('DELETE', '/panels/' . $b['id'], $user);
        $this->panelApi('DELETE', '/panels/' . $foreign['id'], $other);

        $response = $this->panelApi('GET', '/panels/trash', $user);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->listBody($response);
        self::assertSame(2, $data['totalItems']);
        self::assertSame([$b['id'], $a['id']], array_column($data['member'], 'id'), 'Most recently deleted first.');
        self::assertArrayHasKey('deletedAt', $data['member'][0]);
        self::assertNotContains($active['id'], array_column($data['member'], 'id'));
        self::assertSame([$active['id']], $this->listedIds('/panels', $user));
    }

    public function testTrashListTieBreaksByIdDescendingSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $ids = [];
        foreach (['A', 'B', 'C'] as $name) {
            $id = $this->createPanel($user, $name)['id'];
            $this->panelApi('DELETE', '/panels/' . $id, $user);
            $ids[] = $id;
        }
        rsort($ids);

        self::assertSame($ids, $this->listedIds('/panels/trash', $user));
    }

    public function testTrashListPaginationSuccess(): void
    {
        $user = $this->createVerifiedUser();
        for ($i = 1; $i <= 3; ++$i) {
            $id = $this->createPanel($user, "Panel $i")['id'];
            $this->panelApi('DELETE', '/panels/' . $id, $user);
            $this->advanceClock(5);
        }

        $page2 = $this->listBody($this->panelApi('GET', '/panels/trash?itemsPerPage=2&page=2', $user));

        self::assertSame(3, $page2['totalItems']);
        self::assertCount(1, $page2['member']);
    }

    public function testTrashListEmptySuccess(): void
    {
        $user = $this->createVerifiedUser();
        $this->createPanel($user, 'Active');

        $data = $this->listBody($this->panelApi('GET', '/panels/trash', $user));

        self::assertSame(0, $data['totalItems']);
        self::assertSame([], $data['member']);
    }

    public function testTrashListUnauthorizedError(): void
    {
        self::assertSame(401, $this->panelApi('GET', '/panels/trash', null)->getStatusCode());
    }

    /** @return list<string> */
    private function listedIds(string $path, User $user): array
    {
        $data = $this->listBody($this->panelApi('GET', $path, $user));

        return array_column($data['member'], 'id');
    }
}
