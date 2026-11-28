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
- Un ítem gratuito no lleva `descuento`; el descuento debe ser menor que cantidad × precio y no puede dejar el precio neto en 0.00.
- Una línea gravada o IVAP tan pequeña que su impuesto sería 0 responde 422. Con `icbper` (bolsas) la cantidad es entera.
- La factura (01) va a un cliente con RUC, salvo exportación: los ítems {'node': '`tipoIgv: "40"`', 'php': '`tipoIgv` `40`', 'python': '`tipoIgv` `"40"`'} requieren `sunatTransaccion: "0200"`.
- Notas tipo 11 solo con ítems de exportación (40) y tipo 12 solo con IVAP (17). La nota de crédito 13 solo va sobre una factura, con `formaPago: "CREDITO"` y las `cuotas` del nuevo cronograma (se emite con importe 0).
- Cada anticipo tiene `monto` mayor a 0 y su suma no supera el importe del comprobante.

## Guía remitente (09): compra, venta a terceros y otros

Con serie T... la empresa del token es el remitente. Según el motivo van datos extra: `proveedor` es obligatorio con 02 (Compra) y 07 (Recojo de bienes transformados), `comprador` con 03 (Venta con entrega a terceros), y `descripcionMotivo` (máx. 100) con 13 (Otros) si no hay `observaciones`. Ambos llevan `tipoDocumento` (0, 1, 4, 6, 7 o A), `numeroDocumento` y `denominacion`.

```php
// Compra (02): el destinatario es tu propia empresa y va el proveedor.
$guia = [
    'serie' => 'T001',
    'correlativo' => '1',
    'fechaEmision' => '2026-11-28',
    'tipoTransporte' => '02',
    'motivoTraslado' => '02',
    'cliente' => ['tipoDocumento' => '6', 'numeroDocumento' => '20600000001', 'denominacion' => 'TU EMPRESA S.A.C.'],
    'proveedor' => ['tipoDocumento' => '6', 'numeroDocumento' => '20512345678', 'denominacion' => 'PROVEEDOR S.A.C.'],
    'datosTraslado' => ['fecha' => '2026-11-28', 'pesoBrutoTotal' => 130, 'pesoUnidadMedida' => 'KGM', 'numeroBultos' => 7],
    'datosTransportista' => ['numeroPlaca' => 'ABC123'],
    'datosChofer' => ['tipoDocumento' => '1', 'numeroDocumento' => '45678912', 'numeroLicencia' => 'Q45678912',
                      'nombres' => 'CARLOS', 'apellidos' => 'RAMOS TORRES'],
    'puntoPartida' => ['ubigeo' => '150101', 'direccion' => 'AV. ARGENTINA 1234 - LIMA'],
    'puntoLlegada' => ['ubigeo' => '040101', 'direccion' => 'CALLE MERCADERES 456 - AREQUIPA'],
    'items' => [['codigo' => 'P001', 'descripcion' => 'CAJA DE PRODUCTOS', 'unidadMedida' => 'NIU', 'cantidad' => 5]],
];
$facturalo->guias->enviar($guia);

// Venta con entrega a terceros (03): `cliente` es quien recibe y va el comprador.
$base = $guia;
unset($base['proveedor']);
$facturalo->guias->enviar(array_merge($base, [
    'correlativo' => '2',
    'motivoTraslado' => '03',
    'cliente' => ['tipoDocumento' => '6', 'numeroDocumento' => '20605302379', 'denominacion' => 'ALMACEN DESTINO S.A.C.'],
    'comprador' => ['tipoDocumento' => '6', 'numeroDocumento' => '20111111111', 'denominacion' => 'COMPRADOR S.A.C.'],
]));

// Otros (13): describe el motivo.
$facturalo->guias->enviar(array_merge($base, [
    'correlativo' => '3', 'motivoTraslado' => '13', 'descripcionMotivo' => 'TRASLADO A TALLER DE REPARACION',
]));
```

- `proveedor` y `comprador` solo se aceptan en sus motivos. Con 02, 04, 07 y 18 el destinatario (`cliente`) es la propia empresa.
- Los motivos 08 (Importación) y 09 (Exportación) aún no se emiten por la API: responden 422.
- `observaciones` hasta 250 caracteres; la fecha de traslado no puede ser anterior a `fechaEmision`; `pesoBrutoTotal` y cada `cantidad` mayores a 0; `numeroBultos` entero.
- Con transporte público el transportista no puede tener el RUC de tu empresa. Con el indicador `TrasladoVehiculoM1L` no se exigen la placa ni `datosChofer`.

## Guía transportista (31)

La empresa del token es el transportista: van el remitente (dueño de la carga), el destinatario, la placa con su TUC, el conductor y el registro MTC. Si la guía remitente (09) relacionada es electrónica, `items` va vacío: SUNAT toma el detalle de esa guía. `indicadores` acepta `remitente` o `subcontratador` (quién paga el flete) y `traslado_total`.

```php
$facturalo->guias->enviar([
    'serie' => 'V001',
    'correlativo' => '1',
    'fechaEmision' => '2026-11-24',
    'motivoTraslado' => '01',
    'remitente' => ['tipoDocumento' => '6', 'numeroDocumento' => '20512345678', 'denominacion' => 'COMERCIAL REMITENTE S.A.C.'],
    'destinatario' => ['tipoDocumento' => '6', 'numeroDocumento' => '20605302379', 'denominacion' => 'DISTRIBUIDORA DESTINO S.A.C.'],
    'datosTransportista' => ['numeroMTC' => '1500123456'],
    'datosTraslado' => ['fecha' => '2026-11-24', 'pesoBrutoTotal' => 50, 'pesoUnidadMedida' => 'KGM', 'numeroBultos' => 5,
                        'indicadores' => ['remitente']],
    'datosVehiculo' => ['numeroPlaca' => 'ABC123', 'numeroTUC' => '15M21012345678'],
    'datosChofer' => ['tipoDocumento' => '1', 'numeroDocumento' => '45678912', 'numeroLicencia' => 'Q45678912',
                      'nombres' => 'CARLOS', 'apellidos' => 'RAMOS TORRES'],
    'puntoPartida' => ['ubigeo' => '150101', 'direccion' => 'AV. ARGENTINA 1234 - LIMA'],
    'puntoLlegada' => ['ubigeo' => '040101', 'direccion' => 'CALLE MERCADERES 456 - AREQUIPA'],
    'documentosRelacionados' => [['tipoDocumento' => '09', 'numeroDocumento' => 'T001-00000123']],
    'items' => [],
]);
```

- `datosVehiculo.numeroTUC` es obligatorio: TUC, constancia de inscripción vehicular o certificado de habilitación vehicular (10 a 15 letras y números).
- Con una factura relacionada y `traslado_total`, `items` también puede ir vacío.
- Un indicador que no existe responde 422. Lista completa: [docs.facturalo.pe](https://docs.facturalo.pe).

## Validar una guía sin emitirla

`validar` recibe lo mismo que `enviar` (remitente o transportista) y responde si SUNAT la aceptaría: aplica las reglas de la API (422 como `enviar`), arma el XML y corre las reglas de SUNAT, sin usar el correlativo, guardar ni enviar nada.

```php
$validacion = $facturalo->guias->validar($guia);
if ($validacion['prediccion'] === 'RECHAZADA') {
    foreach ($validacion['errores'] as $e) {
        echo $e['codigo'], ' ', $e['mensaje'], PHP_EOL;
    }
} else {
    $facturalo->guias->enviar($guia);
}
```

Si las reglas de SUNAT no están disponibles lanza `FacturaloApiException` con `getStatus()` 503: la guía se puede enviar igual.

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
$facturalo->guias->validar($guia);
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
