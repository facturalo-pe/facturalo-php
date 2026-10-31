<?php

declare(strict_types=1);

namespace Facturalo\Exception;

/** Fallo de red o timeout: no hubo respuesta HTTP de Facturalo. */
class FacturaloConnectionException extends \RuntimeException
{
}
