<?php

declare(strict_types=1);

namespace Facturalo\Exception;

/** 401: el token falta, es inválido o fue regenerado. */
class FacturaloAuthenticationException extends FacturaloApiException
{
}
