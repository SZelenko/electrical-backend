<?php

declare(strict_types=1);

namespace App\Panel;

/** Domain-level 422; rendered as RFC 7807 with a `violations` list by PanelProblemListener. */
final class PanelValidationException extends \RuntimeException
{
    /** @param non-empty-list<Violation> $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Validation failed.');
    }
}
