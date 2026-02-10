<?php

declare(strict_types=1);

namespace Imprint;

/**
 * Configuration for the Imprint client.
 */
class Configuration
{
    public function __construct(
        public readonly string $apiKey,
        public readonly string $serviceName = 'php-app',
        public readonly string $ingestUrl = 'https://api.imprint.cloud/v1/spans',
        public readonly bool $enabled = true,
        public readonly bool $debug = false,
        public readonly int $batchSize = 100,
        public readonly int $flushInterval = 5, // seconds
        public readonly int $bufferSize = 1000,
        public readonly float $samplingRate = 1.0, // 0.0 to 1.0
        public readonly array $ignorePaths = [],
        public readonly array $ignorePrefixes = ['/assets/', '/build/'],
        public readonly array $ignoreExtensions = ['.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.ico', '.svg', '.woff', '.woff2', '.ttf', '.eot', '.map'],
        public readonly ?string $jobNamespace = null, // null means use serviceName
    ) {}

    /**
     * Create configuration from environment variables.
     */
    public static function fromEnvironment(): self
    {
        $samplingRate = getenv('IMPRINT_SAMPLING_RATE');

        return new self(
            apiKey: getenv('IMPRINT_API_KEY') ?: '',
            serviceName: getenv('IMPRINT_SERVICE_NAME') ?: 'php-app',
            ingestUrl: getenv('IMPRINT_INGEST_URL') ?: 'https://api.imprint.cloud/v1/spans',
            enabled: getenv('IMPRINT_ENABLED') !== 'false',
            debug: getenv('IMPRINT_DEBUG') === 'true',
            samplingRate: $samplingRate !== false ? (float) $samplingRate : 1.0,
            jobNamespace: getenv('IMPRINT_JOB_NAMESPACE') ?: null,
        );
    }

    /**
     * Check if the configuration is valid.
     */
    public function isValid(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Get the logs ingest URL.
     */
    public function getLogsUrl(): string
    {
        return str_replace('/v1/spans', '/v1/logs', $this->ingestUrl);
    }

    /**
     * Get the metrics ingest URL.
     */
    public function getMetricsUrl(): string
    {
        return str_replace('/v1/spans', '/v1/metrics', $this->ingestUrl);
    }

    /**
     * Get the effective namespace for background jobs.
     */
    public function getEffectiveJobNamespace(): string
    {
        return $this->jobNamespace ?? $this->serviceName;
    }

    /**
     * Check if a path should be ignored.
     */
    public function shouldIgnore(string $path): bool
    {
        // Check exact paths
        if (in_array($path, $this->ignorePaths, true)) {
            return true;
        }

        // Check prefixes
        foreach ($this->ignorePrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        // Check extensions
        foreach ($this->ignoreExtensions as $ext) {
            if (str_ends_with($path, $ext)) {
                return true;
            }
        }

        return false;
    }
}
