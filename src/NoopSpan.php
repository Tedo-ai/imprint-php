<?php

declare(strict_types=1);

namespace Imprint;

use Throwable;

/**
 * A no-op span used when tracing is disabled.
 */
class NoopSpan extends Span
{
    public function __construct()
    {
        parent::__construct(
            traceId: '',
            spanId: '',
            parentId: null,
            namespace: '',
            name: '',
            kind: self::KIND_INTERNAL,
            client: null,
        );
    }

    public function setAttribute(string $key, mixed $value): self
    {
        return $this;
    }

    public function setAttributes(array $attributes): self
    {
        return $this;
    }

    public function recordError(Throwable|string $error): self
    {
        return $this;
    }

    public function setStatus(int $code): self
    {
        return $this;
    }

    public function setName(string $name): self
    {
        return $this;
    }

    public function setNamespace(string $namespace): self
    {
        return $this;
    }

    public function end(): void
    {
        // No-op
    }

    public function finish(): void
    {
        // No-op
    }
}
