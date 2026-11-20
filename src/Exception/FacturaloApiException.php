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
    // Códigos (`code`) que la API envía en errores de plan y acceso. Ver getErrorCode().
    /** 402: la empresa no tiene un plan activo. */
    public const BILLING_REQUIRED = 'BILLING_REQUIRED';
    /** 402: el plan está pendiente de pago, suspendido o cancelado. */
    public const BILLING_SUSPENDED = 'BILLING_SUSPENDED';
    /** 402: plan Gratis, se usó el cupo del mes. */
    public const QUOTA_EXCEEDED = 'QUOTA_EXCEEDED';
    /** 402: se pasó el cupo del mes y no hay saldo. */
    public const SALDO_INSUFICIENTE = 'SALDO_INSUFICIENTE';
    /** 402: el plan no incluye guías de remisión. */
    public const PLAN_SIN_GUIAS = 'PLAN_SIN_GUIAS';
    /**
     * 403: el plan Gratis no incluye la API de producción (sk_live_). Está incluida en los planes
     * Básico, Pro y Negocio. En demo (sk_test_) la API está abierta en todos los planes.
     */
    public const PLAN_SIN_API = 'PLAN_SIN_API';
    /** 403: un plan API no incluye los módulos de negocio de la consola. */
    public const PLAN_SOLO_API = 'PLAN_SOLO_API';
    /** 403: la clave del plugin de WooCommerce solo emite y consulta un comprobante. */
    public const CLAVE_SOLO_WOOCOMMERCE = 'CLAVE_SOLO_WOOCOMMERCE';
    /** 422: una empresa con plan de Facturalo ERP intentó contratar un plan de Facturalo API (su plan de pago ya incluye la API). */
    public const PLAN_NO_DISPONIBLE = 'PLAN_NO_DISPONIBLE';

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
     * `code` de la respuesta (p. ej. self::PLAN_SIN_API), o null si la API no lo envió.
     * getCode() de PHP devuelve el status HTTP.
     */
    public function getErrorCode(): ?string
    {
        $code = $this->body['code'] ?? null;

        return is_string($code) ? $code : null;
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
