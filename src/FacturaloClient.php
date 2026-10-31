<?php

declare(strict_types=1);

namespace Facturalo;

use Facturalo\Resource\Consultas;
use Facturalo\Resource\Documentos;
use Facturalo\Resource\Guias;
use Facturalo\Resource\Sire;
use Facturalo\Resource\Webhooks;
use GuzzleHttp\ClientInterface;

/**
 * Cliente con el token de una empresa (sk_test_... demo / sk_live_... producción). Emite y consulta
 * comprobantes y guías, consulta RUC/DNI, revisa los webhooks enviados y trabaja el SIRE.
 *
 * ```php
 * $facturalo = new FacturaloClient(getenv('FACTURALO_TOKEN'));
 * $envio = $facturalo->documentos->enviar([...]);
 * ```
 */
final class FacturaloClient
{
    private const TOKEN_PREFIXES = ['sk_test_', 'sk_live_'];

    /** 'demo' o 'prod', según el token. */
    public readonly string $entorno;
    public readonly Documentos $documentos;
    public readonly Guias $guias;
    public readonly Consultas $consultas;
    public readonly Webhooks $webhooks;
    public readonly Sire $sire;

    /**
     * @param string $token Token de la empresa. El token decide el entorno.
     * @param string|null $baseUrl Solo para apuntar a otra URL. Default https://api.facturalo.pe/api/v3
     * @param ClientInterface|null $httpClient Cliente Guzzle propio (proxy, pruebas...).
     * @param float $timeoutSeconds Default 90 s: el envío síncrono espera la respuesta de SUNAT.
     */
    public function __construct(
        string $token,
        ?string $baseUrl = null,
        ?ClientInterface $httpClient = null,
        float $timeoutSeconds = 90,
    ) {
        $valido = false;
        foreach (self::TOKEN_PREFIXES as $prefix) {
            if (str_starts_with($token, $prefix)) {
                $valido = true;
                break;
            }
        }
        if (!$valido) {
            throw new \InvalidArgumentException(
                'FacturaloClient espera el token de una empresa (sk_test_... / sk_live_...). '
                . 'Para el token de usuario (user_sk_...) usa FacturaloCuentaClient.',
            );
        }

        $http = new HttpClient($token, $baseUrl, $httpClient, $timeoutSeconds);
        $this->entorno = str_starts_with($token, 'sk_live_') ? 'prod' : 'demo';
        $this->documentos = new Documentos($http);
        $this->guias = new Guias($http);
        $this->consultas = new Consultas($http);
        $this->webhooks = new Webhooks($http);
        $this->sire = new Sire($http);
    }
}
