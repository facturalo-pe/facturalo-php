<?php

declare(strict_types=1);

namespace Facturalo\Exception;

/** 403: el token es válido pero no puede hacer esto. */
class FacturaloPermissionException extends FacturaloApiException
{
}
