# facturalo-pe/facturalo-php

SDK oficial de [Facturalo](https://www.facturalo.pe) para PHP. Emite facturas, boletas, notas de crédito y débito y guías de remisión ante SUNAT, consulta RUC y DNI, trabaja el SIRE y verifica los webhooks. Funciona en cualquier proyecto PHP 8.1+, incluido Laravel.

Documentación completa de la API: [docs.facturalo.pe](https://docs.facturalo.pe).

```bash
composer require facturalo-pe/facturalo-php
```

## Primera factura

Crea tu empresa en [console.facturalo.pe](https://console.facturalo.pe) y copia su token Demo (`sk_test_...`) desde el menú de la empresa, opción **Tokens**. El token decide el entorno: `sk_test_...` va a SUNAT beta y `sk_live_...` a producción.

```php
use Facturalo\FacturaloClient;

$facturalo = new FacturaloClient(getenv('FACTURALO_TOKEN'));

$envio = $facturalo->documentos->enviar([
    'sunatTransaccion' => '0101',
    'tipoComprobante' => '01',
    'serie' => 'F001',
    'correlativo' => '1',
    'fechaEmision' => '2026-10-30',
    'moneda' => 'PEN',
    'formaPago' => 'CONTADO',
    'cliente' => [
        'tipoDocumento' => '6',
        'numeroDocumento' => '20000000001',
        'denominacion' => 'EMPRESA S.A.C.',
        'direccion' => 'AV. LIMA 123 - LIMA',
    ],
    'items' => [
        // precioUnitario incluye IGV; Facturalo calcula el IGV y los totales.
        ['unidadMedida' => 'NIU', 'descripcion' => 'SERVICIO DE CONSULTORÍA', 'cantidad' => 1, 'precioUnitario' => 118, 'tipoIgv' => '10'],
    ],
]);

if ($envio['estado'] === 'ACEPTADA') {
    echo $envio['enlaces']['pdf'];
} else {
    echo $envio['estado'] . ': ' . ($envio['sunat']['mensaje'] ?? '');
}
```

Un rechazo de SUNAT no lanza excepción: llega con `estado` `RECHAZADA` y el motivo en `sunat`. Las excepciones son para errores de la petición (validación, token, red).

La respuesta solo identifica el comprobante. El detalle completo (cliente, totales, ítems) se obtiene con `$facturalo->documentos->consultar($id)`.

## Sin esperar a SUNAT

```php
$envio = $facturalo->documentos->enviar($documento, webhook: true);
// $envio['estado'] === 'EN_PROCESO': el resultado llega a tu webhook como documento.sunat.respuesta
```

## Recursos

```php
// Documentos (factura, boleta, notas)
$facturalo->documentos->enviar($documento, webhook: false, establecimiento: null);
$facturalo->documentos->generarXml($documento);              // firma el XML sin enviarlo
$facturalo->documentos->consultar($id);                      // detalle completo + estado
$facturalo->documentos->buscar('01', 'F001', '1');
$facturalo->documentos->listar(['fechaInicio' => '2026-10-01', 'estado' => 'ACEPTADA', 'pagina' => 1]);
$facturalo->documentos->anular('01', 'F001', '1', 'Error en el monto');
$facturalo->documentos->reenviar($id);
$facturalo->documentos->eventos($id);
$facturalo->documentos->eliminar($id);                       // solo NO_ENVIADA
file_put_contents('F001-1.pdf', $facturalo->documentos->descargar($id, 'pdf')); // pdf | ticket | xml | cdr

// Guías de remisión (serie T... remitente, V... transportista)
$facturalo->guias->enviar($guia, webhook: false);
$facturalo->guias->consultar($id);
$facturalo->guias->consultarTicket($ticket);
$facturalo->guias->listar(['pagina' => 1]);

// Consultas
$facturalo->consultas->ruc('20601476551');
$facturalo->consultas->dni('12345678');
$facturalo->consultas->tipoCambio('2026-10-30');

// Webhooks enviados
$facturalo->webhooks->listar(['estado' => 'error']);
$facturalo->webhooks->reenviar($eventoId);

// SIRE (periodo YYYYMM)
$facturalo->sire->ventas->resumen('202610');
$facturalo->sire->compras->comprobantes('202610', ['page' => 1, 'perPage' => 100]);
```

## Recibir webhooks

Facturalo firma cada webhook con `hash_hmac('sha256', $cuerpo, $secret)` en el header `X-Facturalo-Firma`. Verifica siempre con el **cuerpo crudo**:

```php
use Facturalo\Exception\FacturaloSignatureVerificationException;
use Facturalo\Webhook;

// Laravel
Route::post('/webhooks/facturalo', function (Request $request) {
    try {
        $evento = Webhook::construirEvento(
            $request->getContent(),
            $request->header('X-Facturalo-Firma'),
            config('services.facturalo.webhook_secret'),
        );
    } catch (FacturaloSignatureVerificationException) {
        abort(401);
    }

    if ($evento['evento'] === 'documento.sunat.respuesta') {
        // $evento['documento']['id'], $evento['sunat']['estado'], $evento['enlaces']['pdf']...
    }

    return response()->noContent();
});
```

En PHP sin framework usa `file_get_contents('php://input')` y `$_SERVER['HTTP_X_FACTURALO_FIRMA']`. El secreto (`whsec_...`) está en la consola, el menú de la empresa, opción **Webhooks**, y es distinto en Demo y Live.

## Cuenta: empresas y establecimientos

Con el token de usuario (`user_sk_...`) puedes dar de alta empresas desde tu propio software:

```php
use Facturalo\FacturaloCuentaClient;

$cuenta = new FacturaloCuentaClient(getenv('FACTURALO_USER_TOKEN'));
$empresa = $cuenta->empresas->crear([
    'ruc' => $ruc, 'nombre' => $nombre, 'direccion' => $direccion,
    'departamento' => $departamento, 'provincia' => $provincia, 'distrito' => $distrito,
    'codigoPostal' => $ubigeo,
    'sunat' => ['solUsuario' => $usuarioSol, 'solClave' => $claveSol],
]);
$cuenta->empresas->actualizarWebhook($empresa['id'], ['demo' => 'https://mi-sistema.pe/webhooks/facturalo']);
```

## Errores

| Excepción | Cuándo |
|---|---|
| `FacturaloValidationException` | 400 / 422. `getErrors()` trae los errores por campo |
| `FacturaloAuthenticationException` | 401: token inválido o regenerado |
| `FacturaloNotFoundException` | 404: no existe en este entorno |
| `FacturaloRateLimitException` | 429 |
| `FacturaloConnectionException` | Red o timeout (default 90 s) |
| `FacturaloApiException` | Clase base de las HTTP, con `getStatus()` y `getBody()` |

Las consultas (`GET`) se reintentan solas ante 429/5xx y fallos de red. Los envíos nunca se reintentan automáticamente, para no emitir dos veces el mismo comprobante.

## Licencia

MIT © Consultia Digital S.A.C. (Facturalo)
