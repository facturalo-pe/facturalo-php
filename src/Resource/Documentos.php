<?php

declare(strict_types=1);

namespace Facturalo\Resource;

use Facturalo\HttpClient;

/** Facturas, boletas y notas de crédito y débito. */
final class Documentos
{
    private const EXTENSION = [
        'pdf' => '.pdf',
        'ticket' => '.ticket.pdf',
        'xml' => '.xml',
        'cdr' => '.cdr',
    ];

    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * POST /documentos/enviar: firma el XML, lo envía a SUNAT y devuelve el resultado.
     * Un rechazo de SUNAT no lanza excepción: llega con `estado` RECHAZADA y el motivo en `sunat`.
     * Con $webhook = true responde al instante con `estado` EN_PROCESO.
     *
     * @param array<string, mixed> $documento Cuerpo del comprobante (tipoComprobante, serie, cliente, items...).
     * @param string|null $idempotencyKey Header Idempotency-Key (24 h). Misma clave + mismo cuerpo = misma respuesta.
     * @return array{estado: string, entorno: string, documento: array<string, mixed>, sunat?: array<string, mixed>, enlaces: array<string, string>, hash?: string|null, cadenaQr?: string}
     */
    public function enviar(
        array $documento,
        bool $webhook = false,
        ?string $establecimiento = null,
        ?string $idempotencyKey = null,
    ): array {
        $body = ['webhook' => $webhook, 'documento' => $documento];
        if ($establecimiento !== null) {
            $body['establecimiento'] = $establecimiento;
        }

        $headers = $idempotencyKey !== null && $idempotencyKey !== ''
            ? ['Idempotency-Key' => $idempotencyKey]
            : [];

        return $this->http->request('POST', '/documentos/enviar', $body, [], $headers);
    }

    /**
     * POST /documentos/xml: genera y firma el XML sin enviarlo a SUNAT (`estado` XML_GENERADO).
     *
     * @param array<string, mixed> $documento
     * @return array<string, mixed>
     */
    public function generarXml(array $documento, ?string $establecimiento = null): array
    {
        $body = ['documento' => $documento];
        if ($establecimiento !== null) {
            $body['establecimiento'] = $establecimiento;
        }

        return $this->http->request('POST', '/documentos/xml', $body);
    }

    /**
     * GET /documentos/{id}: el documento completo (cliente, totales, ítems) con su estado en SUNAT.
     *
     * @return array<string, mixed>
     */
    public function consultar(string $id): array
    {
        return $this->http->request('GET', '/documentos/' . rawurlencode($id));
    }

    /**
     * GET /documentos/buscar: lo mismo que consultar(), pero por tipo, serie y correlativo.
     *
     * @return array<string, mixed>
     */
    public function buscar(string $tipoComprobante, string $serie, string $correlativo): array
    {
        return $this->http->request('GET', '/documentos/buscar', null, [
            'tipo_documento' => $tipoComprobante,
            'serie' => $serie,
            'correlativo' => $correlativo,
        ]);
    }

    /**
     * GET /empresas/documentos: documentos del entorno del token, paginados.
     *
     * @param array{pagina?: int, limite?: int, fechaInicio?: string, fechaFin?: string, tipo?: string, estado?: string, buscar?: string, establecimiento?: string} $filtros
     * @return array{entorno: string, paginacion: array{total: int, limite: int, pagina: int, paginas: int}, documentos: array<int, array<string, mixed>>}
     */
    public function listar(array $filtros = []): array
    {
        return $this->http->request('GET', '/empresas/documentos', null, [
            'page' => $filtros['pagina'] ?? null,
            'limite' => $filtros['limite'] ?? null,
            'fecha_inicio' => $filtros['fechaInicio'] ?? null,
            'fecha_fin' => $filtros['fechaFin'] ?? null,
            'tipo' => $filtros['tipo'] ?? null,
            'estado' => $filtros['estado'] ?? null,
            'buscar' => $filtros['buscar'] ?? null,
            'establecimiento' => $filtros['establecimiento'] ?? null,
        ]);
    }

    /**
     * POST /documentos/anular: facturas por Comunicación de Baja, boletas por Resumen Diario.
     * Si SUNAT aún procesa el ticket, vuelve `estado` EN_PROCESO.
     *
     * @return array{estado: string, documento: array<string, string>, sunat: array<string, mixed>}
     */
    public function anular(string $tipoComprobante, string $serie, string $correlativo, string $motivo): array
    {
        return $this->http->request('POST', '/documentos/anular', [
            'documento' => [
                'tipoComprobante' => $tipoComprobante,
                'serie' => $serie,
                'correlativo' => $correlativo,
                'motivo' => $motivo,
            ],
        ]);
    }

    /**
     * POST /documentos/{id}/reenviar: reenvía a SUNAT un documento que quedó sin enviar o pendiente.
     *
     * @return array<string, mixed>
     */
    public function reenviar(string $id): array
    {
        return $this->http->request('POST', '/documentos/' . rawurlencode($id) . '/reenviar');
    }

    /**
     * GET /documentos/{id}/eventos: historial del documento (envíos, respuestas, webhooks, correos).
     *
     * @return array<string, mixed>
     */
    public function eventos(string $id): array
    {
        return $this->http->request('GET', '/documentos/' . rawurlencode($id) . '/eventos');
    }

    /** DELETE /documentos/{id}: solo documentos que nunca llegaron a SUNAT (NO_ENVIADA). */
    public function eliminar(string $id): void
    {
        $this->http->request('DELETE', '/documentos/' . rawurlencode($id));
    }

    /** URL pública del archivo (no requiere token). $formato: pdf, ticket, xml o cdr. */
    public function urlArchivo(string $id, string $formato = 'pdf'): string
    {
        if (!isset(self::EXTENSION[$formato])) {
            throw new \InvalidArgumentException('Formato no válido. Usa pdf, ticket, xml o cdr.');
        }

        return $this->http->fileUrl('/documentos/' . rawurlencode($id) . self::EXTENSION[$formato]);
    }

    /** Descarga el PDF (A4 o ticket), el XML firmado o el CDR de SUNAT. Devuelve el contenido binario. */
    public function descargar(string $id, string $formato = 'pdf'): string
    {
        return $this->http->download($this->urlArchivo($id, $formato));
    }
}
