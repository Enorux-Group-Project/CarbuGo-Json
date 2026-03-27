<?php
/**
 * ============================================================
 *  CarbuFuel — Scraper PHP
 *  Équivalent du script Python, pensé pour cron job
 *  Cron recommandé : 30 2 * * * /usr/bin/php /chemin/scrape_stations.php
 * ============================================================
 */

// ── Timezone belge pour les logs ─────────────────────────────
date_default_timezone_set('Europe/Brussels');

// ── Chemin de sortie du fichier JSON ─────────────────────────
// Modifiez ce chemin selon votre hébergeur
define('OUTPUT_FILE', __DIR__ . '/../stations.json');

// ── Délais (secondes) ────────────────────────────────────────
define('DELAY_BETWEEN_REQUESTS', 2);   // pause entre chaque requête
define('DELAY_SAFETY_PAUSE',     5);   // pause tous les 5 requêtes
define('RETRY_DELAY',            3);   // délai entre tentatives
define('MAX_RETRIES',            3);   // nombre de tentatives max
define('REQUEST_TIMEOUT',       20);   // timeout cURL

// ── Log helper ───────────────────────────────────────────────
function log_msg(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

// ── Types de carburants ──────────────────────────────────────
$FUELS = [
    '95'     => 'E10',
    '98'     => 'SP98',
    'diesel' => 'GO',
];

// ── Villes / Locations ───────────────────────────────────────
// [city_display, city_url, cp, location_id]
$LOCATIONS = [
    // Bruxelles
    ['Bruxelles',                    'Bruxelles',                    '1000', 'BE_bx_1'],

    // Wallonie
    ['Namur',                        'Namur',                        '5000', 'BE_nm_1204'],
    ['Liège',                        'Liège',                        '4000', 'BE_lg_826'],
    ['Charleroi',                    'Charleroi',                    '6000', 'BE_ht_1578'],
    ['Mons',                         'Mons',                         '7000', 'BE_ht_1945'],
    ['Tournai',                      'Tournai',                      '7500', 'BE_ht_2098'],
    ['Arlon',                        'Arlon',                        '6700', 'BE_lu_1745'],
    ['Verviers',                     'Verviers',                     '4800', 'BE_lg_1140'],
    ['Wavre',                        'Wavre',                        '1300', 'BE_bw_81'],
    ['Nivelles',                     'Nivelles',                     '1400', 'BE_bw_153'],
    ['Ottignies-Louvain-la-Neuve',   'Ottignies-Louvain-la-Neuve',  '1340', 'BE_bw_102'],
    ['Gembloux',                     'Gembloux',                     '5030', 'BE_nm_1222'],
    ['Dinant',                       'Dinant',                       '5500', 'BE_nm_1366'],
    ['Marche-en-Famenne',            'Marche-en-Famenne',            '6900', 'BE_lu_1880'],
    ['Bastogne',                     'Bastogne',                     '6600', 'BE_lu_1705'],
    ['La Louvière',                  'La Louviere',                  '7100', 'BE_ht_2008'],
    ['Huy',                          'Huy',                          '4500', 'BE_lg_1003'],
    ['Ciney',                        'Ciney',                        '5590', 'BE_nm_1479'],
    ['Andenne',                      'Andenne',                      '5300', 'BE_nm_1284'],

    // Flandre
    ['Antwerpen',                    'Anvers',                       '2000', 'BE_a_310'],
    ['Gent',                         'Gand',                         '9000', 'BE_foi_2551'],
    ['Brugge',                       'Brugge',                       '8000', 'BE_foc_2292'],
    ['Leuven',                       'Leuven',                       '3000', 'BE_bf_468'],
    ['Hasselt',                      'Hasselt',                      '3500', 'BE_li_603'],
    ['Genk',                         'Genk',                         '3600', 'BE_li_634'],
    ['Kortrijk',                     'Kortrijk',                     '8500', 'BE_foc_2360'],
    ['Oostende',                     'Ostende',                      '8400', 'BE_foc_2326'],
    ['Sint-Niklaas',                 'Sint-Niklaas',                 '9100', 'BE_foi_2575'],
    ['Aalst',                        'Aalst',                        '9300', 'BE_foi_2633'],
    ['Mechelen',                     'Mechelen',                     '2800', 'BE_a_424'],
    ['Turnhout',                     'Turnhout',                     '2300', 'BE_a_361'],
    ['Roeselare',                    'Roeselare',                    '8800', 'BE_foc_2490'],
    ['Waregem',                      'Waregem',                      '8790', 'BE_foc_2484'],
    ['Vilvoorde',                    'Vilvoorde',                    '1800', 'BE_bf_272'],
    ['Halle',                        'Halle',                        '1500', 'BE_bf_200'],
    ['Tienen',                       'Tienen',                       '3300', 'BE_bf_541'],
    ['Tongeren',                     'Tongeren',                     '3700', 'BE_li_686'],
    ['Lommel',                       'Lommel',                       '3920', 'BE_li_800'],
    ['Beringen',                     'Beringen',                     '3580', 'BE_li_629'],
];

// ════════════════════════════════════════════════════════════
//  Helpers
// ════════════════════════════════════════════════════════════

function clean_text(?string $value): string {
    if ($value === null || $value === '') return '';
    $v = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $v = str_replace("\xc2\xa0", ' ', $v); // &nbsp;
    return trim(preg_replace('/\s+/', ' ', $v));
}

function clean_address(?string $value): string {
    if ($value === null || $value === '') return '';
    $v = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $v = str_ireplace(['<br/>', '<br>'], ', ', $v);
    $v = str_replace("\xc2\xa0", ' ', $v);
    return trim(preg_replace('/\s+/', ' ', $v), " ,");
}

function to_float(?string $value): ?float {
    if ($value === null || $value === '') return null;
    $v = str_replace(',', '.', $value);
    return is_numeric($v) ? (float)$v : null;
}

// ════════════════════════════════════════════════════════════
//  cURL — requête avec retry
// ════════════════════════════════════════════════════════════

function fetch_with_retry(string $url, int $tries = MAX_RETRIES, int $timeout = REQUEST_TIMEOUT): ?string {
    for ($attempt = 1; $attempt <= $tries; $attempt++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: fr-BE,fr;q=0.9',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => 'gzip, deflate',
        ]);

        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno === 0 && $http >= 200 && $http < 300 && $body !== false) {
            return $body;
        }

        log_msg("  Tentative $attempt/$tries échouée (HTTP $http, errno $errno)");
        if ($attempt < $tries) sleep(RETRY_DELAY);
    }

    return null;
}

// ════════════════════════════════════════════════════════════
//  Parser HTML — extraction des .stationItem
// ════════════════════════════════════════════════════════════

function parse_stations(string $html, string $fuel_label, string $city_display, string $cp, string $location_id): array {
    // Supprime les erreurs HTML silencieusement
    $prev = libxml_use_internal_errors(true);

    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $xpath    = new DOMXPath($dom);
    $items    = $xpath->query('//*[contains(@class,"stationItem")]');
    $stations = [];

    foreach ($items as $item) {
        /** @var DOMElement $item */
        $get = fn(string $attr) => $item->hasAttribute($attr) ? $item->getAttribute($attr) : '';

        $name = clean_text($get('data-name'));
        if ($name === '') continue;   // même condition que Python

        $stations[] = [
            'id'          => clean_text($get('data-id')),
            'name'        => $name,
            'brand'       => clean_text($get('data-logo')),
            'address'     => clean_address($get('data-address')),
            'lat'         => to_float($get('data-lat')),
            'lng'         => to_float($get('data-lng')),
            'fuel'        => $fuel_label,
            'fuel_name'   => clean_text($get('data-fuelname')),
            'price'       => to_float($get('data-price')),
            'url'         => clean_text($get('data-link')),
            'source_city' => $city_display,
            'source_cp'   => $cp,
            'location_id' => $location_id,
        ];
    }

    return $stations;
}

// ════════════════════════════════════════════════════════════
//  Scrape une page carbu.com
// ════════════════════════════════════════════════════════════

function scrape_page(
    string $city_display,
    string $city_url,
    string $cp,
    string $location_id,
    string $fuel_label,
    string $fuel_code
): array {
    if ($location_id === '') {
        log_msg("Location ID manquant pour $city_display ($cp) -> ignoré");
        return [];
    }

    $url = sprintf(
        'https://carbu.com/belgique//liste-stations-service/%s/%s/%s/%s',
        $fuel_code,
        rawurlencode($city_url),
        $cp,
        $location_id
    );

    log_msg("Scraping $fuel_label - $city_display ($cp) -> $location_id");

    $html = fetch_with_retry($url);
    if ($html === null) {
        log_msg("  ÉCHEC définitif pour $city_display / $fuel_label");
        return [];
    }

    return parse_stations($html, $fuel_label, $city_display, $cp, $location_id);
}

// ════════════════════════════════════════════════════════════
//  MAIN — boucle principale
// ════════════════════════════════════════════════════════════

log_msg('=== Démarrage du scraping CarbuFuel ===');

$all_stations    = [];   // clé = "id_fuel" pour dédoublonner
$request_count   = 0;

foreach ($FUELS as $fuel_label => $fuel_code) {
    foreach ($LOCATIONS as [$city_display, $city_url, $cp, $location_id]) {
        try {
            $results = scrape_page($city_display, $city_url, $cp, $location_id, $fuel_label, $fuel_code);

            foreach ($results as $s) {
                $key               = $s['id'] . '_' . $s['fuel'];
                $all_stations[$key] = $s;
            }
        } catch (Throwable $e) {
            log_msg("Erreur ignorée pour $city_display / $fuel_label : " . $e->getMessage());
        }

        $request_count++;
        sleep(DELAY_BETWEEN_REQUESTS);

        if ($request_count % 5 === 0) {
            log_msg('Pause de sécurité...');
            sleep(DELAY_SAFETY_PAUSE);
        }
    }
}

// ── Tri identique au script Python ───────────────────────────
$data = array_values($all_stations);

usort($data, function (array $a, array $b): int {
    $fuelCmp = strcmp($a['fuel'] ?? '', $b['fuel'] ?? '');
    if ($fuelCmp !== 0) return $fuelCmp;

    $pa = $a['price'] ?? 999999;
    $pb = $b['price'] ?? 999999;
    if ($pa !== $pb) return $pa <=> $pb;

    return strcmp($a['name'] ?? '', $b['name'] ?? '');
});

// ── Écriture du JSON ──────────────────────────────────────────
// On écrit d'abord dans un fichier temporaire, puis on renomme
// (évite un fichier corrompu si le script est interrompu)
$tmp = OUTPUT_FILE . '.tmp';
$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($json === false) {
    log_msg('ERREUR : impossible d\'encoder en JSON → ' . json_last_error_msg());
    exit(1);
}

if (file_put_contents($tmp, $json) === false) {
    log_msg('ERREUR : impossible d\'écrire dans ' . $tmp);
    exit(1);
}

// Remplacement atomique
if (!rename($tmp, OUTPUT_FILE)) {
    log_msg('ERREUR : rename() a échoué');
    exit(1);
}

log_msg('Done : ' . count($data) . ' stations sauvegardées dans ' . OUTPUT_FILE);
log_msg('=== Fin du scraping ===');