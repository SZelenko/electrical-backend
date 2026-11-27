<?php

declare(strict_types=1);

namespace App\State\Panel;

use App\Auth\AuthError;
use App\Auth\AuthException;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/** What every panel provider/processor needs from the request: the current owner and the panel id. */
final readonly class PanelRequest
{
    public function __construct(private Security $security) {}

    public function owner(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AuthException(AuthError::TokenInvalid);
        }

        return $user;
    }

    /** @param array<string, mixed> $uriVariables */
    public function panelId(array $uriVariables): Uuid
    {
        $id = $uriVariables['id'] ?? null;

        return \is_string($id) && Uuid::isValid($id) ? Uuid::fromString($id) : throw new NotFoundHttpException('Panel not found.');
    }
}
