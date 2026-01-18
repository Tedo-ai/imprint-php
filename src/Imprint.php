<?php

declare(strict_types=1);

namespace Imprint;

use Imprint\Context\Context;
use Throwable;

/**
 * Main facade for the Imprint SDK.
 *
 * Provides static convenience methods for common operations.
 */
class Imprint
{
    private static ?Client $client = null;
    private static ?Configuration $configuration = null;

    /**
     * Configure the Imprint SDK.
     */
    public static function configure(Configuration $config): void
    {
        self::$configuration = $config;
        self::$client = null; // Reset client when configuration changes
    }

    /**
     * Get the configuration.
     */
    public static function configuration(): Configuration
    {
        return self::$configuration ??= Configuration::fromEnvironment();
    }

    /**
     * Get or create the client instance.
     */
    public static function client(): Client
    {
        return self::$client ??= new Client(self::configuration());
    }

    /**
     * Start a new span.
     *
     * @param string $name Span name
     * @param string $kind Span kind (server, client, internal, etc.)
     * @return Span
     */
    public static function startSpan(string $name, string $kind = Span::KIND_INTERNAL): Span
    {
        return self::client()->startSpan($name, $kind);
    }

    /**
     * Execute a callback within a traced span.
     *
     * @template T
     * @param string $name Span name
     * @param callable(Span): T $callback
     * @param string $kind Span kind
     * @return T
     */
    public static function trace(string $name, callable $callback, string $kind = Span::KIND_INTERNAL): mixed
    {
        return self::client()->trace($name, $callback, $kind);
    }

    /**
     * Record an instant event.
     */
    public static function recordEvent(string $name, array $attributes = []): void
    {
        self::client()->recordEvent($name, $attributes);
    }

    /**
     * Record a gauge metric value.
     */
    public static function recordGauge(string $name, float|int $value, array $attributes = []): void
    {
        self::client()->recordGauge($name, $value, $attributes);
    }

    /**
     * Alias for recordGauge (AppSignal compatibility).
     */
    public static function setGauge(string $name, float|int $value, array $attributes = []): void
    {
        self::recordGauge($name, $value, $attributes);
    }

    /**
     * Record a log entry with trace correlation.
     */
    public static function log(string $message, string $severity = 'info', array $attributes = []): void
    {
        self::client()->recordLog($message, $severity, $attributes);
    }

    // Log level convenience methods
    public static function debug(string $message, array $attributes = []): void
    {
        self::log($message, 'debug', $attributes);
    }

    public static function info(string $message, array $attributes = []): void
    {
        self::log($message, 'info', $attributes);
    }

    public static function warn(string $message, array $attributes = []): void
    {
        self::log($message, 'warn', $attributes);
    }

    public static function error(string $message, array $attributes = []): void
    {
        self::log($message, 'error', $attributes);
    }

    public static function fatal(string $message, array $attributes = []): void
    {
        self::log($message, 'fatal', $attributes);
    }

    /**
     * Add custom attributes to the current span.
     */
    public static function tag(array $tags): void
    {
        $span = Context::getCurrentSpan();
        $span?->setAttributes(array_map('strval', $tags));
    }

    /**
     * Send an error to Imprint.
     */
    public static function sendError(Throwable $exception, array $context = []): void
    {
        $span = Context::getCurrentSpan();

        if ($span) {
            $span->recordError($exception);
            $span->setAttributes(array_map('strval', $context));
        } else {
            // No active span - create a standalone error event
            $attributes = array_merge([
                'error.class' => get_class($exception),
                'error.message' => $exception->getMessage(),
                'error.backtrace' => implode("\n", array_slice(explode("\n", $exception->getTraceAsString()), 0, 10)),
            ], array_map('strval', $context));

            self::recordEvent('error: ' . get_class($exception), $attributes);
        }
    }

    /**
     * Set the action name for the current span.
     */
    public static function setAction(string $name): void
    {
        Context::getCurrentSpan()?->setName($name);
    }

    /**
     * Set the namespace for the current span.
     */
    public static function setNamespace(string $namespace): void
    {
        Context::getCurrentSpan()?->setNamespace($namespace);
    }

    /**
     * Get the current trace ID.
     */
    public static function currentTraceId(): ?string
    {
        return Context::getCurrentTraceId();
    }

    /**
     * Get the current span ID.
     */
    public static function currentSpanId(): ?string
    {
        return Context::getCurrentSpanId();
    }

    /**
     * Flush all buffers and shutdown the client.
     */
    public static function shutdown(): void
    {
        self::$client?->shutdown();
    }

    /**
     * Reset the SDK (useful for testing).
     */
    public static function reset(): void
    {
        self::$client?->shutdown();
        self::$client = null;
        self::$configuration = null;
        Context::clear();
    }
}
