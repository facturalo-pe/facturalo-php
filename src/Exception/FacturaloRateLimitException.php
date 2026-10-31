<?php

declare(strict_types=1);

namespace Facturalo\Exception;

/** 429: demasiadas peticiones. El SDK ya reintenta las consultas antes de lanzar esto. */
class FacturaloRateLimitException extends FacturaloApiException
{
}
