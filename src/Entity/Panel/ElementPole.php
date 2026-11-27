<?php

declare(strict_types=1);

namespace App\Entity\Panel;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One pole of an element (an input and an output terminal); identified by (element, pole index). */
#[ORM\Entity]
#[ORM\Table(name: 'element_pole')]
class ElementPole
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Element::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    public private(set) Element $element;

    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT)]
    public private(set) int $poleIndex;

    /** A transit pole conducts regardless of the element's on/off state. */
    #[ORM\Column]
    public private(set) bool $isTransit;

    public function __construct(Element $element, int $poleIndex, bool $isTransit)
    {
        $this->element = $element;
        $this->poleIndex = $poleIndex;
        $this->isTransit = $isTransit;
    }
}
