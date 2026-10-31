<?php

declare(strict_types=1);

namespace Facturalo;

use Facturalo\Resource\Consultas;
use Facturalo\Resource\Empresas;
use Facturalo\Resource\Establecimientos;
use GuzzleHttp\ClientInterface;

/**
 * Cliente con el token de usuario (user_sk_...): administra empresas, sus tokens, webhooks y
 * establecimientos. Útil para software que da de alta empresas de sus clientes.
 */
final class FacturaloCuentaClient
{
    public readonly Empresas $empresas;
    public readonly Establecimientos $establecimientos;
    public readonly Consultas $consultas;

    public function __construct(
        string $token,
        ?string $baseUrl = null,
        ?ClientInterface $httpClient = null,
        float $timeoutSeconds = 90,
    ) {
        if (!str_starts_with($token, 'user_sk_')) {
            throw new \InvalidArgumentException(
                'FacturaloCuentaClient espera el token de usuario (user_sk_...). '
                . 'Para emitir comprobantes usa FacturaloClient con el token de la empresa.',
            );
        }

        $http = new HttpClient($token, $baseUrl, $httpClient, $timeoutSeconds);
        $this->empresas = new Empresas($http);
        $this->establecimientos = new Establecimientos($http);
        $this->consultas = new Consultas($http);
    }
}
