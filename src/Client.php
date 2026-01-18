<?php

declare(strict_types=1);

namespace Imprint;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use Imprint\Context\Context;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Imprint client for sending spans to the ingest service.
 */
class Client
{
    /** @var Span[] */
    private array $spanBuffer = [];

    /** @var array<array<string, mixed>> */
    private array $logBuffer = [];

    private HttpClient $httpClient;
    private LoggerInterface $logger;
    private bool $stopped = false;

    public function __construct(
        private readonly Configuration $config,
        ?HttpClient $httpClient = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->httpClient = $httpClient ?? new HttpClient([
            'timeout' => 5,
            'connect_timeout' => 5,
        ]);
        $this->logger = $logger ?? new NullLogger();

        if ($this->config->debug) {
            $this->debug('Initializing client...');
            $this->debug('  API Key: ' . substr($this->config->apiKey, 0, 20) . '...');
            $this->debug('  Ingest URL: ' . $this->config->ingestUrl);
            $this->debug('  Enabled: ' . ($this->config->enabled ? 'true' : 'false'));
            $this->debug('  Valid: ' . ($this->config->isValid() ? 'true' : 'false'));
        }

        // Register shutdown function to flush remaining spans
        register_shutdown_function([$this, 'shutdown']);
    }

    /**
     * Check if the client is enabled and valid.
     */
    public function isEnabled(): bool
    {
        return $this->config->enabled && $this->config->isValid() && !$this->stopped;
    }

    /**
     * Start a new span.
     *
     * @param string $name Span name
     * @param string $kind Span kind (server, client, internal, etc.)
     * @param Span|null $parent Parent span (uses current context if null)
     * @return Span
     */
    public function startSpan(string $name, string $kind = Span::KIND_INTERNAL, ?Span $parent = null): Span
    {
        $parent = $parent ?? Context::getCurrentSpan();

        $traceId = $parent?->getTraceId() ?? Span::generateTraceId();
        $parentId = $parent?->getSpanId();

        $span = new Span(
            traceId: $traceId,
            spanId: Span::generateSpanId(),
            parentId: $parentId,
            namespace: $this->config->serviceName,
            name: $name,
            kind: $kind,
            client: $this,
        );

        Context::setCurrentSpan($span);

        return $span;
    }

    /**
     * Start a span and execute a callback within it.
     *
     * @template T
     * @param string $name Span name
     * @param callable(Span): T $callback
     * @param string $kind Span kind
     * @return T
     */
    public function trace(string $name, callable $callback, string $kind = Span::KIND_INTERNAL): mixed
    {
        $span = $this->startSpan($name, $kind);

        try {
            $result = Context::withSpan($span, fn() => $callback($span));
            $span->end();
            return $result;
        } catch (Throwable $e) {
            $span->recordError($e);
            $span->end();
            throw $e;
        }
    }

    /**
     * Record an instant event (0ms duration span).
     */
    public function recordEvent(string $name, array $attributes = []): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $parent = Context::getCurrentSpan();
        $traceId = $parent?->getTraceId() ?? Span::generateTraceId();
        $parentId = $parent?->getSpanId();

        $span = new Span(
            traceId: $traceId,
            spanId: Span::generateSpanId(),
            parentId: $parentId,
            namespace: $this->config->serviceName,
            name: $name,
            kind: Span::KIND_EVENT,
        );

        $span->setAttributes($attributes);
        $span->setDurationNs(0);
        $this->queueSpan($span);
    }

    /**
     * Record a gauge metric value.
     *
     * Gauges are numeric values that can go up or down (memory, queue depth, etc.).
     * They appear as line charts in the dashboard.
     */
    public function recordGauge(string $name, float|int $value, array $attributes = []): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $parent = Context::getCurrentSpan();
        $traceId = $parent?->getTraceId() ?? Span::generateTraceId();
        $parentId = $parent?->getSpanId();

        $span = new Span(
            traceId: $traceId,
            spanId: Span::generateSpanId(),
            parentId: $parentId,
            namespace: $this->config->serviceName,
            name: $name,
            kind: Span::KIND_EVENT,
        );

        // Set the gauge value - this is what makes it a gauge vs counter
        $span->setAttribute('metric.value', (string) $value);

        // Auto-inject service.instance.id if not present
        if (!isset($attributes['service.instance.id'])) {
            $span->setAttribute('service.instance.id', gethostname());
        }

        $span->setAttributes($attributes);
        $span->setDurationNs(0);
        $this->queueSpan($span);
    }

    /**
     * Record a log entry with trace correlation.
     */
    public function recordLog(string $message, string $severity = 'info', array $attributes = []): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $currentSpan = Context::getCurrentSpan();

        $logEntry = [
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED),
            'trace_id' => $currentSpan?->getTraceId() ?? '',
            'span_id' => $currentSpan?->getSpanId() ?? '',
            'severity' => $this->normalizeSeverity($severity),
            'message' => $message,
            'namespace' => $this->config->serviceName,
            'attributes' => array_merge(
                array_map('strval', $attributes),
                [
                    'telemetry.sdk.name' => Span::SDK_NAME,
                    'telemetry.sdk.version' => Span::SDK_VERSION,
                    'telemetry.sdk.language' => Span::SDK_LANGUAGE,
                ]
            ),
        ];

        $this->queueLog($logEntry);
    }

    /**
     * Queue a span for batch sending.
     */
    public function queueSpan(Span $span): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (count($this->spanBuffer) < $this->config->bufferSize) {
            $this->spanBuffer[] = $span;

            if (count($this->spanBuffer) >= $this->config->batchSize) {
                $this->flushSpans();
            }
        }
        // Drop span if buffer is full
    }

    /**
     * Queue a log entry for batch sending.
     */
    public function queueLog(array $logEntry): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (count($this->logBuffer) < $this->config->bufferSize) {
            $this->logBuffer[] = $logEntry;

            if (count($this->logBuffer) >= $this->config->batchSize) {
                $this->flushLogs();
            }
        }
    }

    /**
     * Flush all buffered spans immediately.
     */
    public function flushSpans(): void
    {
        if (empty($this->spanBuffer)) {
            return;
        }

        $spans = $this->spanBuffer;
        $this->spanBuffer = [];

        $this->sendBatch($spans);
    }

    /**
     * Flush all buffered logs immediately.
     */
    public function flushLogs(): void
    {
        if (empty($this->logBuffer)) {
            return;
        }

        $logs = $this->logBuffer;
        $this->logBuffer = [];

        $this->sendLogsBatch($logs);
    }

    /**
     * Flush all buffers and shutdown the client.
     */
    public function shutdown(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;
        $this->flushSpans();
        $this->flushLogs();
    }

    /**
     * Get the configuration.
     */
    public function getConfiguration(): Configuration
    {
        return $this->config;
    }

    /**
     * Send a batch of spans to the ingest service.
     *
     * @param Span[] $spans
     */
    private function sendBatch(array $spans): void
    {
        if (empty($spans)) {
            return;
        }

        $payload = array_map(fn(Span $span) => $span->toArray(), $spans);

        try {
            $this->debug("Sending " . count($spans) . " spans to {$this->config->ingestUrl}");

            $response = $this->httpClient->post($this->config->ingestUrl, [
                'json' => $payload,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config->apiKey,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $this->debug("Response: " . $response->getStatusCode() . " " . $response->getReasonPhrase());
        } catch (GuzzleException $e) {
            $this->debug("Error sending spans: " . $e->getMessage());
            // Silently fail to avoid impacting the application
        }
    }

    /**
     * Send a batch of logs to the logs endpoint.
     */
    private function sendLogsBatch(array $logs): void
    {
        if (empty($logs)) {
            return;
        }

        $logsUrl = $this->config->getLogsUrl();

        try {
            $this->debug("Sending " . count($logs) . " logs to {$logsUrl}");

            $response = $this->httpClient->post($logsUrl, [
                'json' => $logs,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config->apiKey,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $this->debug("Response: " . $response->getStatusCode() . " " . $response->getReasonPhrase());
        } catch (GuzzleException $e) {
            $this->debug("Error sending logs: " . $e->getMessage());
        }
    }

    private function normalizeSeverity(string $severity): string
    {
        return match (strtolower($severity)) {
            'debug', 'trace' => 'debug',
            'info', 'information' => 'info',
            'warn', 'warning' => 'warn',
            'error', 'err' => 'error',
            'fatal', 'critical', 'panic' => 'fatal',
            default => 'info',
        };
    }

    private function debug(string $message): void
    {
        if ($this->config->debug) {
            $this->logger->debug("[Imprint] {$message}");
            error_log("[Imprint] {$message}");
        }
    }
}
