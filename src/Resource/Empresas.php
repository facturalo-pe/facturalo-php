<?php

declare(strict_types=1);

namespace Facturalo\Resource;

use Facturalo\HttpClient;

/** Empresas emisoras de tu cuenta (token de usuario user_sk_...). */
final class Empresas
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /** @return array{total: int, empresas: array<int, array<string, mixed>>} */
    public function listar(): array
    {
        /** @var array{total: int, empresas: array<int, array<string, mixed>>} */
        return $this->http->request('GET', '/empresas');
    }

    /** @return array<string, mixed> */
    public function obtener(string $id): array
    {
        return $this->http->request('GET', '/empresas/' . rawurlencode($id))['empresa'] ?? [];
    }

    /**
     * Si el RUC ya está registrado en Facturalo.
     *
     * @return array{disponible: bool, motivo: string|null}
     */
    public function verificarRuc(string $ruc): array
    {
        /** @var array{disponible: bool, motivo: string|null} */
        return $this->http->request('GET', '/empresas/verificar-ruc/' . rawurlencode($ruc));
    }

    /**
     * Registra la empresa y genera sus tokens sk_test_... y sk_live_....
     *
     * @param array<string, mixed> $empresa ruc, nombre, direccion, departamento, provincia, distrito, codigoPostal, sunat{solUsuario, solClave, clientId?, clientSecret?}
     * @return array<string, mixed>
     */
    public function crear(array $empresa): array
    {
        return $this->http->request('POST', '/empresas', ['empresa' => $empresa])['empresa'] ?? [];
    }

    /**
     * Solo cambia lo que envías (incluye `sunat` para Clave SOL y API SUNAT).
     *
     * @param array<string, mixed> $empresa
     * @return array<string, mixed>
     */
    public function actualizar(string $id, array $empresa): array
    {
        return $this->http->request('PATCH', '/empresas/' . rawurlencode($id), ['empresa' => $empresa])['empresa'] ?? [];
    }

    /**
     * URL de webhook por entorno (null la quita).
     *
     * @param array{demo?: string|null, prod?: string|null} $webhook
     * @return array<string, mixed>
     */
    public function actualizarWebhook(string $id, array $webhook): array
    {
        return $this->http->request('PATCH', '/empresas/' . rawurlencode($id) . '/webhook', ['webhook' => $webhook])['empresa'] ?? [];
    }

    /**
     * Eventos activos por entorno.
     *
     * @return array{demo: array<int, array{evento: string, activo: bool}>, prod: array<int, array{evento: string, activo: bool}>}
     */
    public function eventosWebhook(string $id): array
    {
        /** @var array{demo: array<int, array{evento: string, activo: bool}>, prod: array<int, array{evento: string, activo: bool}>} */
        return $this->http->request('GET', '/empresas/' . rawurlencode($id) . '/webhook/eventos');
    }

    /**
     * Activa o desactiva eventos de un entorno.
     *
     * @param 'demo'|'prod' $entorno
     * @param array<int, array{evento: string, activo: bool}> $eventos
     * @return array<string, mixed>
     */
    public function actualizarEventosWebhook(string $id, string $entorno, array $eventos): array
    {
        return $this->http->request('PATCH', '/empresas/' . rawurlencode($id) . '/webhook/eventos', [
            'entorno' => $entorno,
            'eventos' => $eventos,
        ]);
    }

    /**
     * El token anterior deja de funcionar de inmediato.
     *
     * @param 'demo'|'prod' $entorno
     * @return array<string, mixed>
     */
    public function regenerarToken(string $id, string $entorno): array
    {
        return $this->http->request('POST', '/empresas/' . rawurlencode($id) . '/regenerar-token', ['entorno' => $entorno])['empresa'] ?? [];
    }

    /**
     * Nuevo secreto para verificar X-Facturalo-Firma.
     *
     * @param 'demo'|'prod' $entorno
     * @return array<string, mixed>
     */
    public function regenerarSecretWebhook(string $id, string $entorno): array
    {
        return $this->http->request('POST', '/empresas/' . rawurlencode($id) . '/webhook/regenerar-secret', ['entorno' => $entorno])['empresa'] ?? [];
    }

    /**
     * Titular y vigencia del certificado digital cargado (null si no hay).
     *
     * @return array<string, mixed>|null
     */
    public function certificado(string $id): ?array
    {
        $certificado = $this->http->request('GET', '/empresas/' . rawurlencode($id) . '/certificado')['certificado'] ?? null;

        return is_array($certificado) ? $certificado : null;
    }
}
