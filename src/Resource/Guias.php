<?php

declare(strict_types=1);

namespace Facturalo\Resource;

use Facturalo\HttpClient;

/** Guías de remisión remitente (serie T...) y transportista (serie V...). */
final class Guias
{
    private const FORMATOS = ['pdf', 'xml', 'cdr'];

    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * POST /guias/enviar: la serie decide el tipo (T... remitente 09, V... transportista 31).
     * Con $webhook = true responde EN_PROCESO y el resultado llega como guia.sunat.respuesta.
     *
     * Remitente (09): `proveedor` {tipoDocumento, numeroDocumento, denominacion} es obligatorio con
     * motivo 02 y 07; `comprador` (mismos campos) con motivo 03; `descripcionMotivo` (máx. 100) con
     * motivo 13 si no hay `observaciones`. Los motivos 08 y 09 aún no se emiten (422).
     * Transportista (31): `datosVehiculo.numeroTUC` es obligatorio (10 a 15 letras y números).
     *
     * @param array<string, mixed> $guia
     * @param string|null $idempotencyKey Header Idempotency-Key (24 h).
     * @return array<string, mixed>
     */
    public function enviar(array $guia, bool $webhook = false, ?string $idempotencyKey = null): array
    {
        $headers = $idempotencyKey !== null && $idempotencyKey !== ''
            ? ['Idempotency-Key' => $idempotencyKey]
            : [];

        return $this->http->request('POST', '/guias/enviar', ['webhook' => $webhook, 'guia' => $guia], [], $headers);
    }

    /**
     * POST /guias/validar: con el mismo arreglo que enviar(), aplica las reglas de la API (422 como
     * enviar), arma el XML y corre las reglas de SUNAT. No usa el correlativo, no guarda ni envía.
     * Si las reglas de SUNAT no están disponibles lanza FacturaloApiException con status 503: la guía
     * se puede enviar igual.
     *
     * @param array<string, mixed> $guia
     * @return array{valido: bool, prediccion: string, guia: array{tipo: string, serie: string|null, correlativo: string|null},
     *     errores: list<array{codigo: string|null, mensaje: string, detalle: string}>,
     *     observaciones: list<array{codigo: string|null, mensaje: string, detalle: string}>}
     */
    public function validar(array $guia): array
    {
        /** @var array{valido: bool, prediccion: string, guia: array{tipo: string, serie: string|null, correlativo: string|null}, errores: list<array{codigo: string|null, mensaje: string, detalle: string}>, observaciones: list<array{codigo: string|null, mensaje: string, detalle: string}>} */
        return $this->http->request('POST', '/guias/validar', ['guia' => $guia]);
    }

    /**
     * GET /guias/{id}: la guía completa con su estado en SUNAT.
     *
     * @return array<string, mixed>
     */
    public function consultar(string $id): array
    {
        return $this->http->request('GET', '/guias/' . rawurlencode($id));
    }

    /**
     * POST /guias/ticket: consulta en SUNAT el ticket de una guía y actualiza su estado.
     *
     * @return array<string, mixed>
     */
    public function consultarTicket(string $ticket): array
    {
        return $this->http->request('POST', '/guias/ticket', ['ticket' => $ticket]);
    }

    /** @return array<string, mixed> */
    public function eventos(string $id): array
    {
        return $this->http->request('GET', '/guias/' . rawurlencode($id) . '/eventos');
    }

    /**
     * GET /empresas/guias: guías del entorno del token, paginadas.
     *
     * @param array{pagina?: int, limite?: int, fechaInicio?: string, fechaFin?: string, estado?: string, buscar?: string} $filtros
     * @return array<string, mixed>
     */
    public function listar(array $filtros = []): array
    {
        return $this->http->request('GET', '/empresas/guias', null, [
            'page' => $filtros['pagina'] ?? null,
            'limite' => $filtros['limite'] ?? null,
            'fecha_inicio' => $filtros['fechaInicio'] ?? null,
            'fecha_fin' => $filtros['fechaFin'] ?? null,
            'estado' => $filtros['estado'] ?? null,
            'buscar' => $filtros['buscar'] ?? null,
        ]);
    }

    public function urlArchivo(string $id, string $formato = 'pdf'): string
    {
        if (!in_array($formato, self::FORMATOS, true)) {
            throw new \InvalidArgumentException('Formato no válido. Usa pdf, xml o cdr.');
        }

        return $this->http->fileUrl('/guias/' . rawurlencode($id) . '.' . $formato);
    }

    public function descargar(string $id, string $formato = 'pdf'): string
    {
        return $this->http->download($this->urlArchivo($id, $formato));
    }
}
