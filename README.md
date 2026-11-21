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

## Exonerada en la Amazonía (Ley 27037)

Empresas de la Amazonía que venden para consumo en la misma región: ítems con `tipoIgv` `20` y `amazonia`, que agrega la leyenda de SUNAT (`bienes` → 2001, `servicios` → 2002, `construccion` → 2003). Sirve en facturas y boletas.

```php
$envio = $facturalo->documentos->enviar(array_merge($documento, [
    'amazonia' => 'bienes',
    'items' => [
        ['unidadMedida' => 'NIU', 'descripcion' => 'ARROZ COSTEÑO 5KG', 'cantidad' => 10, 'precioUnitario' => 25, 'tipoIgv' => '20'],
    ],
]));
```

Sin ningún ítem exonerado la API responde 422 (SUNAT lo rechazaría con 3283/3284/3285).

## Percepción, retención, otros cargos y dólares

```php
// Percepción del IGV (51 = 2%, 52 = 1%, 53 = 0.5%): solo al contado, en facturas y boletas.
// Facturalo pone la operación 2001 y calcula la percepción en soles.
$facturalo->documentos->enviar(array_merge($documento, ['percepcion' => ['tipo' => '51']]));

// Retención del IGV (código 62, 3%): solo facturas a un cliente con RUC.
$facturalo->documentos->enviar(array_merge($documento, ['retencion' => ['tipo' => '62', 'porcentaje' => 3]]));

// Otros cargos globales (código 50): no forman parte de la base del IGV y se suman al total.
$facturalo->documentos->enviar(array_merge($documento, ['totalOtrosCargos' => 15]));

// En dólares con bolsas (ICBPER) o percepción, envía el tipo de cambio.
$facturalo->documentos->enviar(array_merge($documento, ['moneda' => 'USD', 'tipoCambio' => 3.75]));
```

- Percepción, retención y detracción no se combinan entre sí: la API responde 422.
- Al crédito con cliente RUC, las `cuotas` deben sumar total + ICBPER + otros cargos − anticipos − detracción − retención.
- `tipoIgv` `17` es IVAP (arroz pilado, 4%): ese comprobante solo lleva ítems con IVAP.
- El ISC todavía no se emite por la API: `totalIsc` mayor a 0 responde 422.

## Sin esperar a SUNAT

```php
$envio = $facturalo->documentos->enviar(
    $documento,
    webhook: true,
    idempotencyKey: 'orden-1042', // evita duplicar si reintentas el POST
);
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
file_put_contents('F001-1.pdf', $facturalo->documentos->descargar($id, 'pdf')); // pdf | ticket (80 mm) | ticket58 (58 mm) | xml | cdr

// Guías de remisión (serie T... remitente, V... transportista)
$facturalo->guias->enviar($guia, webhook: false);
$facturalo->guias->consultar($id);
$facturalo->guias->consultarTicket($ticket);
$facturalo->guias->listar(['pagina' => 1]);

// Consultas
$facturalo->consultas->ruc('20601476551');
$facturalo->consultas->dni('12345678');
$facturalo->consultas->tipoCambio('2026-10-30');
// Código de producto SUNAT para un ítem, por su descripción
$facturalo->consultas->codigoProductoSunat('laptop core i5', limite: 3);

// Webhooks enviados
$facturalo->webhooks->listar(['estado' => 'error']);
$facturalo->webhooks->reenviar($eventoId);
// Si tu endpoint no responde 2xx, Facturalo reintenta el webhook hasta 5 veces.

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
| `FacturaloPermissionException` | 403: el token no puede hacer esto. `getErrorCode()` dice por qué (p. ej. `PLAN_SIN_API`) |
| `FacturaloNotFoundException` | 404: no existe en este entorno |
| `FacturaloRateLimitException` | 429 |
| `FacturaloConnectionException` | Red o timeout (default 90 s) |
| `FacturaloApiException` | Clase base de las HTTP, con `getStatus()`, `getErrorCode()` y `getBody()`. Los 402 de plan llegan como esta clase |

Errores de plan (`getErrorCode()`, con constantes en `FacturaloApiException`):

| Código | HTTP | Qué significa |
|---|---|---|
| `PLAN_SIN_API` | 403 | El plan Gratis no incluye la API de producción (`sk_live_`). Está incluida en los planes de pago de Facturalo ERP (Básico, Pro, Negocio) y en los de Facturalo API. En demo (`sk_test_`) la API funciona en todos los planes |
| `PLAN_SOLO_API` | 403 | Tu plan API no incluye los módulos de negocio (inventario, compras, cajas...) |
| `CLAVE_SOLO_WOOCOMMERCE` | 403 | La clave del plugin de WooCommerce solo sirve para emitir y consultar un comprobante |
| `BILLING_REQUIRED` / `BILLING_SUSPENDED` | 402 | Sin plan activo, o pendiente de pago |
| `QUOTA_EXCEEDED` / `SALDO_INSUFICIENTE` | 402 | Se usó el cupo del mes y no hay saldo |
| `PLAN_SIN_GUIAS` | 402 | El plan no incluye guías de remisión |
| `PLAN_NO_DISPONIBLE` | 422 | Una empresa con plan de Facturalo ERP intentó contratar un plan de Facturalo API (su plan de pago ya incluye la API) |

```php
use Facturalo\Exception\FacturaloApiException;
use Facturalo\Exception\FacturaloPermissionException;

try {
    $facturalo->documentos->enviar($documento);
} catch (FacturaloPermissionException $e) {
    if ($e->getErrorCode() === FacturaloApiException::PLAN_SIN_API) {
        // Elige un plan Básico, Pro o Negocio en console.facturalo.pe/plan/planes
    }
    throw $e;
}
```

Las consultas (`GET`) se reintentan solas ante 429/5xx y fallos de red. Los envíos nunca se reintentan automáticamente, para no emitir dos veces el mismo comprobante.

## Licencia

MIT © Consultia Digital S.A.C. (Facturalo)
