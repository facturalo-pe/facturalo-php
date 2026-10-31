<?php

declare(strict_types=1);

namespace Facturalo\Resource;

use Facturalo\HttpClient;

/**
 * Historial de webhooks enviados a tu URL. La URL y los eventos se configuran con
 * FacturaloCuentaClient; para verificar la firma usa \Facturalo\Webhook.
 */
final class Webhooks
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * GET /webhooks
     *
     * @param array{pagina?: int, limite?: int, estado?: 'ok'|'error', evento?: string} $filtros
     * @return array{total: int, pagina: int, porPagina: int, webhooks: array<int, array<string, mixed>>}
     */
    public function listar(array $filtros = []): array
    {
        return $this->http->request('GET', '/webhooks', null, [
            'page' => $filtros['pagina'] ?? null,
            'limite' => $filtros['limite'] ?? null,
            'estado' => $filtros['estado'] ?? null,
            'evento' => $filtros['evento'] ?? null,
        ]);
    }

    /**
     * POST /webhooks/{eventoId}/reenviar: vuelve a enviar un webhook (los fallidos no se reintentan solos).
     *
     * @return array{evento: string, documentoId: string|null}
     */
    public function reenviar(string $eventoId): array
    {
        return $this->http->request('POST', '/webhooks/' . rawurlencode($eventoId) . '/reenviar');
    }
}
