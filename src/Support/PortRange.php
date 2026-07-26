<?php

namespace PelicanDev\PelicanPangolin\Support;

use InvalidArgumentException;

final readonly class PortRange
{
    public function __construct(public int $start, public int $end)
    {
        if ($start < 1 || $end > 65535 || $start > $end) {
            throw new InvalidArgumentException("Invalid port range: {$start}-{$end}");
        }
    }

    public static function parse(string $value): self
    {
        if (! preg_match('/^(\d+)(?:-(\d+))?$/', trim($value), $matches)) {
            throw new InvalidArgumentException("Invalid port range: {$value}");
        }

        return new self((int) $matches[1], (int) ($matches[2] ?? $matches[1]));
    }

    public function contains(int $port): bool
    {
        return $port >= $this->start && $port <= $this->end;
    }
}
