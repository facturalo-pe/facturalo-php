<?php

declare(strict_types=1);

namespace Facturalo;

use Facturalo\Exception\FacturaloApiException;
use Facturalo\Exception\FacturaloConnectionException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Transporte HTTP compartido por FacturaloClient y FacturaloCuentaClient. Resuelve auth, timeouts,
 * reintentos y errores; los recursos solo arman ruta y cuerpo.
 *
 * @internal
 */
final class HttpClient
{
    public const DEFAULT_BASE_URL = 'https://api.facturalo.pe/api/v3';
    /** El envío síncrono espera la respuesta de SUNAT: puede tardar varios segundos. */
    private const DEFAULT_TIMEOUT_SECONDS = 90;
    private const MAX_RETRIES = 2;
    private const RETRYABLE_STATUS = [429, 502, 503, 504];

    private readonly ClientInterface $http;
    private readonly string $baseUrl;

    public function __construct(
        private readonly string $token,
        ?string $baseUrl = null,
        ?ClientInterface $httpClient = null,
        private readonly float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
        $this->baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
        $this->http = $httpClient ?? new GuzzleClient();
    }

    /**
     * Devuelve `data` del sobre `{ success, message, data }`.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, scalar|null> $query
     * @param array<string, string> $headers Headers extra (p. ej. Idempotency-Key).
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $body = null, array $query = [], array $headers = []): array
    {
        $envelope = $this->send($method, $path, $body, $query, $headers);
        $data = $envelope['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /** Descarga binaria (PDF, XML, CDR). Las URLs de archivos son públicas: no envía el token. */
    public function download(string $url): string
    {
        try {
            $response = $this->http->request('GET', $url, ['http_errors' => false, 'timeout' => $this->timeoutSeconds]);
        } catch (GuzzleException $e) {
            throw new FacturaloConnectionException('No se pudo conectar con la API de Facturalo.', 0, $e);
        }
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw FacturaloApiException::forStatus($status, "No se pudo descargar {$url}");
        }

        return (string) $response->getBody();
    }

    public function fileUrl(string $path): string
    {
        return $this->baseUrl . $path;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, scalar|null> $query
     * @param array<string, string> $extraHeaders
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $body, array $query, array $extraHeaders = []): array
    {
        $options = [
            'headers' => array_merge([
                // Facturalo no usa el esquema Bearer: el token va tal cual.
                'Authorization' => $this->token,
                'Accept' => 'application/json',
            ], $extraHeaders),
            'http_errors' => false,
            'timeout' => $this->timeoutSeconds,
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }
        $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');
        if ($query !== []) {
            $options['query'] = array_map(static fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : $v, $query);
        }

        // GET siempre. POST solo con Idempotency-Key (el servidor deduplica).
        $canRetry = $method === 'GET' || isset($extraHeaders['Idempotency-Key']);
        $maxAttempts = $canRetry ? self::MAX_RETRIES + 1 : 1;

        $lastException = null;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0) {
                usleep((int) ((2 ** $attempt) * 200_000));
            }
            try {
                $response = $this->http->request($method, $this->baseUrl . $path, $options);
            } catch (ConnectException $e) {
                $lastException = new FacturaloConnectionException('No se pudo conectar con la API de Facturalo.', 0, $e);
                continue;
            } catch (GuzzleException $e) {
                throw new FacturaloConnectionException('No se pudo conectar con la API de Facturalo.', 0, $e);
            }

            $status = $response->getStatusCode();
            $decoded = json_decode((string) $response->getBody(), true);
            $decoded = is_array($decoded) ? $decoded : [];

            if ($status >= 200 && $status < 300) {
                return $decoded;
            }

            $exception = FacturaloApiException::forStatus(
                $status,
                is_string($decoded['message'] ?? null) ? $decoded['message'] : $response->getReasonPhrase(),
                is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [],
                $decoded ?: null,
            );
            if ($canRetry && in_array($status, self::RETRYABLE_STATUS, true) && $attempt < self::MAX_RETRIES) {
                $lastException = $exception;
                continue;
            }
            throw $exception;
        }

        throw $lastException ?? new FacturaloConnectionException('No se pudo conectar con la API de Facturalo.');
    }
}
