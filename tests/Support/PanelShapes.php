<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * PHPStan array-shape aliases of the panel JSON (the request body of `PUT /panels/{id}/content`
 * and the `content` of every panel response). Imported with `@phpstan-import-type`; no code here.
 *
 * @phpstan-type CableWire array{id: string, colour: string}
 * @phpstan-type InputCable array{id: string, voltage: float|int, frequency: float|int, crossSection: float|int, wires: list<CableWire>}
 * @phpstan-type OutputCable array{id: string, description: string, wires: list<CableWire>}
 * @phpstan-type Pole array{transit: bool}
 * @phpstan-type Element array{kind: string, id: string, offsetModules: int, widthModules: int, label: string, marking: string, sidesSwapped: bool, on: bool|null, poles: list<Pole>|null, state: string|null, style: string|null}
 * @phpstan-type Block array{id: string, offsetModules: int, widthModules: int, terminalCount: int, colour: string|null, label: string}
 * @phpstan-type Rail array{id: string, widthModules: int, spacingMm: float|int, elements: list<Element>, terminalBlocks: list<Block>}
 * @phpstan-type Endpoint array{kind: string, elementId?: string, poleIndex?: int, side?: string, terminalBlockId?: string, terminalIndex?: int, cableWireId?: string, contactSide?: string|null}
 * @phpstan-type Bend array{x: float|int, y: float|int}
 * @phpstan-type Wire array{id: string, colour: string, crossSection: string, start: Endpoint, end: Endpoint, bends: list<Bend>}
 * @phpstan-type Content array{inputCables: list<InputCable>, outputCables: list<OutputCable>, terminalRow: list<Block>, rails: list<Rail>, wires: list<Wire>}
 * @phpstan-type Group array{sources: list<string>, wireIds: list<string>, terminals: list<Endpoint>}
 * @phpstan-type PanelBody array{id: string, name: string, version: int, createdAt: string, updatedAt: string, deletedAt?: string|null, content: Content, shortCircuits: list<Group>}
 * @phpstan-type ListItem array{id: string, name: string, version: int, createdAt: string, updatedAt: string, deletedAt?: string|null}
 * @phpstan-type ListBody array{totalItems: int, member: list<array{id: string, name: string, version: int, createdAt: string, updatedAt: string, deletedAt?: string|null}>, view?: array<string, mixed>}
 * @phpstan-type Violation array{propertyPath: string, message: string, id?: string}
 */
final class PanelShapes
{
    private function __construct() {}
}
