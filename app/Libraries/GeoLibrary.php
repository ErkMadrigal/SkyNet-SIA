<?php

namespace App\Libraries;

/**
 * GeoLibrary -- geocodificación inversa (coordenadas → estado + dirección)
 * con Nominatim (OpenStreetMap). Gratis, pero pide User-Agent propio y no
 * abusar (máx 1 petición/seg). Si falla devuelve [] y el flujo sigue.
 */
class GeoLibrary
{
    private const ESTADOS_MX = [
        'Ciudad de México', 'Aguascalientes', 'Baja California Sur', 'Baja California', 'Campeche', 'Chiapas',
        'Chihuahua', 'Coahuila', 'Colima', 'Durango', 'Guanajuato', 'Guerrero', 'Hidalgo', 'Jalisco',
        'Michoacán', 'Morelos', 'Nayarit', 'Nuevo León', 'Oaxaca', 'Puebla', 'Querétaro', 'Quintana Roo',
        'San Luis Potosí', 'Sinaloa', 'Sonora', 'Tabasco', 'Tamaulipas', 'Tlaxcala', 'Veracruz', 'Yucatán',
        'Zacatecas', 'México',
    ];

    /** @return array{estado?: ?string, direccion?: ?string} */
    public static function reverse(float $lat, float $lon): array
    {
        try {
            $client = \Config\Services::curlrequest(['timeout' => 6, 'http_errors' => false]);
            $resp   = $client->get('https://nominatim.openstreetmap.org/reverse', [
                'headers' => [
                    'User-Agent'      => 'SIAG-Camaras/1.0 (serprosep.vinculasag.com)',
                    'Accept-Language' => 'es',
                ],
                'query' => [
                    'format' => 'jsonv2', 'lat' => $lat, 'lon' => $lon,
                    'zoom' => 18, 'addressdetails' => 1,
                ],
            ]);
            if ($resp->getStatusCode() !== 200) return [];

            $json = json_decode($resp->getBody(), true);
            $a    = $json['address'] ?? null;
            if (!$a) return [];

            $estado = null;
            $crudo  = (string)($a['state'] ?? $a['region'] ?? '');
            if ($crudo !== '') {
                $estado = $crudo;
                foreach (self::ESTADOS_MX as $e) {
                    if (mb_stripos($crudo, $e) !== false) { $estado = $e; break; }
                }
                if (mb_stripos($crudo, 'Distrito Federal') !== false) $estado = 'Ciudad de México';
            }

            $calle = trim(($a['road'] ?? $a['pedestrian'] ?? '') . ' ' . ($a['house_number'] ?? ''));
            $col   = $a['suburb'] ?? $a['neighbourhood'] ?? $a['quarter'] ?? '';
            $muni  = $a['city'] ?? $a['town'] ?? $a['village'] ?? $a['municipality'] ?? $a['county'] ?? '';
            $partes = array_values(array_filter([$calle, $col, $muni], fn($p) => trim((string)$p) !== ''));

            return ['estado' => $estado, 'direccion' => $partes ? implode(', ', $partes) : null];
        } catch (\Throwable $e) {
            log_message('error', 'Geocodificación falló: ' . $e->getMessage());
            return [];
        }
    }
}
