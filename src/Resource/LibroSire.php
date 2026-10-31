<?php

declare(strict_types=1);

namespace Facturalo\Resource;

use Facturalo\HttpClient;

/**
 * Un libro del SIRE: ventas (RVIE) o compras (RCE). Los nombres de los parámetros son los de SUNAT
 * (page, perPage, codTipoCDP...) y se envían tal cual. $periodo va como YYYYMM.
 * La empresa necesita las credenciales de la API SUNAT (client_id / client_secret).
 */
final class LibroSire
{
    /** @param 'ventas'|'compras' $libro */
    public function __construct(private readonly HttpClient $http, private readonly string $libro)
    {
    }

    private function path(string $periodo, string $accion): string
    {
        return '/sire/' . $this->libro . '/' . rawurlencode($periodo) . '/' . $accion;
    }

    /** @return array<string, mixed> */
    public function periodos(): array
    {
        return $this->http->request('GET', '/sire/' . $this->libro . '/periodos');
    }

    /**
     * @param array{tipo?: string} $query tipo: 1 propuesta (default), 2 preliminar...
     * @return array<string, mixed>
     */
    public function resumen(string $periodo, array $query = []): array
    {
        return $this->http->request('GET', $this->path($periodo, 'resumen'), null, $query);
    }

    /**
     * @param array{tipo?: string} $query
     * @return array<string, mixed>
     */
    public function resumenComprobantes(string $periodo, array $query = []): array
    {
        return $this->http->request('GET', $this->path($periodo, 'resumen-comprobantes'), null, $query);
    }

    /**
     * @param array<string, scalar|null> $query page, perPage; en compras también codTipoOpe, fecEmisionIni, fecEmisionFin.
     * @return array<string, mixed>
     */
    public function comprobantes(string $periodo, array $query = []): array
    {
        return $this->http->request('GET', $this->path($periodo, 'comprobantes'), null, $query);
    }

    /**
     * @param array<string, scalar|null> $query page, perPage, s, ord.
     * @return array<string, mixed>
     */
    public function inconsistencias(string $periodo, array $query = []): array
    {
        return $this->http->request('GET', $this->path($periodo, 'inconsistencias'), null, $query);
    }

    /**
     * @param array<string, scalar|null> $query codTipoCDP, numSerieCDP, numCDP, codCar, codOrigen.
     * @return array<string, mixed>
     */
    public function inconsistenciaPorComprobante(string $periodo, array $query): array
    {
        return $this->http->request('GET', $this->path($periodo, 'inconsistencia-por-comprobante'), null, $query);
    }

    /**
     * Acepta la propuesta de SUNAT para el periodo.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function aceptarPropuesta(string $periodo, array $body = []): array
    {
        return $this->http->request('POST', $this->path($periodo, 'aceptar-propuesta'), $body);
    }

    /**
     * Pide a SUNAT la exportación del periodo; devuelve un ticket para descargarTicket().
     *
     * @param array<string, scalar|null> $query
     * @return array<string, mixed>
     */
    public function exportar(string $periodo, array $query = []): array
    {
        return $this->http->request('GET', $this->path($periodo, 'exportar'), null, $query);
    }

    /** @return array<string, mixed> */
    public function descargarTicket(string $numTicket): array
    {
        return $this->http->request('GET', '/sire/' . $this->libro . '/tickets/' . rawurlencode($numTicket) . '/descargar');
    }

    /**
     * @param array<string, scalar|null> $query page, perPage.
     * @return array<string, mixed>
     */
    public function preliminares(string $periodo, array $query = []): array
    {
        return $this->http->request('GET', $this->path($periodo, 'preliminares'), null, $query);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function eliminarPreliminar(string $periodo, array $body): array
    {
        return $this->http->request('PUT', $this->path($periodo, 'eliminar-preliminar'), $body);
    }

    /**
     * @param array{id: string, codTipoRegistro?: string} $body
     * @return array<string, mixed>
     */
    public function eliminarPreliminarRegistrado(string $periodo, array $body): array
    {
        return $this->http->request('PUT', $this->path($periodo, 'eliminar-preliminar-registrado'), $body);
    }

    /**
     * Inicia la conciliación de tus comprobantes con la propuesta de SUNAT.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function conciliar(string $periodo, array $body = []): array
    {
        return $this->http->request('POST', $this->path($periodo, 'conciliar'), $body);
    }
}
