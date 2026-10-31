<?php

declare(strict_types=1);

namespace Facturalo\Resource;

use Facturalo\HttpClient;

/** SIRE: registro de ventas (RVIE) y de compras (RCE). */
final class Sire
{
    public readonly LibroSire $ventas;
    public readonly LibroSire $compras;

    public function __construct(private readonly HttpClient $http)
    {
        $this->ventas = new LibroSire($http, 'ventas');
        $this->compras = new LibroSire($http, 'compras');
    }

    /**
     * Estado de una conciliación iniciada con ventas->conciliar() o compras->conciliar().
     *
     * @return array<string, mixed>
     */
    public function conciliacion(string $conciliacionId): array
    {
        return $this->http->request('GET', '/sire/conciliaciones/' . rawurlencode($conciliacionId));
    }
}
