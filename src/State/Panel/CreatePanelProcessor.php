<?php

declare(strict_types=1);

namespace App\State\Panel;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Panel\CreatePanelInput;
use App\ApiResource\Panel\PanelResource;
use App\Panel\PanelService;

/** @implements ProcessorInterface<CreatePanelInput, PanelResource> */
final readonly class CreatePanelProcessor implements ProcessorInterface
{
    public function __construct(
        private PanelRequest $request,
        private PanelService $panels,
        private PanelResourceFactory $resources,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PanelResource
    {
        return $this->resources->create($this->panels->create($this->request->owner(), $data->name));
    }
}
