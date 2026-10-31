<?php

declare(strict_types=1);

namespace Facturalo\Exception;

/** La firma del webhook (X-Facturalo-Firma) no coincide con el cuerpo recibido. */
class FacturaloSignatureVerificationException extends \RuntimeException
{
}
