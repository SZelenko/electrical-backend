<?php

declare(strict_types=1);

namespace App\ApiResource\Panel;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Panel\Content\PanelContent;
use App\Panel\Content\ShortCircuitGroup;
use App\State\Panel\CreatePanelProcessor;
use App\State\Panel\DeletePermanentlyProcessor;
use App\State\Panel\MoveToTrashProcessor;
use App\State\Panel\PanelCollectionProvider;
use App\State\Panel\PanelProvider;
use App\State\Panel\RenamePanelProcessor;
use App\State\Panel\RestorePanelProcessor;
use App\State\Panel\SavePanelContentProcessor;

/**
 * A panel with its whole content tree; the response of every panel operation except the lists
 * (which return PanelListItem) and the deletes. All operations are owner-scoped: a foreign panel
 * is 404. JSON-LD is the default format so that collections carry `totalItems`.
 */
#[ApiResource(
    shortName: 'Panel',
    formats: ['jsonld', 'json'],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/panels',
            output: PanelListItem::class,
            provider: PanelCollectionProvider::class,
            paginationItemsPerPage: 30,
            paginationMaximumItemsPerPage: 100,
            paginationClientItemsPerPage: true,
            openapi: new OpenApiOperation(
                tags: ['Panels'],
                summary: 'Lists the current user\'s active panels, most recently updated first.',
            ),
        ),
        new GetCollection(
            uriTemplate: '/panels/trash',
            output: PanelListItem::class,
            provider: PanelCollectionProvider::class,
            paginationItemsPerPage: 30,
            paginationMaximumItemsPerPage: 100,
            paginationClientItemsPerPage: true,
            extraProperties: ['trashed' => true],
            openapi: new OpenApiOperation(
                tags: ['Panels'],
                summary: 'Lists the current user\'s trashed panels, most recently deleted first.',
            ),
        ),
        new Post(
            uriTemplate: '/panels',
            status: 201,
            inputFormats: ['json' => ['application/json'], 'jsonld' => ['application/ld+json']],
            input: CreatePanelInput::class,
            collectDenormalizationErrors: true,
            read: false,
            processor: CreatePanelProcessor::class,
            openapi: new OpenApiOperation(
                tags: ['Panels'],
                summary: 'Creates a panel with one default input cable (3 wires, 230 V, 50 Hz, 4 mm²).',
            ),
        ),
        new Get(
            uriTemplate: '/panels/{id}',
            requirements: ['id' => PanelResource::ID_PATTERN],
            provider: PanelProvider::class,
            openapi: new OpenApiOperation(
                tags: ['Panels'],
                summary: 'Returns an active or trashed panel with its content and short-circuit warnings.',
            ),
        ),
        new Patch(
            uriTemplate: '/panels/{id}',
            requirements: ['id' => PanelResource::ID_PATTERN],
            inputFormats: ['json' => ['application/merge-patch+json']],
            input: RenamePanelInput::class,
            collectDenormalizationErrors: true,
            read: false,
            processor: RenamePanelProcessor::class,
            openapi: new OpenApiOperation(tags: ['Panels'], summary: 'Renames an active or trashed panel.'),
        ),
        new Put(
            uriTemplate: '/panels/{id}/content',
            requirements: ['id' => PanelResource::ID_PATTERN],
            inputFormats: ['json' => ['application/json'], 'jsonld' => ['application/ld+json']],
            input: SavePanelContentInput::class,
            collectDenormalizationErrors: true,
            read: false,
            processor: SavePanelContentProcessor::class,
            openapi: new OpenApiOperation(
                tags: ['Panels'],
                summary: 'Replaces the whole content of an active panel; a stale version gets 409.',
            ),
        ),
        new Delete(
            uriTemplate: '/panels/{id}',
            requirements: ['id' => PanelResource::ID_PATTERN],
            status: 204,
            output: false,
            read: false,
            deserialize: false,
            processor: MoveToTrashProcessor::class,
            openapi: new OpenApiOperation(tags: ['Panels'], summary: 'Moves an active panel to the trash.'),
        ),
        new Post(
            uriTemplate: '/panels/{id}/restore',
            requirements: ['id' => PanelResource::ID_PATTERN],
            status: 200,
            input: false,
            read: false,
            deserialize: false,
            processor: RestorePanelProcessor::class,
            openapi: new OpenApiOperation(
                tags: ['Panels'],
                summary: 'Restores a trashed panel; 409 if its name is taken by an active panel.',
            ),
        ),
        new Delete(
            uriTemplate: '/panels/{id}/permanent',
            requirements: ['id' => PanelResource::ID_PATTERN],
            status: 204,
            output: false,
            read: false,
            deserialize: false,
            processor: DeletePermanentlyProcessor::class,
            openapi: new OpenApiOperation(tags: ['Panels'], summary: 'Permanently deletes a trashed panel.'),
        ),
    ],
)]
final readonly class PanelResource
{
    public const string ID_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}';

    /** @param list<ShortCircuitGroup> $shortCircuits warnings only; a panel with short circuits is still saved */
    public function __construct(
        #[ApiProperty(identifier: true, required: true)]
        public string $id,
        #[ApiProperty(required: true)]
        public string $name,
        #[ApiProperty(required: true)]
        public int $version,
        #[ApiProperty(required: true)]
        public \DateTimeImmutable $createdAt,
        #[ApiProperty(required: true)]
        public \DateTimeImmutable $updatedAt,
        #[ApiProperty(required: true)]
        public ?\DateTimeImmutable $deletedAt,
        #[ApiProperty(genId: false, required: true)]
        public PanelContent $content,
        #[ApiProperty(genId: false, required: true)]
        public array $shortCircuits,
    ) {}
}
