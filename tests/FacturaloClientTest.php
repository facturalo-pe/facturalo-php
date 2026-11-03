<?php

declare(strict_types=1);

namespace Facturalo\Tests;

use Facturalo\Exception\FacturaloAuthenticationException;
use Facturalo\Exception\FacturaloConnectionException;
use Facturalo\Exception\FacturaloNotFoundException;
use Facturalo\Exception\FacturaloValidationException;
use Facturalo\FacturaloClient;
use Facturalo\FacturaloCuentaClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class FacturaloClientTest extends TestCase
{
    private const TOKEN = 'sk_test_abc123';
    private const BASE = 'https://example.test/api/v3';

    /**
     * @param array<int, Response|\Throwable> $responses
     * @param array<int, array{request: RequestInterface}> $history Se llena conforme Guzzle ejecuta.
     */
    private function guzzle(array $responses, array &$history = []): GuzzleClient
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new GuzzleClient(['handler' => $stack]);
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    /** @return array<string, mixed> */
    private static function factura(): array
    {
        return [
            'sunatTransaccion' => '0101',
            'tipoComprobante' => '01',
            'serie' => 'F001',
            'correlativo' => '1',
            'fechaEmision' => '2026-10-30',
            'moneda' => 'PEN',
            'formaPago' => 'CONTADO',
            'cliente' => ['tipoDocumento' => '6', 'numeroDocumento' => '20000000001', 'denominacion' => 'EMPRESA S.A.C.', 'direccion' => 'AV. LIMA 123'],
            'items' => [['unidadMedida' => 'NIU', 'descripcion' => 'SERVICIO', 'cantidad' => 1, 'precioUnitario' => 118, 'tipoIgv' => '10']],
        ];
    }

    public function testRechazaUnTokenQueNoEsDeEmpresa(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FacturaloClient('user_sk_abc');
    }

    public function testDeduceElEntornoDelToken(): void
    {
        self::assertSame('demo', (new FacturaloClient('sk_test_x'))->entorno);
        self::assertSame('prod', (new FacturaloClient('sk_live_x'))->entorno);
    }

    public function testEnviaElTokenSinBearerYElDocumentoEnvuelto(): void
    {
        $history = [];
        $guzzle = $this->guzzle([self::json(200, [
            'success' => true,
            'message' => 'La Factura numero F001-00000001 ha sido aceptada',
            'data' => [
                'estado' => 'ACEPTADA',
                'entorno' => 'demo',
                'documento' => ['id' => 'uuid-1', 'estado' => 'ACEPTADA', 'serie' => 'F001', 'correlativo' => '00000001', 'tipo' => 'FACTURA'],
                'sunat' => ['estado' => 'ACEPTADA', 'codigo' => '0', 'mensaje' => 'ok', 'notas' => []],
            ],
        ])], $history);

        $envio = (new FacturaloClient(self::TOKEN, self::BASE, $guzzle))->documentos->enviar(self::factura());

        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE . '/documentos/enviar', (string) $request->getUri());
        self::assertSame(self::TOKEN, $request->getHeaderLine('Authorization'));
        self::assertSame(['webhook' => false, 'documento' => self::factura()], json_decode((string) $request->getBody(), true));
        self::assertSame('ACEPTADA', $envio['estado']);
        self::assertSame('00000001', $envio['documento']['correlativo']);
    }

    public function testEnviaIdempotencyKeyAlEmitir(): void
    {
        $history = [];
        $guzzle = $this->guzzle([self::json(200, [
            'success' => true,
            'message' => 'ok',
            'data' => [
                'estado' => 'ACEPTADA',
                'entorno' => 'demo',
                'documento' => ['id' => 'uuid-1', 'estado' => 'ACEPTADA', 'serie' => 'F001', 'correlativo' => '00000001', 'tipo' => 'FACTURA'],
                'sunat' => ['estado' => 'ACEPTADA', 'codigo' => '0', 'mensaje' => 'ok', 'notas' => []],
            ],
        ])], $history);

        (new FacturaloClient(self::TOKEN, self::BASE, $guzzle))->documentos->enviar(
            self::factura(),
            false,
            null,
            'orden-1042',
        );

        self::assertSame('orden-1042', $history[0]['request']->getHeaderLine('Idempotency-Key'));
    }

    public function testUnRechazoDeSunatNoLanza(): void
    {
        $guzzle = $this->guzzle([self::json(200, [
            'success' => false,
            'message' => 'rechazada',
            'data' => ['estado' => 'RECHAZADA', 'sunat' => ['codigo' => '2017', 'mensaje' => 'x', 'notas' => []]],
        ])]);

        $envio = (new FacturaloClient(self::TOKEN, null, $guzzle))->documentos->enviar(self::factura());

        self::assertSame('RECHAZADA', $envio['estado']);
        self::assertSame('2017', $envio['sunat']['codigo']);
    }

    public function testMapea422AValidacionConErroresPorCampo(): void
    {
        $guzzle = $this->guzzle([self::json(422, [
            'success' => false,
            'message' => 'Los datos enviados no son válidos.',
            'errors' => ['documento.items' => ['El campo items es requerido.']],
        ])]);

        try {
            (new FacturaloClient(self::TOKEN, null, $guzzle))->documentos->enviar(self::factura());
            self::fail('Debió lanzar');
        } catch (FacturaloValidationException $e) {
            self::assertSame(422, $e->getStatus());
            self::assertSame(['El campo items es requerido.'], $e->getErrors()['documento.items']);
        }
    }

    public function testMapea401Y404(): void
    {
        $client = new FacturaloClient(self::TOKEN, null, $this->guzzle([
            self::json(401, ['success' => false, 'message' => 'Token de autorización inválido.']),
        ]));
        try {
            $client->documentos->consultar('x');
            self::fail('Debió lanzar');
        } catch (FacturaloAuthenticationException $e) {
            self::assertSame(401, $e->getStatus());
        }

        $client = new FacturaloClient(self::TOKEN, null, $this->guzzle([
            self::json(404, ['success' => false, 'message' => 'No se encontró', 'data' => []]),
        ]));
        $this->expectException(FacturaloNotFoundException::class);
        $client->documentos->consultar('x');
    }

    public function testReintentaConsultasAnte503PeroNuncaUnEnvio(): void
    {
        $history = [];
        $guzzle = $this->guzzle([
            self::json(503, ['success' => false, 'message' => 'busy']),
            self::json(200, ['success' => true, 'data' => ['estado' => 'ACEPTADA']]),
        ], $history);
        (new FacturaloClient(self::TOKEN, null, $guzzle))->documentos->consultar('uuid-1');
        self::assertCount(2, $history);

        $history = [];
        $guzzle = $this->guzzle([
            self::json(503, ['success' => false, 'message' => 'busy']),
            self::json(200, ['success' => true, 'data' => []]),
        ], $history);
        try {
            (new FacturaloClient(self::TOKEN, null, $guzzle))->documentos->enviar(self::factura());
            self::fail('Debió lanzar');
        } catch (\Facturalo\Exception\FacturaloApiException) {
            self::assertCount(1, $history);
        }
    }

    public function testNoReintentaUnEnvioCuandoFallaLaRed(): void
    {
        $history = [];
        $guzzle = $this->guzzle([
            new ConnectException('down', new Request('POST', 'x')),
            self::json(200, ['success' => true, 'data' => []]),
        ], $history);

        try {
            (new FacturaloClient(self::TOKEN, null, $guzzle))->documentos->enviar(self::factura());
            self::fail('Debió lanzar');
        } catch (FacturaloConnectionException) {
            self::assertCount(1, $history);
        }
    }

    public function testBuscarYListarTraducenLosParametros(): void
    {
        $history = [];
        $guzzle = $this->guzzle([
            self::json(200, ['success' => true, 'data' => []]),
            self::json(200, ['success' => true, 'data' => []]),
        ], $history);
        $client = new FacturaloClient(self::TOKEN, self::BASE, $guzzle);

        $client->documentos->buscar('03', 'B001', '12');
        self::assertSame(self::BASE . '/documentos/buscar?tipo_documento=03&serie=B001&correlativo=12', (string) $history[0]['request']->getUri());

        $client->documentos->listar(['pagina' => 2, 'fechaInicio' => '2026-10-01', 'estado' => 'ACEPTADA']);
        self::assertSame(self::BASE . '/empresas/documentos?page=2&fecha_inicio=2026-10-01&estado=ACEPTADA', (string) $history[1]['request']->getUri());
    }

    public function testAnularEnvuelveElCuerpoEnDocumento(): void
    {
        $history = [];
        $guzzle = $this->guzzle([self::json(200, ['success' => true, 'data' => ['estado' => 'ANULADA']])], $history);

        $anulacion = (new FacturaloClient(self::TOKEN, null, $guzzle))->documentos->anular('01', 'F001', '1', 'Error en el monto');

        self::assertSame(
            ['documento' => ['tipoComprobante' => '01', 'serie' => 'F001', 'correlativo' => '1', 'motivo' => 'Error en el monto']],
            json_decode((string) $history[0]['request']->getBody(), true),
        );
        self::assertSame('ANULADA', $anulacion['estado']);
    }

    public function testArmaLasUrlsPublicasDeArchivos(): void
    {
        $client = new FacturaloClient(self::TOKEN, self::BASE);
        self::assertSame(self::BASE . '/documentos/u1.ticket.pdf', $client->documentos->urlArchivo('u1', 'ticket'));
        self::assertSame(self::BASE . '/guias/g1.cdr', $client->guias->urlArchivo('g1', 'cdr'));
    }

    public function testCuentaExigeTokenDeUsuarioYCreaEmpresa(): void
    {
        try {
            new FacturaloCuentaClient('sk_test_abc');
            self::fail('Debió lanzar');
        } catch (\InvalidArgumentException) {
        }

        $history = [];
        $guzzle = $this->guzzle([self::json(201, [
            'success' => true,
            'data' => ['empresa' => ['id' => 'e1', 'ruc' => '20601476551', 'nombre' => 'MI EMPRESA']],
        ])], $history);

        $empresa = (new FacturaloCuentaClient('user_sk_abc', self::BASE, $guzzle))->empresas->crear([
            'ruc' => '20601476551',
            'nombre' => 'MI EMPRESA',
            'sunat' => ['solUsuario' => 'MODDATOS', 'solClave' => 'moddatos'],
        ]);

        self::assertSame(self::BASE . '/empresas', (string) $history[0]['request']->getUri());
        self::assertSame('e1', $empresa['id']);
    }
}
