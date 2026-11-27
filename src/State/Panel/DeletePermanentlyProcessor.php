<?php

declare(strict_types=1);

namespace App\State\Panel;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Panel\PanelService;

/** @implements ProcessorInterface<mixed, null> */
final readonly class DeletePermanentlyProcessor implements ProcessorInterface
{
    public function __construct(
        private PanelRequest $request,
        private PanelService $panels,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->panels->deletePermanently($this->request->owner(), $this->request->panelId($uriVariables));

        return null;
    }
}
