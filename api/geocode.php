
<?php
// ======================================================
// API GEOCODE - JURNAL KERJA PKL
// Reverse Geocoding menggunakan OpenStreetMap Nominatim
// ======================================================

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ======================================================
// KONFIGURASI
// ======================================================

$cacheDuration = 6 * 60 * 60; // Cache 6 jam
$minimumInterval = 1.1;       // Jeda minimal request per sesi
$cacheLimit = 40;

// ======================================================
// RESPONSE JSON
// ======================================================

function geoResponse(
    string $status,
    string $message = '',
    array $data = [],
    int $httpCode = 200
): never {
    http_response_code($httpCode);

    echo json_encode(
        array_merge([
            'status' => $status,
            'message' => $message,
            'display_name' => ''
        ], $data),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

// ======================================================
// VALIDASI METODE REQUEST
// ======================================================

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');

    geoResponse(
        'error',
        'Metode request tidak diizinkan.',
        [],
        405
    );
}

// ======================================================
// VALIDASI KOORDINAT
// ======================================================

$latInput = $_GET['lat'] ?? null;
$lngInput = $_GET['lng'] ?? null;

if (
    !is_scalar($latInput) ||
    !is_scalar($lngInput) ||
    !is_numeric($latInput) ||
    !is_numeric($lngInput)
) {
    geoResponse(
        'error',
        'Koordinat tidak valid.',
        [],
        422
    );
}

$lat = (float) $latInput;
$lng = (float) $lngInput;

if (
    !is_finite($lat) ||
    !is_finite($lng) ||
    $lat < -90 ||
    $lat > 90 ||
    $lng < -180 ||
    $lng > 180
) {
    geoResponse(
        'error',
        'Latitude atau longitude berada di luar batas.',
        [],
        422
    );
}

// Presisi koordinat untuk cache
$lat = round($lat, 5);
$lng = round($lng, 5);

// ======================================================
// CACHE SESSION
// ======================================================

if (!isset($_SESSION['geo_cache']) || !is_array($_SESSION['geo_cache'])) {
    $_SESSION['geo_cache'] = [];
}

$now = time();
$cacheKey = md5($lat . '|' . $lng);

// Bersihkan cache kedaluwarsa
foreach ($_SESSION['geo_cache'] as $key => $item) {
    if (
        !is_array($item) ||
        !isset($item['time']) ||
        ($now - (int) $item['time']) > $cacheDuration
    ) {
        unset($_SESSION['geo_cache'][$key]);
    }
}

// Ambil cache jika tersedia
if (isset($_SESSION['geo_cache'][$cacheKey])) {
    $cached = $_SESSION['geo_cache'][$cacheKey];

    geoResponse(
        'success',
        'Alamat berhasil diambil dari cache.',
        $cached['data'] ?? []
    );
}

// ======================================================
// PEMBATASAN REQUEST
// ======================================================

$lastRequest = (float) ($_SESSION['geo_last_request'] ?? 0);

if (($now + microtime() - $lastRequest) < $minimumInterval) {
    geoResponse(
        'error',
        'Tunggu sebentar sebelum meminta lokasi berikutnya.',
        [],
        429
    );
}

// Simpan waktu request
$_SESSION['geo_last_request'] = microtime(true);

// ======================================================
// REQUEST NOMINATIM
// ======================================================

$params = http_build_query([
    'format' => 'jsonv2',
    'lat' => $lat,
    'lon' => $lng,
    'addressdetails' => 1,
    'accept-language' => 'id'
]);

$url = 'https://nominatim.openstreetmap.org/reverse?' . $params;

$appName = defined('APP_NAME') ? APP_NAME : 'JurnalPKL';

$userAgent = preg_replace(
    '/[^a-zA-Z0-9 ._-]/',
    '',
    $appName
);

$userAgent = trim($userAgent) ?: 'JurnalPKL';

$userAgent .= '/1.1';

// ======================================================
// EKSEKUSI CURL
// ======================================================

$response = false;
$httpCode = 0;

if (function_exists('curl_init')) {

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Accept-Language: id'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        error_log('Geocode cURL error: ' . curl_error($ch));
    }

    curl_close($ch);

// ======================================================
// FALLBACK STREAM CONTEXT
// ======================================================

} else {

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' =>
                "User-Agent: {$userAgent}\r\n" .
                "Accept: application/json\r\n" .
                "Accept-Language: id\r\n",
            'timeout' => 10,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true
        ]
    ]);

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    if (isset($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) {
                $httpCode = (int) $match[1];
            }
        }
    }
}

// ======================================================
// PENANGANAN HASIL REQUEST
// ======================================================

if (
    $response === false ||
    $httpCode !== 200
) {
    error_log(
        'Geocode request failed. HTTP status: ' . $httpCode
    );

    geoResponse(
        'error',
        'Layanan lokasi sedang tidak tersedia. Silakan coba lagi.',
        [],
        502
    );
}

$data = json_decode($response, true);

if (
    !is_array($data) ||
    empty($data['display_name'])
) {
    geoResponse(
        'error',
        'Alamat tidak ditemukan untuk koordinat tersebut.',
        [],
        404
    );
}

// ======================================================
// FORMAT HASIL
// ======================================================

$result = [
    'display_name' => (string) $data['display_name'],
    'lat' => $data['lat'] ?? (string) $lat,
    'lon' => $data['lon'] ?? (string) $lng,
    'address' => is_array($data['address'] ?? null)
        ? $data['address']
        : []
];

// ======================================================
// SIMPAN CACHE
// ======================================================

$_SESSION['geo_cache'][$cacheKey] = [
    'time' => time(),
    'data' => $result
];

// Batasi jumlah cache
if (count($_SESSION['geo_cache']) > $cacheLimit) {
    uasort(
        $_SESSION['geo_cache'],
        fn($a, $b) => ($a['time'] ?? 0) <=> ($b['time'] ?? 0)
    );

    $_SESSION['geo_cache'] = array_slice(
        $_SESSION['geo_cache'],
        -$cacheLimit,
        null,
        true
    );
}

// ======================================================
// SELESAI
// ======================================================

geoResponse(
    'success',
    'Alamat berhasil ditemukan.',
    $result
);
