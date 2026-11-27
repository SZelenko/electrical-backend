<?php

declare(strict_types=1);

namespace App\Serializer;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Keeps a JSON float out of an `int` constructor parameter of the panel DTOs. With
 * `collectDenormalizationErrors` the serializer records the type error and then still passes the raw
 * value to the constructor, where PHP truncates `24.5` to `24` with an "implicit conversion loses
 * precision" deprecation. The float is replaced by a value no int parameter accepts, so the
 * serializer reports the usual 422 type violation on the property path and nothing is truncated.
 * JSON does not distinguish `24` from `24.0`, so a float without a fractional part that fits a PHP
 * int is accepted and converted to int first; `24.5` and out-of-range values such as `1e20` are rejected.
 */
#[AutoconfigureTag('serializer.normalizer', ['priority' => 100])]
final class PanelIntegerGuardDenormalizer implements DenormalizerInterface, DenormalizerAwareInterface
{
    use DenormalizerAwareTrait;

    /** @var array<class-string, list<string>> names of the int constructor parameters per class */
    private array $intParameters = [];

    private const string GUARDED = 'panel_integer_guard';
    private const array NAMESPACES = ['App\\Panel\\Content\\', 'App\\ApiResource\\Panel\\'];

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        $guarded = \is_array($context[self::GUARDED] ?? null) ? $context[self::GUARDED] : [];
        if (!\is_array($data) || isset($guarded[$type]) || !class_exists($type)) {
            return false;
        }
        foreach (self::NAMESPACES as $prefix) {
            if (str_starts_with($type, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, false> */
    public function getSupportedTypes(?string $format): array
    {
        return ['object' => false];
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        \assert(\is_array($data) && class_exists($type));

        foreach ($this->intParameters($type) as $name) {
            if (isset($data[$name]) && \is_float($data[$name])) {
                $data[$name] = self::integral($data[$name]) ?? '';
            }
        }

        $guarded = \is_array($context[self::GUARDED] ?? null) ? $context[self::GUARDED] : [];
        $guarded[$type] = true;
        $context[self::GUARDED] = $guarded;

        return $this->denormalizer->denormalize($data, $type, $format, $context);
    }

    /**
     * @param class-string $type
     *
     * @return list<string>
     */
    private function intParameters(string $type): array
    {
        if (!isset($this->intParameters[$type])) {
            $names = [];
            foreach (new \ReflectionClass($type)->getConstructor()?->getParameters() ?? [] as $parameter) {
                $reflectionType = $parameter->getType();
                if ($reflectionType instanceof \ReflectionNamedType && 'int' === $reflectionType->getName()) {
                    $names[] = $parameter->getName();
                }
            }
            $this->intParameters[$type] = $names;
        }

        return $this->intParameters[$type];
    }

    private static function integral(float $value): ?int
    {
        return is_finite($value) && floor($value) === $value && abs($value) < 9.2e18 ? (int) $value : null;
    }
}
