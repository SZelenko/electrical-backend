<?php

declare(strict_types=1);

namespace App\State\Panel;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Panel\PanelResource;
use App\ApiResource\Panel\SavePanelContentInput;
use App\Panel\PanelRateLimiter;
use App\Panel\PanelService;

/** @implements ProcessorInterface<SavePanelContentInput, PanelResource> */
final readonly class SavePanelContentProcessor implements ProcessorInterface
{
    public function __construct(
        private PanelRequest $request,
        private PanelRateLimiter $limiter,
        private PanelService $panels,
        private PanelResourceFactory $resources,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PanelResource
    {
        $owner = $this->request->owner();
        // Runs after deserialisation (body size is bounded by nginx at 4 MB); it protects the DB work below.
        $this->limiter->checkSave($owner);

        return $this->resources->create(
            $this->panels->saveContent(
                $owner,
                $this->request->panelId($uriVariables),
                $data->version,
                $data->content,
            ),
        );
    }
}
