<?php

declare(strict_types=1);

namespace App\Tests\Functional\Panel;

use App\Tests\Support\PanelShapes;
use App\Tests\Support\Doc;
use App\Tests\Support\PanelContentBuilder as B;
use App\Tests\Support\PanelContentCases;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Validation of `PUT /panels/{id}/content`: 422 with payload paths and item ids, 400 for malformed JSON.
 *
 * @phpstan-import-type Content from PanelShapes
 */
final class ContentValidationTest extends PanelApiTestCase
{
    /** @return iterable<string, array{Content}> */
    public static function validContents(): iterable
    {
        return PanelContentCases::validBoundaries();
    }

    /** @return iterable<string, array{Content, string, ?string}> */
    public static function invalidContents(): iterable
    {
        return PanelContentCases::invalid();
    }

    /** @param Content $content */
    #[DataProvider('validContents')]
    public function testValidBoundaryContentSuccess(array $content): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        $response = $this->putContent($user, $panel['id'], $content);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertSame(2, $this->panelRow($panel['id'])?->version);
    }

    /** @param Content $content */
    #[DataProvider('invalidContents')]
    public function testInvalidContentError(array $content, string $path, ?string $id): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        $response = $this->putContent($user, $panel['id'], $content);

        $violations = $this->violations($response);
        $problem = $this->json($response);
        self::assertSame('validation_error', $problem['type']);
        self::assertSame(422, $problem['status']);
        self::assertStringContainsString('application/problem+json', $response->getHeaders(false)['content-type'][0] ?? '');
        $matching = array_filter(
            $violations,
            static fn(array $v): bool => $v['propertyPath'] === $path && (null === $id || ($v['id'] ?? null) === $id),
        );
        self::assertNotEmpty($matching, \sprintf(
            'Expected a violation at "%s"%s, got: %s',
            $path,
            null === $id ? '' : " for item $id",
            implode('; ', array_map(static fn(array $v): string => $v['propertyPath'] . ' [' . ($v['id'] ?? '') . ']', \array_slice($violations, 0, 10))),
        ));
        foreach ($violations as $violation) {
            self::assertNotSame('', $violation['message']);
        }
        // Nothing was written: the stored panel still has its default content.
        self::assertSame(1, $this->panelRow($panel['id'])?->version);
        self::assertSame(1, $this->countRows('cable', $panel['id']));
        self::assertSame(0, $this->countRows('din_rail', $panel['id']));
    }

    public function testSeveralViolationsAreReportedTogetherError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $content = B::with(B::sample(), [
            'inputCables.0.voltage' => 0,
            'rails.0.elements.0.label' => str_repeat('x', 17),
            'wires.0.end' => B::cableWireEnd(11),
        ]);

        $violations = $this->violations($this->putContent($user, $panel['id'], $content));

        self::assertEqualsCanonicalizing(
            ['content.inputCables[0].voltage', 'content.rails[0].elements[0].label', 'content.wires[0].end'],
            array_column($violations, 'propertyPath'),
        );
        $voltage = array_values(array_filter($violations, static fn(array $v): bool => 'content.inputCables[0].voltage' === $v['propertyPath']));
        self::assertSame(B::uuid(10), $voltage[0]['id'] ?? null);
    }

    /** @return iterable<string, array{callable(Doc): void, string}> */
    public static function malformedContents(): iterable
    {
        yield 'inputCables is a string' => [static function (Doc $c): void {
            $c->set('inputCables', 'x');
        }, 'content.inputCables'];
        yield 'rails is missing' => [static function (Doc $c): void {
            $c->unset('rails');
        }, 'content.rails'];
        yield 'wires is missing' => [static function (Doc $c): void {
            $c->unset('wires');
        }, 'content.wires'];
        yield 'voltage is a string' => [static function (Doc $c): void {
            $c->set('inputCables.0.voltage', '230');
        }, 'content.inputCables[0].voltage'];
        yield 'voltage is null' => [static function (Doc $c): void {
            $c->set('inputCables.0.voltage', null);
        }, 'content.inputCables[0].voltage'];
        yield 'rail width is a float' => [static function (Doc $c): void {
            $c->set('railWidthModules', 24.5);
        }, 'content.railWidthModules'];
        yield 'rail width is 1e20' => [static function (Doc $c): void {
            $c->set('railWidthModules', 1e20);
        }, 'content.railWidthModules'];
        yield 'rail width is a string' => [static function (Doc $c): void {
            $c->set('railWidthModules', '24');
        }, 'content.railWidthModules'];
        yield 'rail width is missing' => [static function (Doc $c): void {
            $c->unset('railWidthModules');
        }, 'content.railWidthModules'];
        yield 'rail width is null' => [static function (Doc $c): void {
            $c->set('railWidthModules', null);
        }, 'content.railWidthModules'];
        yield 'input cable label is missing' => [static function (Doc $c): void {
            $c->unset('inputCables.0.label');
        }, 'content.inputCables[0].label'];
        yield 'output cable label is missing' => [static function (Doc $c): void {
            $c->unset('outputCables.0.label');
        }, 'content.outputCables[0].label'];
        yield 'input cable label is a number' => [static function (Doc $c): void {
            $c->set('inputCables.0.label', 5);
        }, 'content.inputCables[0].label'];
        yield 'output cable label is null' => [static function (Doc $c): void {
            $c->set('outputCables.0.label', null);
        }, 'content.outputCables[0].label'];
        yield 'rail busbars is missing' => [static function (Doc $c): void {
            $c->unset('rails.0.busbars');
        }, 'content.rails[0].busbars'];
        yield 'busbar without a type' => [static function (Doc $c): void {
            $c->set('rails.0.busbars', [['id' => B::uuid(60), 'firstElementId' => B::uuid(31), 'count' => 2]]);
        }, 'content.rails[0].busbars[0].type'];
        yield 'busbar with an unknown type' => [static function (Doc $c): void {
            $c->set('rails.0.busbars', [B::busbar(60, 31, 2, 'LNPE')]);
        }, 'content.rails[0].busbars[0].type'];
        yield 'busbar count is a string' => [static function (Doc $c): void {
            $c->set('rails.0.busbars', [['id' => B::uuid(60), 'type' => 'L', 'firstElementId' => B::uuid(31), 'count' => '2']]);
        }, 'content.rails[0].busbars[0].count'];
        yield 'busbar without a first element id' => [static function (Doc $c): void {
            $c->set('rails.0.busbars', [['id' => B::uuid(60), 'type' => 'L', 'count' => 2]]);
        }, 'content.rails[0].busbars[0].firstElementId'];
        yield 'busbar endpoint feed index is a string' => [static function (Doc $c): void {
            $c->set('rails.0.elements', [B::element(31, 0, 1), B::element(33, 1, 1)]);
            $c->set('rails.0.busbars', [B::busbar(60, 31, 2)]);
            $c->set('wires.0.end', ['kind' => 'busbar', 'busbarId' => B::uuid(60), 'feedIndex' => '0']);
        }, 'content.wires[0].end.feedIndex'];
        yield 'endpoint kind is a misspelled busbar' => [static function (Doc $c): void {
            $c->set('wires.0.end.kind', 'busbars');
        }, 'content.wires[0].end.kind'];
        yield 'unknown cable wire colour' => [static function (Doc $c): void {
            $c->set('inputCables.0.wires.0.colour', 'purple');
        }, 'content.inputCables[0].wires[0].colour'];
        yield 'unknown panel wire colour' => [static function (Doc $c): void {
            $c->set('wires.0.colour', 'white');
        }, 'content.wires[0].colour'];
        yield 'wire cross-section outside the series' => [static function (Doc $c): void {
            $c->set('wires.0.crossSection', '3');
        }, 'content.wires[0].crossSection'];
        yield 'wire cross-section as a number' => [static function (Doc $c): void {
            $c->set('wires.0.crossSection', 2.5);
        }, 'content.wires[0].crossSection'];
        yield 'unknown endpoint kind' => [static function (Doc $c): void {
            $c->set('wires.0.end.kind', 'junction');
        }, 'content.wires[0].end.kind'];
        yield 'unknown pole side' => [static function (Doc $c): void {
            $c->set('wires.0.end.side', 'left');
        }, 'content.wires[0].end.side'];
        yield 'pole index is a string' => [static function (Doc $c): void {
            $c->set('wires.0.end.poleIndex', '0');
        }, 'content.wires[0].end.poleIndex'];
        yield 'element on flag is a string' => [static function (Doc $c): void {
            $c->set('rails.0.elements.0.on', 'yes');
        }, 'content.rails[0].elements[0].on'];
        yield 'pole is not an object' => [static function (Doc $c): void {
            $c->set('rails.0.elements.0.poles.0', true);
        }, 'content.rails[0].elements[0].poles[0].transit'];
        yield 'label is a number' => [static function (Doc $c): void {
            $c->set('rails.0.elements.0.label', 5);
        }, 'content.rails[0].elements[0].label'];
        yield 'bend x is a string' => [static function (Doc $c): void {
            $c->set('wires.1.bends.0.x', 'ten');
        }, 'content.wires[1].bends[0].x'];
        yield 'wire id is a number' => [static function (Doc $c): void {
            $c->set('wires.0.id', 40);
        }, 'content.wires[0].id'];
    }

    /** @param callable(Doc): void $mutate */
    #[DataProvider('malformedContents')]
    public function testMalformedContentTypesError(callable $mutate, string $path): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $doc = new Doc(B::sample());
        $mutate($doc);

        $response = $this->putContent($user, $panel['id'], $doc->content());

        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertContains($path, $this->violationPaths($response));
        if (str_contains($path, 'widthModules')) {
            self::assertContains('This value should be of type int.', $this->violationMessages($response, $path));
        }
        self::assertSame(1, $this->panelRow($panel['id'])?->version);
    }

    public function testIntegralFloatsAreAcceptedForIntFieldsSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $this->saveContent($user, $panel['id'], B::minimal()); // version is now 2
        $content = B::with(B::sample(), ['railWidthModules' => 24.0]);

        $response = $this->panelApi('PUT', '/panels/' . $panel['id'] . '/content', $user, ['version' => 2.0, 'content' => $content]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        $data = $this->panelBody($response);
        self::assertSame(3, $data['version']);
        self::assertSame(24, $data['content']['railWidthModules']);
        self::assertSame(3, $this->panelRow($panel['id'])?->version);
        self::assertSame(24, $this->sqlInt('SELECT rail_width_modules FROM panel WHERE id = ?', [$panel['id']]));
    }

    /** @return list<string> */
    private function violationMessages(ResponseInterface $response, string $path): array
    {
        $messages = [];
        foreach ($this->violations($response) as $violation) {
            if ($violation['propertyPath'] === $path) {
                $messages[] = $violation['message'];
            }
        }

        return $messages;
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedEnvelopes(): iterable
    {
        yield 'version missing' => [['content' => ['railWidthModules' => 24, 'inputCables' => [], 'outputCables' => [], 'terminalRow' => [], 'rails' => [], 'wires' => []]]];
        yield 'version is a string' => [['version' => '1', 'content' => ['railWidthModules' => 24, 'inputCables' => [], 'outputCables' => [], 'terminalRow' => [], 'rails' => [], 'wires' => []]]];
        yield 'version is a float' => [['version' => 1.5, 'content' => ['railWidthModules' => 24, 'inputCables' => [], 'outputCables' => [], 'terminalRow' => [], 'rails' => [], 'wires' => []]]];
        yield 'version is 2.5' => [['version' => 2.5, 'content' => ['railWidthModules' => 24, 'inputCables' => [], 'outputCables' => [], 'terminalRow' => [], 'rails' => [], 'wires' => []]]];
        yield 'content missing' => [['version' => 1]];
        yield 'content is a string' => [['version' => 1, 'content' => 'x']];
        yield 'content is null' => [['version' => 1, 'content' => null]];
        yield 'empty object' => [[]];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('malformedEnvelopes')]
    public function testMalformedEnvelopeError(array $body): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        $response = $this->panelApi('PUT', '/panels/' . $panel['id'] . '/content', $user, $body);

        self::assertSame(422, $response->getStatusCode());
        self::assertNotEmpty($this->violationPaths($response));
        if (\is_float($body['version'] ?? null) && fmod($body['version'], 1.0) > 0.0) {
            self::assertContains('This value should be of type int.', $this->violationMessages($response, 'version'));
        }
        self::assertSame(1, $this->panelRow($panel['id'])?->version);
    }

    /** @return iterable<string, array{string, string}> Doc path of the list, expected violation path */
    public static function nonListPaths(): iterable
    {
        yield 'inputCables' => ['inputCables', 'content.inputCables'];
        yield 'outputCables' => ['outputCables', 'content.outputCables'];
        yield 'rails' => ['rails', 'content.rails'];
        yield 'wires' => ['wires', 'content.wires'];
        yield 'input cable wires' => ['inputCables.0.wires', 'content.inputCables[0].wires'];
        yield 'output cable wires' => ['outputCables.0.wires', 'content.outputCables[0].wires'];
        yield 'rail elements' => ['rails.0.elements', 'content.rails[0].elements'];
        yield 'rail terminal blocks' => ['rails.0.terminalBlocks', 'content.rails[0].terminalBlocks'];
        yield 'element poles' => ['rails.0.elements.0.poles', 'content.rails[0].elements[0].poles'];
        yield 'wire bends' => ['wires.1.bends', 'content.wires[1].bends'];
    }

    /** A JSON object (non-sequential numeric keys) where a list is required. */
    #[DataProvider('nonListPaths')]
    public function testNonListArrayError(string $docPath, string $violationPath): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $doc = new Doc(B::sample());
        $list = $doc->get($docPath);
        self::assertIsArray($list);
        self::assertNotSame([], $list);
        $doc->set($docPath, [5 => reset($list)]); // encodes as {"5": ...}

        $response = $this->putContent($user, $panel['id'], $doc->content());

        $violations = $this->violations($response);
        self::assertContains($violationPath, array_column($violations, 'propertyPath'));
        $matching = array_filter($violations, static fn(array $v): bool => $v['propertyPath'] === $violationPath);
        self::assertSame('Must be a JSON array (list), not an object.', array_values($matching)[0]['message'] ?? null);
        self::assertSame('validation_error', $this->json($response)['type']);
        self::assertSame(1, $this->panelRow($panel['id'])?->version);
    }

    public function testListsAreAcceptedSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        $response = $this->putContent($user, $panel['id'], B::sample());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testTwentyThousandBendsInTotalSuccess(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $wires = [];
        for ($i = 0; $i < 400; ++$i) {
            $wires[] = B::wire(20000 + $i, B::cableWireEnd(11), B::cableWireEnd(12), array_fill(0, 50, ['x' => 1, 'y' => 1]));
        }
        $content = B::with(B::sample(), ['wires' => $wires]);

        self::assertSame(200, $this->putContent($user, $panel['id'], $content)->getStatusCode());
    }

    public function testStaleVersionWithInvalidContentIs422Error(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $invalid = B::with(B::sample(), ['inputCables.0.voltage' => 0]);

        $response = $this->putContent($user, $panel['id'], $invalid, 99);

        self::assertContains('content.inputCables[0].voltage', array_column($this->violations($response), 'propertyPath'));
        self::assertSame(
            'The request is invalid; see violations.',
            $this->json($response)['detail'],
        );
    }

    public function testValidationDetailMessageError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        $response = $this->putContent($user, $panel['id'], B::with(B::sample(), ['railWidthModules' => 0]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('The request is invalid; see violations.', $this->json($response)['detail']);
    }

    public function testMalformedJsonError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);
        $url = '/panels/' . $panel['id'] . '/content';

        self::assertSame(400, $this->rawPanelApi('PUT', $url, $user, '{"version": 1, "content": {')->getStatusCode());
        self::assertSame(400, $this->rawPanelApi('PUT', $url, $user, '')->getStatusCode());
        self::assertSame(400, $this->rawPanelApi('PUT', $url, $user, 'null')->getStatusCode());
        self::assertSame(400, $this->rawPanelApi('PUT', $url, $user, '"a string"')->getStatusCode());
        self::assertSame(422, $this->rawPanelApi('PUT', $url, $user, '[1, 2]')->getStatusCode());
        self::assertSame(400, $this->rawPanelApi('PUT', $url, $user, "{\"version\": 1, \"content\": \"\xFF\"}")->getStatusCode());
        self::assertSame(1, $this->panelRow($panel['id'])?->version);
    }

    public function testUnsupportedContentTypeError(): void
    {
        $user = $this->createVerifiedUser();
        $panel = $this->createPanel($user);

        $response = $this->rawPanelApi('PUT', '/panels/' . $panel['id'] . '/content', $user, '{}', 'text/plain');

        self::assertSame(415, $response->getStatusCode());
    }
}
