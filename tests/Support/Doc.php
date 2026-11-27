<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * A mutable JSON-like document addressed by dotted paths (`rails.0.elements.0.label`). Lets the case
 * tables edit the sample content without fighting PHPStan over nested array shapes.
 *
 * @phpstan-import-type Content from PanelShapes
 */
final class Doc
{
    /** @param array<array-key, mixed> $data */
    public function __construct(private array $data) {}

    public function set(string $path, mixed $value): void
    {
        $this->data = self::write($this->data, explode('.', $path), $value);
    }

    /** Appends to the list at the path. */
    public function append(string $path, mixed $value): void
    {
        $list = $this->get($path);
        $list = \is_array($list) ? $list : [];
        $list[] = $value;
        $this->set($path, $list);
    }

    public function unset(string $path): void
    {
        $segments = explode('.', $path);
        $last = array_pop($segments);
        if ([] === $segments) {
            unset($this->data[$last]);

            return;
        }
        $parent = $this->get(implode('.', $segments));
        if (\is_array($parent)) {
            unset($parent[$last]);
            $this->set(implode('.', $segments), $parent);
        }
    }

    public function get(string $path): mixed
    {
        $value = $this->data;
        foreach (explode('.', $path) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @return Content */
    public function content(): array
    {
        /** @var Content $content */
        $content = $this->data;

        return $content;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string>            $segments
     *
     * @return array<array-key, mixed>
     */
    private static function write(array $data, array $segments, mixed $value): array
    {
        $key = array_shift($segments);
        if (null === $key) {
            return $data;
        }
        if ([] === $segments) {
            $data[$key] = $value;

            return $data;
        }
        $child = $data[$key] ?? [];
        $data[$key] = self::write(\is_array($child) ? $child : [], $segments, $value);

        return $data;
    }
}
