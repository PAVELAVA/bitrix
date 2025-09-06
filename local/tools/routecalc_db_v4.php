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
$from_raw = (string)($row['UF_FROM'] ?? '');
$to_raw   = (string)($row['UF_TO']   ?? '');

$from = sanitize_addr($from_raw);
$to   = sanitize_addr($to_raw);

if ($from === '' || $to === '') {
    echo json_encode(['error'=>'EMPTY_ADDRESSES','from'=>$from,'to'=>$to], JSON_UNESCAPED_UNICODE); exit(1);
}

if ($from === $to) {
    $km = 0.0;
} else {
    $A = geocode_relaxed($from);
    $B = geocode_relaxed($to);
    if (!$A || !$B) {
        echo json_encode([
            'error'=>'GEOCODE_FAILED',
            'from_ok'=> (bool)$A,
            'to_ok'  => (bool)$B,
            'from_q'=> $A===null ? ru_simplify($from) : $from,
            'to_q'  => $B===null ? ru_simplify($to)   : $to
        ], JSON_UNESCAPED_UNICODE); exit(1);
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
    // убрать координаты после "|", скобки и лишние пробелы/знаки
    $s = preg_replace('/\|.*$/u', '', $s);
    $s = preg_replace('/\(.+?\)/u', '', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s, " \t\n\r\0\x0B,;");
}

function ru_simplify(string $s): string {
    // убрать служебные слова: "въезд ...", "проходная ...", "со стороны ..."
    $s = preg_replace('/\bвъезд[^,]*/ui', '', $s);
    $s = preg_replace('/\bсо стороны[^,]*/ui', '', $s);
    $s = preg_replace('/\bпроходн(?:ая|ой)\b[^,]*/ui', '', $s);
    $s = preg_replace('/\(.+?\)/u', '', $s);
    $s = preg_replace('/\s+/u', ' ', $s);

    // Город — первый кусок до запятой
    $parts = array_map('trim', explode(',', $s));
    $city = $parts[0] ?? '';
    $city = preg_replace('/^\s*(г\.|город)\s*/ui', '', $city);

    // Улица
    $street = '';
    if (preg_match('/\b(?:ул\.?|улица)\s*([^\d,]+)/ui', $s, $m)) {
        $street = trim($m[1]);
    } else {
        // fallback: если второй кусок выглядит как название улицы
        $street = $parts[1] ?? '';
        $street = preg_replace('/^(ул\.?|улица)\s*/ui','',$street);
    }

    // Дом
    $house = '';
    if (preg_match('/\b(?:д\.?|дом)\s*([0-9]+[0-9A-Za-zА-Яа-я\-\/]*)/u', $s, $m)) {
        $house = $m[1];
    } elseif (preg_match('/\b([0-9]+[0-9A-Za-zА-Яа-я\-\/]*)\b/u', $s, $m)) {
        $house = $m[1];
    }

    $out = trim($city);
    if ($street !== '') $out .= ', ' . trim($street);
    if ($house  !== '') $out .= ' ' . trim($house);
    return trim($out, " ,");
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

function geocode_relaxed(string $q): ?array {
    // 1) пробуем как есть (Photon)
    $p1 = geocode_photon($q);
    if ($p1) return $p1;

    // 2) нормализуем рус-адрес и снова Photon
    $q2 = ru_simplify($q);
    if ($q2 && $q2 !== $q) {
        $p2 = geocode_photon($q2);
        if ($p2) return $p2;
    }

    // 3) фолбэк: geocode.maps.co (OSM)
    $m1 = geocode_mapsco($q);
    if ($m1) return $m1;
    if ($q2 && $q2 !== $q) {
        $m2 = geocode_mapsco($q2);
        if ($m2) return $m2;
    }
    return null;
}

function geocode_photon(string $q): ?array {
    $url = 'https://photon.komoot.io/api/?'.http_build_query(['q'=>$q,'limit'=>1,'lang'=>'en']);
    [$c,$b] = http_get($url, 15);
    if ($c !== 200) return null;
    $j = json_decode($b, true);
    if (!empty($j['features'][0]['geometry']['coordinates'])) {
        $lon = (float)$j['features'][0]['geometry']['coordinates'][0];
        $lat = (float)$j['features'][0]['geometry']['coordinates'][1];
        return [$lat,$lon];
    }
    return null;
}

function geocode_mapsco(string $q): ?array {
    $url = 'https://geocode.maps.co/search?'.http_build_query(['q'=>$q,'format'=>'json']);
    [$c,$b] = http_get($url, 15);
    if ($c !== 200) return null;
    $j = json_decode($b, true);
    if (is_array($j) && !empty($j[0]['lat']) && !empty($j[0]['lon'])) {
        return [ (float)$j[0]['lat'], (float)$j[0]['lon'] ];
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
