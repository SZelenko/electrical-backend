<?php

declare(strict_types=1);

namespace App\State\Panel;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Panel\PanelListItem;
use App\Panel\PanelSummary;
use App\Repository\PanelRepository;

/**
 * One paginated query (plus a count) over the owner's panels: active ones, or the trash when the
 * operation has the `trashed` extra property.
 *
 * @implements ProviderInterface<PanelListItem>
 */
final readonly class PanelCollectionProvider implements ProviderInterface
{
    public function __construct(
        private PanelRequest $request,
        private PanelRepository $panels,
        private Pagination $pagination,
    ) {}

    /** @return TraversablePaginator<PanelListItem> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $owner = $this->request->owner();
        $trashed = true === ($operation->getExtraProperties()['trashed'] ?? false);

        $page = $this->pagination->getPage($context);
        $limit = $this->pagination->getLimit($operation, $context);
        $offset = $this->pagination->getOffset($operation, $context);

        $items = array_map(
            PanelListItem::fromSummary(...),
            $this->panels->findSummaries($owner, $trashed, $offset, $limit),
        );

        return new TraversablePaginator(
            new \ArrayIterator($items),
            $page,
            $limit,
            $this->panels->countSummaries($owner, $trashed),
        );
    }
}
