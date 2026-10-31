<?php

declare(strict_types=1);

namespace Facturalo\Exception;

use RuntimeException;
use Throwable;

/**
 * Error base de la API: cualquier respuesta HTTP fuera de 2xx lanza esto o una subclase.
 */
class FacturaloApiException extends RuntimeException
{
    /**
     * @param array<string, mixed> $errors Errores por campo tal como los devuelve la API (422).
     * @param array<string, mixed>|null $body Cuerpo completo de la respuesta.
     */
    public function __construct(
        string $message,
        private readonly int $status,
        private readonly array $errors = [],
        private readonly ?array $body = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Errores por campo. En campos de catálogo el valor trae `mensaje` y `valoresValidos`.
     *
     * @return array<string, mixed>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed>|null */
    public function getBody(): ?array
    {
        return $this->body;
    }

    /**
     * @param array<string, mixed> $errors
     * @param array<string, mixed>|null $body
     */
    public static function forStatus(int $status, string $message, array $errors = [], ?array $body = null): self
    {
        $class = match ($status) {
            401 => FacturaloAuthenticationException::class,
            403 => FacturaloPermissionException::class,
            404 => FacturaloNotFoundException::class,
            400, 422 => FacturaloValidationException::class,
            429 => FacturaloRateLimitException::class,
            default => self::class,
        };

        return new $class($message, $status, $errors, $body);
    }
}
