<?php

declare(strict_types=1);

namespace Facturalo\Resource;

use Facturalo\HttpClient;

/** Consultas de RUC, DNI, tipo de cambio y vehículos. Aceptan token de empresa o de usuario. */
final class Consultas
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /** @return array<string, mixed> */
    public function dni(string $dni): array
    {
        return $this->http->request('GET', '/consulta/dni/' . rawurlencode($dni));
    }

    /**
     * Razón social, estado, condición, dirección y ubigeo.
     *
     * @return array<string, mixed>
     */
    public function ruc(string $ruc): array
    {
        return $this->http->request('GET', '/consulta/ruc/' . rawurlencode($ruc));
    }

    /**
     * Establecimientos anexos registrados en SUNAT.
     *
     * @return array<string, mixed>
     */
    public function rucEstablecimientos(string $ruc): array
    {
        return $this->http->request('GET', '/consulta/ruc/' . rawurlencode($ruc) . '/anexos');
    }

    /** @return array<string, mixed> */
    public function rucRepresentantes(string $ruc): array
    {
        return $this->http->request('GET', '/consulta/ruc/' . rawurlencode($ruc) . '/representantes');
    }

    /**
     * Tipo de cambio SUNAT. Sin fecha (YYYY-MM-DD), el del día.
     *
     * @return array<string, mixed>
     */
    public function tipoCambio(?string $fecha = null): array
    {
        return $this->http->request('GET', '/consulta/tipo-cambio', null, ['fecha' => $fecha]);
    }

    /** @return array<string, mixed> */
    public function placa(string $placa): array
    {
        return $this->http->request('GET', '/consulta/placa/' . rawurlencode($placa));
    }

    /** @return array<string, mixed> */
    public function soat(string $placa): array
    {
        return $this->http->request('GET', '/consulta/placa/' . rawurlencode($placa) . '/soat');
    }

    /** @return array<string, mixed> */
    public function licencia(string $dni): array
    {
        return $this->http->request('GET', '/consulta/licencia/' . rawurlencode($dni));
    }
}
