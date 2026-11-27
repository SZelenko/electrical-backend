<?php

declare(strict_types=1);

namespace App\Panel;

/**
 * Domain exception for every panel conflict in the catalogue (see PanelError). Rendered as RFC 7807
 * by PanelProblemListener; every 409 of a locked panel carries the current version of the stored panel.
 */
final class PanelException extends \RuntimeException
{
    public function __construct(
        public readonly PanelError $error,
        public readonly ?int $currentVersion = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($error->title(), 0, $previous);
    }
}
