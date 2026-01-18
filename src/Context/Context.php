<?php

declare(strict_types=1);

namespace Imprint\Context;

use Imprint\Span;

/**
 * Manages the current span context.
 *
 * Uses a static stack to handle nested spans, similar to how Ruby uses
 * ActiveSupport::CurrentAttributes or thread-local storage.
 */
class Context
{
    /** @var Span[] */
    private static array $spanStack = [];

    /**
     * Get the current span.
     */
    public static function getCurrentSpan(): ?Span
    {
        return end(self::$spanStack) ?: null;
    }

    /**
     * Set the current span.
     */
    public static function setCurrentSpan(?Span $span): void
    {
        if ($span === null) {
            array_pop(self::$spanStack);
        } else {
            self::$spanStack[] = $span;
        }
    }

    /**
     * Execute a callback with a span as the current context.
     *
     * @template T
     * @param Span $span
     * @param callable(): T $callback
     * @return T
     */
    public static function withSpan(Span $span, callable $callback): mixed
    {
        self::$spanStack[] = $span;
        try {
            return $callback();
        } finally {
            array_pop(self::$spanStack);
        }
    }

    /**
     * Get the current trace ID.
     */
    public static function getCurrentTraceId(): ?string
    {
        return self::getCurrentSpan()?->getTraceId();
    }

    /**
     * Get the current span ID.
     */
    public static function getCurrentSpanId(): ?string
    {
        return self::getCurrentSpan()?->getSpanId();
    }

    /**
     * Clear all context (useful at request boundaries).
     */
    public static function clear(): void
    {
        self::$spanStack = [];
    }

    /**
     * Parse a W3C traceparent header.
     *
     * Format: 00-{trace_id}-{span_id}-{flags}
     *
     * @return array{trace_id: string, span_id: string}|null
     */
    public static function parseTraceparent(string $traceparent): ?array
    {
        $parts = explode('-', $traceparent);
        if (count($parts) !== 4) {
            return null;
        }

        [$version, $traceId, $spanId, $flags] = $parts;

        // Validate format
        if (strlen($traceId) !== 32 || strlen($spanId) !== 16) {
            return null;
        }

        return [
            'trace_id' => $traceId,
            'span_id' => $spanId,
        ];
    }

    /**
     * Generate a traceparent header from the current context.
     */
    public static function generateTraceparent(): ?string
    {
        $span = self::getCurrentSpan();
        return $span?->getTraceparent();
    }
}
