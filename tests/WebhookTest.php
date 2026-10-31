<?php

declare(strict_types=1);

namespace Facturalo\Tests;

use Facturalo\Exception\FacturaloSignatureVerificationException;
use Facturalo\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_1234567890';

    private static function body(): string
    {
        // Igual que el backend: json_encode del payload, firmado con hash_hmac sobre ese string.
        return (string) json_encode([
            'id' => 'evt-1',
            'evento' => 'documento.sunat.respuesta',
            'timestamp' => '2026-10-30T10:30:00-05:00',
            'entorno' => 'demo',
            'documento' => ['id' => 'uuid-1', 'estado' => 'ACEPTADA', 'serie' => 'F001', 'correlativo' => '00000001', 'tipo' => 'FACTURA'],
            'enlaces' => ['pdf' => 'https://api.facturalo.pe/api/v3/documentos/uuid-1.pdf'],
        ]);
    }

    public function testAceptaUnaFirmaCalculadaComoEnElServidor(): void
    {
        $body = self::body();
        Webhook::verificarFirma($body, hash_hmac('sha256', $body, self::SECRET), self::SECRET);
        $this->addToAssertionCount(1);
    }

    public function testRechazaOtroSecreto(): void
    {
        $body = self::body();
        $this->expectException(FacturaloSignatureVerificationException::class);
        Webhook::verificarFirma($body, hash_hmac('sha256', $body, 'otro'), self::SECRET);
    }

    public function testRechazaUnCuerpoAlterado(): void
    {
        $body = self::body();
        $firma = hash_hmac('sha256', $body, self::SECRET);
        $this->expectException(FacturaloSignatureVerificationException::class);
        Webhook::verificarFirma(str_replace('ACEPTADA', 'RECHAZADA', $body), $firma, self::SECRET);
    }

    public function testRechazaSiFaltaLaFirma(): void
    {
        $this->expectException(FacturaloSignatureVerificationException::class);
        $this->expectExceptionMessage('X-Facturalo-Firma');
        Webhook::verificarFirma(self::body(), null, self::SECRET);
    }

    public function testConstruirEventoDevuelveElArray(): void
    {
        $body = self::body();
        $evento = Webhook::construirEvento($body, hash_hmac('sha256', $body, self::SECRET), self::SECRET);
        self::assertSame('documento.sunat.respuesta', $evento['evento']);
        self::assertSame('ACEPTADA', $evento['documento']['estado']);
    }
}
