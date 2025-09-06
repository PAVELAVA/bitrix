<?php
// CLI: расчёт расстояния без загрузки ядра Битрикс

const UF_FROM     = 'UF_CRM_5F1BFC2852C3C';
const UF_TO       = 'UF_CRM_5F1BFC285BA35';
const UF_DISTANCE = 'UF_CRM_1756877045179';

// ---- фолбэки для отсутствующих констант cURL ----
if (!defined('CURL_HTTP_VERSION_1_1')) define('CURL_HTTP_VERSION_1_1', 2);
if (!defined('CURL_IPRESOLVE_V4'))     define('CURL_IPRESOLVE_V4', 1);
if (!defined('CURL_HTTP_VERSION_2TLS')) {
    if (defined('CURL_HTTP_VERSION_2_0')) define('CURL_HTTP_VERSION_2TLS', CURL_HTTP_VERSION_2_0);
    else                                  define('CURL_HTTP_VERSION_2TLS', CURL_HTTP_VERSION_1_1);
}

$dealId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($dealId <= 0) { echo json_encode(['error'=>'BAD_DEAL_ID']); exit(1); }

$_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www";
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/php_interface/dbconn.php";
$mysqli = @mysqli_connect($DBHost, $DBLogin, $DBPassword, $DBName);
if (!$mysqli) { echo json_encode(['error'=>'DB_CONNECT_FAIL','msg'=>mysqli_connect_error()], JSON_UNESCAPED_UNICODE); exit(1); }
$mysqli->set_charset('utf8mb4');

// читаем адреса
$dealIdEsc = (int)$dealId;
$sql = "SELECT ".UF_FROM." AS UF_FROM, ".UF_TO." AS UF_TO FROM b_uts_crm_deal WHERE VALUE_ID = $dealIdEsc LIMIT 1";
$res = $mysqli->query($sql);
if (!$res || !$res->num_rows) { echo json_encode(['error'=>'DEAL_NOT_FOUND','deal_id'=>$dealId], JSON_UNESCAPED_UNICODE); exit(1); }
$row  = $res->fetch_assoc();
$from = sanitize_addr((string)($row['UF_FROM'] ?? ''));
$to   = sanitize_addr((string)($row['UF_TO']   ?? ''));

if ($from === '' || $to === '') {
    echo json_encode(['error'=>'EMPTY_ADDRESSES','from'=>$from,'to'=>$to], JSON_UNESCAPED_UNICODE); exit(1);
}

if ($from === $to) {
    $km = 0.0;
} else {
    $A = geocode($from);
    $B = geocode($to);
    if (!$A || !$B) {
        echo json_encode(['error'=>'GEOCODE_FAILED','from_ok'=> (bool)$A,'to_ok'=> (bool)$B], JSON_UNESCAPED_UNICODE); exit(1);
    }
    [$alat,$alon] = $A; [$blat,$blon] = $B;
    $route = osrm_route($alat,$alon,$blat,$blon);
    $km = $route ? round($route['distance_m']/1000, 1) : round(haversine_km($alat,$alon,$blat,$blon), 1);
}

// пишем в UF_DISTANCE
$kmSql = number_format($km, 1, '.', '');
$ok = (bool)$mysqli->query("UPDATE b_uts_crm_deal SET ".UF_DISTANCE." = '$kmSql' WHERE VALUE_ID = $dealIdEsc");

// ответ
echo json_encode([
    'deal_id'     => $dealId,
    'from'        => $from,
    'to'          => $to,
    'distance_km' => (float)$kmSql,
    'updated'     => $ok
], JSON_UNESCAPED_UNICODE);

// ===== helpers =====
function sanitize_addr(string $s): string {
    $s = preg_replace('/\|.*$/u', '', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s, " \t\n\r\0\x0B,");
}

function http_get(string $url, int $timeout = 15, array $headers = []): array {
    // пробуем 2TLS->1.1 и IPv4->auto
    $attempts = [
        ['httpver'=>CURL_HTTP_VERSION_2TLS, 'ipresolve'=>CURL_IPRESOLVE_V4],
        ['httpver'=>CURL_HTTP_VERSION_1_1,  'ipresolve'=>CURL_IPRESOLVE_V4],
        ['httpver'=>CURL_HTTP_VERSION_1_1,  'ipresolve'=>0],
    ];
    $defaultHdr = [
        'User-Agent'      => 'neg32-routecalc/1.0 (admin@neg32.local)',
        'Accept'          => 'application/json',
        'Accept-Language' => 'ru',
        'Connection'      => 'close',
    ];
    $hdr = $defaultHdr;
    foreach ($headers as $k=>$v) $hdr[$k] = $v;
    $hdrArr = [];
    foreach ($hdr as $k=>$v) $hdrArr[] = $k.': '.$v;

    $last = [0, null];
    foreach ($attempts as $a) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => $hdrArr,
            CURLOPT_HTTP_VERSION   => $a['httpver'],
        ]);
        if (!empty($a['ipresolve'])) curl_setopt($ch, CURLOPT_IPRESOLVE, $a['ipresolve']);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $last = [$code, $body];
        if ($code === 200) return $last;
    }
    return $last;
}

function geocode(string $q): ?array {
    // 1) Nominatim
    $url1 = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q'=>$q,'format'=>'json','limit'=>1,'addressdetails'=>0,'accept-language'=>'ru',
        'email'=>'admin@neg32.local'
    ]);
    [$c1,$b1] = http_get($url1, 15);
    if ($c1 === 200) {
        $j = json_decode($b1, true);
        if (is_array($j) && !empty($j[0]['lat']) && !empty($j[0]['lon'])) {
            return [ (float)$j[0]['lat'], (float)$j[0]['lon'] ];
        }
    }
    // 2) Photon (fallback)
    $url2 = 'https://photon.komoot.io/api/?' . http_build_query(['q'=>$q,'limit'=>1,'lang'=>'ru']);
    [$c2,$b2] = http_get($url2, 15);
    if ($c2 === 200) {
        $j = json_decode($b2, true);
        if (!empty($j['features'][0]['geometry']['coordinates'])) {
            $lon = (float)$j['features'][0]['geometry']['coordinates'][0];
            $lat = (float)$j['features'][0]['geometry']['coordinates'][1];
            return [$lat,$lon];
        }
    }
    return null;
}

function osrm_route(float $lat1,float $lon1,float $lat2,float $lon2): ?array {
    $url = sprintf('https://router.project-osrm.org/route/v1/driving/%.6f,%.6f;%.6f,%.6f?overview=false',
        $lon1,$lat1,$lon2,$lat2);
    [$code,$body] = http_get($url, 15);
    if ($code !== 200) return null;
    $j = json_decode($body, true);
    if (empty($j['routes'][0]['distance'])) return null;
    return [
        'distance_m' => (float)$j['routes'][0]['distance'],
        'duration_s' => isset($j['routes'][0]['duration']) ? (float)$j['routes'][0]['duration'] : null,
    ];
}

function haversine_km(float $lat1,float $lon1,float $lat2,float $lon2): float {
    $R = 6371.0;
    $dLat = deg2rad($lat2-$lat1);
    $dLon = deg2rad($lon2-$lon1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)**2;
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}
