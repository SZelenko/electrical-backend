<?php

declare(strict_types=1);

namespace App\Serializer;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalises the panel content DTOs (App\Panel\Content) as plain JSON objects. API Platform's JSON-LD
 * object normalizer would add `@type` (and `@id`) to every nested non-resource object; the content
 * tree is data, not linked resources, so it is serialised without that noise.
 */
#[AutoconfigureTag('serializer.normalizer', ['priority' => 100])]
final class PanelContentNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const string NAMESPACE_PREFIX = 'App\\Panel\\Content\\';

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return 'jsonld' === $format && \is_object($data) && str_starts_with($data::class, self::NAMESPACE_PREFIX);
    }

    /** @return array<string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return 'jsonld' === $format ? ['object' => false] : [];
    }

    /** @return array<string, mixed> */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert(\is_object($data));

        $result = [];
        foreach (get_object_vars($data) as $name => $value) {
            $result[(string) $name] = $this->normalizer->normalize($value, $format, $context);
        }

        return $result;
    }
}
