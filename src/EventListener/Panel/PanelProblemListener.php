<?php

declare(strict_types=1);

namespace App\EventListener\Panel;

use App\Panel\PanelError;
use App\Panel\PanelException;
use App\Panel\PanelValidationException;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Renders panel domain exceptions as RFC 7807 problems (same shape as the auth problems, plus
 * `currentVersion` on version conflicts and `violations` on 422). A stray OptimisticLockException
 * is mapped globally to 409 `panel_version_conflict`. Runs before API Platform's error listener.
 */
final readonly class PanelProblemListener
{
    #[AsEventListener(event: 'kernel.exception', priority: 10)]
    public function onException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if ($throwable instanceof PanelException) {
            $event->setResponse($this->conflict($throwable->error, $throwable->currentVersion));
        } elseif ($throwable instanceof OptimisticLockException) {
            $event->setResponse($this->conflict(PanelError::VersionConflict, null));
        } elseif ($throwable instanceof PanelValidationException) {
            $event->setResponse($this->validation($throwable));
        }
    }

    private function conflict(PanelError $error, ?int $currentVersion): JsonResponse
    {
        $body = [
            'type' => $error->value,
            'title' => $error->title(),
            'status' => $error->status(),
            'detail' => $error->title(),
        ];
        if (null !== $currentVersion) {
            $body['currentVersion'] = $currentVersion;
        }

        return new JsonResponse($body, $error->status(), ['Content-Type' => 'application/problem+json']);
    }

    private function validation(PanelValidationException $exception): JsonResponse
    {
        $violations = [];
        foreach ($exception->violations as $violation) {
            $item = ['propertyPath' => $violation->propertyPath, 'message' => $violation->message];
            if (null !== $violation->id) {
                $item['id'] = $violation->id;
            }
            $violations[] = $item;
        }

        return new JsonResponse(
            [
                'type' => 'validation_error',
                'title' => 'Validation failed.',
                'status' => 422,
                'detail' => 'The request is invalid; see violations.',
                'violations' => $violations,
            ],
            422,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
