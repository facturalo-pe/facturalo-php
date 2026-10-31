<?php

declare(strict_types=1);

namespace Facturalo\Exception;

/** 400 / 422: la petición no pasó validación o la operación no procede. Revisa getErrors(). */
class FacturaloValidationException extends FacturaloApiException
{
}
