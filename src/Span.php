<?php

declare(strict_types=1);

namespace Imprint;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Represents a trace span.
 */
class Span
{
    public const SDK_NAME = 'imprint-php';
    public const SDK_VERSION = '0.1.0';
    public const SDK_LANGUAGE = 'php';

    public const KIND_SERVER = 'server';
    public const KIND_CLIENT = 'client';
    public const KIND_INTERNAL = 'internal';
    public const KIND_CONSUMER = 'consumer';
    public const KIND_PRODUCER = 'producer';
    public const KIND_EVENT = 'event';

    private DateTimeImmutable $startTime;
    private float $startTimeMonotonic;
    private ?int $durationNs = null;
    private int $statusCode = 200;
    private ?string $errorData = null;
    private array $attributes = [];
    private bool $ended = false;
    private ?Client $client;

    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentId,
        public string $namespace,
        public string $name,
        public readonly string $kind = self::KIND_INTERNAL,
        ?Client $client = null,
    ) {
        $this->startTime = new DateTimeImmutable();
        $this->startTimeMonotonic = hrtime(true);
        $this->client = $client;
    }

    /**
     * Generate a new trace ID (32 hex characters).
     */
    public static function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Generate a new span ID (16 hex characters).
     */
    public static function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Set an attribute on the span.
     */
    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = (string) $value;
        return $this;
    }

    /**
     * Merge multiple attributes at once.
     */
    public function setAttributes(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[(string) $key] = (string) $value;
        }
        return $this;
    }

    /**
     * Get all attributes.
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Record an error on the span.
     */
    public function recordError(Throwable|string $error): self
    {
        if ($error instanceof Throwable) {
            $this->errorData = get_class($error) . ': ' . $error->getMessage();
            $this->setAttribute('error.class', get_class($error));
            $this->setAttribute('error.message', $error->getMessage());
            $this->setAttribute('error.backtrace', implode("\n", array_slice(explode("\n", $error->getTraceAsString()), 0, 10)));
        } else {
            $this->errorData = $error;
        }

        if ($this->statusCode < 400) {
            $this->statusCode = 500;
        }

        return $this;
    }

    /**
     * Set the HTTP status code.
     */
    public function setStatus(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Get the status code.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Set the span name.
     */
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Set the span namespace.
     */
    public function setNamespace(string $namespace): self
    {
        $this->namespace = $namespace;
        return $this;
    }

    /**
     * Check if this is a root span.
     */
    public function isRoot(): bool
    {
        return $this->parentId === null;
    }

    /**
     * End the span and queue it for sending.
     */
    public function end(): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;
        $this->durationNs = (int) (hrtime(true) - $this->startTimeMonotonic);
        $this->client?->queueSpan($this);
    }

    /**
     * Alias for end().
     */
    public function finish(): void
    {
        $this->end();
    }

    /**
     * Get the W3C traceparent string.
     */
    public function getTraceparent(): string
    {
        return sprintf('00-%s-%s-01', $this->traceId, $this->spanId);
    }

    /**
     * Convert to array for JSON serialization.
     */
    public function toArray(): array
    {
        $mergedAttributes = array_merge($this->attributes, [
            'telemetry.sdk.name' => self::SDK_NAME,
            'telemetry.sdk.version' => self::SDK_VERSION,
            'telemetry.sdk.language' => self::SDK_LANGUAGE,
        ]);

        return array_filter([
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
            'parent_id' => $this->parentId,
            'namespace' => $this->namespace,
            'name' => $this->name,
            'kind' => $this->kind,
            'start_time' => $this->startTime->format(DateTimeInterface::RFC3339_EXTENDED),
            'duration_ns' => $this->durationNs ?? 0,
            'status_code' => $this->statusCode,
            'error_data' => $this->errorData,
            'attributes' => $mergedAttributes,
        ], fn($v) => $v !== null);
    }

    /**
     * Set duration manually (for retrospective spans).
     */
    public function setDurationNs(int $ns): self
    {
        $this->durationNs = $ns;
        $this->ended = true;
        return $this;
    }

    /**
     * Get the trace ID.
     */
    public function getTraceId(): string
    {
        return $this->traceId;
    }

    /**
     * Get the span ID.
     */
    public function getSpanId(): string
    {
        return $this->spanId;
    }
}
