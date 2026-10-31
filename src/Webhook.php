<?php

declare(strict_types=1);

namespace Facturalo;

use Facturalo\Exception\FacturaloSignatureVerificationException;

final class Webhook
{
    private function __construct()
    {
    }

    /**
     * Verifica la firma de un webhook de Facturalo: recalcula hash_hmac('sha256', $payload, $secret)
     * y lo compara en tiempo constante con el header X-Facturalo-Firma.
     *
     * Usa el cuerpo crudo tal como llegó (file_get_contents('php://input') o $request->getContent()
     * en Laravel), nunca el array ya decodificado.
     *
     * @param string $payload Cuerpo crudo del request.
     * @param string|null $firma Valor del header X-Facturalo-Firma.
     * @param string $secret El secreto del webhook de la empresa (whsec_...), distinto por entorno.
     * @throws FacturaloSignatureVerificationException Si falta la firma o no coincide.
     */
    public static function verificarFirma(string $payload, ?string $firma, string $secret): void
    {
        if ($firma === null || trim($firma) === '') {
            throw new FacturaloSignatureVerificationException('Falta el header X-Facturalo-Firma.');
        }
        $esperada = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($esperada, strtolower(trim($firma)))) {
            throw new FacturaloSignatureVerificationException('La firma del webhook no coincide.');
        }
    }

    /**
     * Verifica la firma y devuelve el evento decodificado.
     *
     * @return array<string, mixed> id, evento, timestamp, entorno y los bloques del evento (documento, sunat...).
     * @throws FacturaloSignatureVerificationException
     */
    public static function construirEvento(string $payload, ?string $firma, string $secret): array
    {
        self::verificarFirma($payload, $firma, $secret);
        $evento = json_decode($payload, true);
        if (!is_array($evento)) {
            throw new FacturaloSignatureVerificationException('El cuerpo del webhook no es JSON válido.');
        }

        return $evento;
    }
}
