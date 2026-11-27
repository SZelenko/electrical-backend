<?php

declare(strict_types=1);

namespace App\State\Panel;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Panel\PanelResource;
use App\Panel\PanelRateLimiter;
use App\Panel\PanelService;

/** @implements ProviderInterface<PanelResource> */
final readonly class PanelProvider implements ProviderInterface
{
    public function __construct(
        private PanelRequest $request,
        private PanelRateLimiter $limiter,
        private PanelService $panels,
        private PanelResourceFactory $resources,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PanelResource
    {
        $owner = $this->request->owner();
        $this->limiter->checkRead($owner);

        return $this->resources->create($this->panels->get($owner, $this->request->panelId($uriVariables)));
    }
}
