<?php

declare(strict_types=1);

namespace App\Tests\Functional\Panel;

use ApiPlatform\Test\ApiTestCase;

/** The OpenAPI document describes the stage 3 fields of the panel content. */
final class PanelContentOpenApiTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = false;

    /** @return array<string, array{required: list<string>, properties: array<string, array{type: string|list<string>, enum: list<string|null>, items: array{'$ref': string}}>}> */
    private function schemas(): array
    {
        $response = static::createClient()->request('GET', '/api/v1/docs', [
            'headers' => ['Accept' => 'application/vnd.openapi+json'],
        ]);
        self::assertResponseStatusCodeSame(200);
        /** @var array{components: array{schemas: array<string, array{required: list<string>, properties: array<string, array{type: string|list<string>, enum: list<string|null>, items: array{'$ref': string}}>}>}} $doc */
        $doc = $response->toArray();

        return $doc['components']['schemas'];
    }

    public function testPanelContentSchemaListsTerminalRowSuccess(): void
    {
        $schema = $this->schemas()['PanelContent'];

        self::assertSame(['railWidthModules', 'inputCableSide', 'outputCableSide', 'inputCables', 'outputCables', 'terminalRow', 'bottomTerminalRow', 'rails', 'wires'], $schema['required']);
        self::assertSame('integer', $schema['properties']['railWidthModules']['type']);
        self::assertSame('array', $schema['properties']['terminalRow']['type']);
        self::assertSame('#/components/schemas/TerminalBlockDto', $schema['properties']['terminalRow']['items']['$ref']);
        self::assertSame('array', $schema['properties']['bottomTerminalRow']['type']);
        self::assertSame('#/components/schemas/TerminalBlockDto', $schema['properties']['bottomTerminalRow']['items']['$ref']);
    }

    public function testPanelContentSchemaListsCableSidesSuccess(): void
    {
        $schema = $this->schemas()['PanelContent'];

        foreach (['inputCableSide', 'outputCableSide'] as $field) {
            self::assertContains($field, $schema['required']);
            self::assertSame('string', $schema['properties'][$field]['type']);
            self::assertSame(['top', 'bottom', 'left', 'right'], $schema['properties'][$field]['enum']);
        }
        self::assertContains('bottomTerminalRow', $schema['required']);
    }

    public function testTerminalBlockSchemaHasColourAndLabelSuccess(): void
    {
        $schema = $this->schemas()['TerminalBlockDto'];

        self::assertSame(['id', 'offsetModules', 'widthModules', 'terminalCount', 'colour', 'label'], $schema['required']);
        self::assertSame(['string', 'null'], $schema['properties']['colour']['type']);
        self::assertSame(['black', 'brown', 'red', 'blue', 'green-yellow', null], $schema['properties']['colour']['enum']);
        self::assertSame('string', $schema['properties']['label']['type']);
    }

    public function testElementSchemaHasKindAndStateSuccess(): void
    {
        $schema = $this->schemas()['ElementDto'];

        self::assertContains('kind', $schema['required']);
        self::assertContains('state', $schema['required']);
        self::assertSame(['device', 'ats'], $schema['properties']['kind']['enum']);
        self::assertSame(['A', 'B', 'off', null], $schema['properties']['state']['enum']);
        self::assertContains('style', $schema['required']);
        self::assertSame(['string', 'null'], $schema['properties']['style']['type']);
        self::assertSame(['breaker', 'rcd', 'voltageRelay', null], $schema['properties']['style']['enum']);
        self::assertSame(['boolean', 'null'], $schema['properties']['on']['type']);
        self::assertSame(['array', 'null'], $schema['properties']['poles']['type']);
    }

    public function testEndpointSchemaHasContactSideSuccess(): void
    {
        $schema = $this->schemas()['EndpointDto'];

        self::assertSame(['top', 'bottom', 'left', 'right', null], $schema['properties']['contactSide']['enum']);
        self::assertSame(['kind'], $schema['required'], 'contactSide is optional on input (absent or null for pole and cable-wire ends).');
    }

    public function testRemovedStageTwoShapeIsGoneError(): void
    {
        $schemas = $this->schemas();

        self::assertArrayNotHasKey('terminalSide', $schemas['EndpointDto']['properties']);
        self::assertArrayNotHasKey('terminalRow', $schemas['RailDto']['properties']);
    }

    public function testRailSchemaHasBusbarsAndNoWidthSuccess(): void
    {
        $schema = $this->schemas()['RailDto'];

        self::assertContains('busbars', $schema['required']);
        self::assertSame('array', $schema['properties']['busbars']['type']);
        self::assertSame('#/components/schemas/BusbarDto', $schema['properties']['busbars']['items']['$ref']);
    }

    public function testRailSchemaHasNoWidthModulesError(): void
    {
        $schema = $this->schemas()['RailDto'];

        self::assertArrayNotHasKey('widthModules', $schema['properties']);
        self::assertNotContains('widthModules', $schema['required']);
    }

    public function testBusbarSchemaSuccess(): void
    {
        $schema = $this->schemas()['BusbarDto'];

        self::assertSame(['id', 'type', 'colour', 'firstElementId', 'count'], $schema['required']);
        self::assertSame(['string', 'null'], $schema['properties']['colour']['type']);
        self::assertSame(['black', 'brown', 'red', 'blue', 'green-yellow', null], $schema['properties']['colour']['enum']);
        self::assertSame(['L', 'LN'], $schema['properties']['type']['enum']);
        self::assertSame('integer', $schema['properties']['count']['type']);
    }

    public function testCableSchemasRequireLabelSuccess(): void
    {
        foreach (['InputCableDto', 'OutputCableDto'] as $name) {
            $schema = $this->schemas()[$name];

            self::assertContains('label', $schema['required'], $name);
            self::assertSame('string', $schema['properties']['label']['type'], $name);
        }
    }

    public function testEndpointSchemaHasBusbarKindSuccess(): void
    {
        $schema = $this->schemas()['EndpointDto'];

        self::assertContains('busbar', $schema['properties']['kind']['enum']);
        self::assertArrayHasKey('busbarId', $schema['properties']);
        self::assertArrayHasKey('feedIndex', $schema['properties']);
        self::assertSame(['kind'], $schema['required'], 'busbarId and feedIndex are optional on input.');
    }
}
