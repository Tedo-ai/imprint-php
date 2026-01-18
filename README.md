# Imprint PHP SDK

PHP client library for [Imprint](https://imprint.cloud) observability platform. Send traces, spans, and logs from your PHP applications.

## Installation

```bash
composer require imprint/imprint-php
```

## Quick Start

```php
use Imprint\Imprint;
use Imprint\Configuration;

// Configure the SDK
Imprint::configure(new Configuration(
    apiKey: 'imp_live_xxxxx',
    serviceName: 'my-php-app',
));

// Create a span
$span = Imprint::startSpan('process-order', 'internal');
try {
    // Your business logic
    processOrder($orderId);
    $span->setAttribute('order.id', $orderId);
} catch (Throwable $e) {
    $span->recordError($e);
    throw $e;
} finally {
    $span->end();
}
```

## Configuration

### Environment Variables

The SDK automatically reads configuration from environment variables:

```bash
IMPRINT_API_KEY=imp_live_xxxxx           # Required: Your API key
IMPRINT_SERVICE_NAME=my-app              # Service name (default: php-app)
IMPRINT_INGEST_URL=https://api.imprint.cloud/v1/spans  # Ingest endpoint
IMPRINT_ENABLED=true                     # Enable/disable tracing
IMPRINT_DEBUG=false                      # Enable debug logging
IMPRINT_SAMPLING_RATE=1.0                # Sample rate (0.0-1.0)
```

### Programmatic Configuration

```php
use Imprint\Configuration;
use Imprint\Imprint;

$config = new Configuration(
    apiKey: 'imp_live_xxxxx',
    serviceName: 'my-app',
    ingestUrl: 'https://api.imprint.cloud/v1/spans',
    enabled: true,
    debug: false,
    batchSize: 100,
    bufferSize: 1000,
    samplingRate: 1.0,
    ignorePaths: ['/health', '/ping'],
    ignoreExtensions: ['css', 'js', 'png'],
);

Imprint::configure($config);
```

## Usage

### Manual Tracing

```php
use Imprint\Imprint;
use Imprint\Span;

// Start a span manually
$span = Imprint::startSpan('my-operation', Span::KIND_INTERNAL);
$span->setAttribute('user.id', $userId);
$span->setNamespace('api');
$span->end();

// Or use the trace helper for automatic error handling
$result = Imprint::trace('process-payment', function ($span) use ($payment) {
    $span->setAttribute('payment.id', $payment->id);
    return $paymentProcessor->process($payment);
});
```

### Span Kinds

```php
use Imprint\Span;

Span::KIND_SERVER;    // Incoming HTTP request
Span::KIND_CLIENT;    // Outgoing HTTP/DB call
Span::KIND_INTERNAL;  // Internal operation
Span::KIND_CONSUMER;  // Queue job processing
Span::KIND_PRODUCER;  // Queue job dispatching
Span::KIND_EVENT;     // Instant event
```

### Setting Attributes

```php
$span->setAttribute('user.id', '123');
$span->setAttributes([
    'order.id' => $orderId,
    'order.total' => $total,
    'order.currency' => 'USD',
]);
```

### Recording Errors

```php
try {
    riskyOperation();
} catch (Throwable $e) {
    $span->recordError($e);
    throw $e;
}

// Or record a string error
$span->recordError('Something went wrong');
```

### Recording Events

```php
// Instant events (0 duration)
Imprint::recordEvent('user.logged_in', [
    'user.id' => $userId,
    'method' => 'oauth',
]);
```

### Recording Gauges

```php
// Gauge metrics (numeric values)
Imprint::recordGauge('queue.depth', 42, [
    'queue.name' => 'emails',
]);

Imprint::recordGauge('memory.usage_mb', memory_get_usage() / 1024 / 1024);
```

### Logging

```php
// Log with trace correlation
Imprint::log('Processing started', 'info', ['batch_id' => $batchId]);

// Convenience methods
Imprint::debug('Debugging info');
Imprint::info('Operation completed');
Imprint::warn('This might be a problem');
Imprint::error('Something failed');
Imprint::fatal('Critical failure');
```

### Context and Trace Propagation

```php
use Imprint\Context\Context;
use Imprint\Imprint;

// Get current trace/span IDs
$traceId = Imprint::currentTraceId();
$spanId = Imprint::currentSpanId();

// Generate W3C traceparent header for outgoing requests
$traceparent = Context::generateTraceparent();
// Returns: "00-{trace_id}-{span_id}-01"

// Parse incoming traceparent header
$context = Context::parseTraceparent($request->header('traceparent'));
if ($context) {
    // $context['trace_id'] and $context['span_id']
}
```

### Error Reporting

```php
use Imprint\Imprint;

try {
    riskyOperation();
} catch (Throwable $e) {
    Imprint::sendError($e, [
        'user.id' => $userId,
        'context' => 'payment_processing',
    ]);
    throw $e;
}
```

### Tagging the Current Span

```php
// Add attributes to the current span
Imprint::tag([
    'user.plan' => 'premium',
    'feature.flag' => 'new_checkout',
]);

// Set action name (updates span name)
Imprint::setAction('OrderController::store');

// Set namespace
Imprint::setNamespace('checkout');
```

### Shutdown

The SDK automatically flushes on shutdown, but you can manually flush:

```php
// Flush all buffered spans
Imprint::shutdown();

// Reset the SDK (useful in tests)
Imprint::reset();
```

## Span Data Model

Spans are sent to Imprint with this structure:

```json
{
  "trace_id": "32 character hex string",
  "span_id": "16 character hex string",
  "parent_id": "16 character hex string or null",
  "namespace": "service-name",
  "name": "operation name",
  "kind": "server|client|internal|consumer|producer|event",
  "start_time": "2024-01-15T10:30:00.000+00:00",
  "duration_ns": 1234567,
  "status_code": 200,
  "error_data": null,
  "attributes": {
    "key": "value"
  }
}
```

## Laravel Integration

For Laravel applications, use the [imprint-laravel](https://github.com/tedo-ai/imprint-laravel) package which provides:

- Automatic HTTP request tracing
- Database query tracing
- Queue job tracing
- Blade directives for RUM integration

```bash
composer require imprint/imprint-laravel
```

## Requirements

- PHP 8.1+
- Guzzle HTTP client 7.0+

## License

MIT
