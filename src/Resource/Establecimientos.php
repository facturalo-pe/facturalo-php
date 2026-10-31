<?php

declare(strict_types=1);

namespace Facturalo\Resource;

use Facturalo\HttpClient;

/** Establecimientos (locales) de una empresa. */
final class Establecimientos
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    private function base(string $empresaId): string
    {
        return '/empresas/' . rawurlencode($empresaId) . '/establecimientos';
    }

    /** @return array{total: int, establecimientos: array<int, array<string, mixed>>} */
    public function listar(string $empresaId): array
    {
        /** @var array{total: int, establecimientos: array<int, array<string, mixed>>} */
        return $this->http->request('GET', $this->base($empresaId));
    }

    /** @return array<string, mixed> */
    public function obtener(string $empresaId, string $id): array
    {
        return $this->http->request('GET', $this->base($empresaId) . '/' . rawurlencode($id))['establecimiento'] ?? [];
    }

    /**
     * @param array<string, mixed> $establecimiento nombre, direccion, departamento, provincia, distrito, codigoPostal, celular?, correo?, logo?
     * @return array<string, mixed>
     */
    public function crear(string $empresaId, array $establecimiento): array
    {
        return $this->http->request('POST', $this->base($empresaId), ['establecimiento' => $establecimiento])['establecimiento'] ?? [];
    }

    /**
     * @param array<string, mixed> $establecimiento
     * @return array<string, mixed>
     */
    public function actualizar(string $empresaId, string $id, array $establecimiento): array
    {
        return $this->http->request(
            'PATCH',
            $this->base($empresaId) . '/' . rawurlencode($id),
            ['establecimiento' => $establecimiento],
        )['establecimiento'] ?? [];
    }

    public function eliminar(string $empresaId, string $id): void
    {
        $this->http->request('DELETE', $this->base($empresaId) . '/' . rawurlencode($id));
    }
}
