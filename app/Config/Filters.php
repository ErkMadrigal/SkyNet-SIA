<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\SecureHeaders;

class Filters extends BaseConfig
{
    public array $always = [
        'before' => [],
        'after'  => [],
    ];

    public array $aliases = [
        'csrf'          => CSRF::class,
        'toolbar'       => DebugToolbar::class,
        'honeypot'      => Honeypot::class,
        'invalidchars'  => InvalidChars::class,
        'secureheaders' => SecureHeaders::class,
        'jwt'           => \App\Filters\JwtFilter::class,
        'apikey'        => \App\Filters\ApiKeyFilter::class,
    ];

    // ── Sin filtros requeridos globales ──────────────────────────────────
    // InvalidChars causaba el bug en CI4 4.7.2 retornando Response object
    // en rutas no encontradas. El toolbar solo aplica en HTML, no en JSON.
    public array $required = [
        'before' => [],
        'after'  => [],
    ];

    public array $filters = [];
}