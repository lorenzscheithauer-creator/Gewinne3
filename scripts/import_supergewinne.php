<?php
declare(strict_types=1);

set_time_limit(0);
ini_set('memory_limit', '-1');

// Einmalig in MySQL ausführen, um doppelte Einträge auszuschließen:
// ALTER TABLE gewinnspiele ADD UNIQUE KEY uniq_link_zur_webseite (link_zur_webseite);

const DB_HOST = 'localhost';
const DB_NAME = 'gewinne3';
const DB_USER = 'root';
const DB_PASS = '';
const HTTP_DELAY_MICROSECONDS = 250000;
const USER_AGENT = 'Mozilla/5.0 (compatible; SupergewinneCrawler/3.0)';

$pdo = createPdo();
$stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];
$visitedListings = [];
$visitedDetailUrls = [];

crawlSupergewinne($pdo, $stats, $visitedListings, $visitedDetailUrls);
output_line("Fertig. Neu: {$stats['created']}, aktualisiert: {$stats['updated']}, übersprungen: {$stats['skipped']}");

function createPdo(): PDO
{
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function output_line(string $message): void
{
    if (php_sapi_name() !== 'cli') {
        echo htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "<br>\n";
        return;
    }
    echo $message . PHP_EOL;
}

function crawlSupergewinne(PDO $pdo, array &$stats, array &$visitedListings, array &$visitedDetailUrls): void
{
    $entryPoints = collectSupergewinneEntryPoints();
    foreach ($entryPoints as $startUrl) {
        output_line('[LISTING] ' . $startUrl);
        crawlListingWithPagination($startUrl, $visitedListings, function (DOMXPath $xpath, string $currentUrl) use ($pdo, &$stats, &$visitedDetailUrls): void {
            $detailLinks = extractSupergewinneDetailLinks($xpath, $currentUrl);
            foreach ($detailLinks as $detailUrl) {
                if (isset($visitedDetailUrls[$detailUrl])) {
                    continue;
                }
                $visitedDetailUrls[$detailUrl] = true;
                handleSupergewinneDetail($pdo, $stats, $detailUrl);
            }
        });
    }
}

function collectSupergewinneEntryPoints(): array
{
    $seedUrls = [
        'https://www.supergewinne.de/',
        'https://www.supergewinne.de/gewinnspiele/',
    ];
    $seen = [];
    $entryPoints = [];
    $queue = $seedUrls;
    while ($queue) {
        $current = normalizeUrl((string) array_shift($queue));
        if ($current === '' || isset($seen[$current])) {
            continue;
        }
        $seen[$current] = true;
        if (isSupergewinneListingUrl($current) && !in_array($current, $entryPoints, true)) {
            $entryPoints[] = $current;
        }
        $xpath = fetchHtml($current);
        if (!$xpath) {
            continue;
        }
        $linkNodes = $xpath->query("//a[contains(@href,'gewinnspiel') or contains(@href,'gewinnspiele') or contains(@href,'/kategorie/') or contains(@href,'/category/')]");
        if (!$linkNodes) {
            continue;
        }
        foreach ($linkNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $href = trim($node->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            $absolute = makeAbsoluteUrl($current, $href);
            if (!$absolute || !isSupergewinneListingUrl($absolute)) {
                continue;
            }
            $absolute = normalizeUrl($absolute);
            if ($absolute !== '' && !isset($seen[$absolute])) {
                $queue[] = $absolute;
            }
        }
    }
    return $entryPoints;
}

function extractSupergewinneDetailLinks(DOMXPath $xpath, string $currentUrl): array
{
    $detailLinks = [];
    $expressions = [
        "//article//a[contains(@href,'/gewinnspiel') or contains(@href,'/verlosung') or contains(@href,'/aktion')]",
        "//div[contains(@class,'gewinnspiel')]//a[@href]",
        "//a[contains(@class,'elementor-button') or contains(@class,'more-link') or contains(@class,'btn')]",
        "//a[contains(translate(normalize-space(string()), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ', 'abcdefghijklmnopqrstuvwxyzäöü'), 'mehr lesen') or contains(translate(normalize-space(string()), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ', 'abcdefghijklmnopqrstuvwxyzäöü'), 'weiterlesen') or contains(translate(normalize-space(string()), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ', 'abcdefghijklmnopqrstuvwxyzäöü'), 'zum gewinnspiel')]",
    ];
    foreach ($expressions as $expression) {
        $nodes = $xpath->query($expression);
        if (!$nodes) {
            continue;
        }
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $href = trim($node->getAttribute('href'));
            $candidates = [$href];
            foreach (['data-href', 'data-url', 'data-link'] as $attribute) {
                $value = trim($node->getAttribute($attribute));
                if ($value !== '') {
                    $candidates[] = $value;
                }
            }
            $onclick = $node->getAttribute('onclick');
            if ($onclick && preg_match("#https?://[^'\"]+#", $onclick, $match)) {
                $candidates[] = $match[0];
            }
            foreach ($candidates as $candidate) {
                if ($candidate === '') {
                    continue;
                }
                $detailUrl = makeAbsoluteUrl($currentUrl, $candidate);
                if (!$detailUrl) {
                    continue;
                }
                $detailUrl = normalizeUrl($detailUrl);
                if ($detailUrl === '') {
                    continue;
                }
                $host = parse_url($detailUrl, PHP_URL_HOST) ?: '';
                if (!str_contains($host, 'supergewinne.de')) {
                    continue;
                }
                $detailLinks[$detailUrl] = $detailUrl;
            }
        }
    }
    return array_values($detailLinks);
}

function isSupergewinneListingUrl(string $url): bool
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host || !str_contains($host, 'supergewinne.de')) {
        return false;
    }
    $path = parse_url($url, PHP_URL_PATH) ?? '/';
    if ($path === '/') {
        return true;
    }
    if (preg_match('#/(gewinnspiele|kategorie|category|tag)/#i', $path)) {
        return true;
    }
    if (str_contains($path, '/gewinnspiele')) {
        return true;
    }
    return false;
}

function crawlListingWithPagination(string $startUrl, array &$visitedListings, callable $processListing): void
{
    $nextUrl = $startUrl;
    while ($nextUrl !== null) {
        $normalized = normalizeUrl($nextUrl);
        if ($normalized === '' || isset($visitedListings[$normalized])) {
            break;
        }
        $visitedListings[$normalized] = true;
        $xpath = fetchHtml($nextUrl);
        if (!$xpath) {
            output_line('[ERROR] Konnte Listing nicht laden: ' . $nextUrl);
            $nextUrl = null;
            continue;
        }
        $processListing($xpath, $normalized);
        $candidate = findNextPageUrl($xpath, $normalized);
        if ($candidate) {
            $candidate = normalizeUrl($candidate);
        }
        if ($candidate && !isset($visitedListings[$candidate])) {
            $nextUrl = $candidate;
            continue;
        }
        $nextUrl = null;
    }
}

function findNextPageUrl(DOMXPath $xpath, string $currentUrl): ?string
{
    $expressions = [
        "//a[contains(@class,'next') or contains(@rel,'next') or contains(@aria-label,'Next')]",
        "//a[contains(translate(normalize-space(string()), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ', 'abcdefghijklmnopqrstuvwxyzäöü'), 'weiter')]",
        "//a[contains(@class,'page-numbers') and (contains(@class,'next') or contains(translate(normalize-space(string()), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ', 'abcdefghijklmnopqrstuvwxyzäöü'), 'nächste'))]",
        "//a[contains(translate(normalize-space(string()), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ', 'abcdefghijklmnopqrstuvwxyzäöü'), 'ältere') or contains(translate(normalize-space(string()), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ', 'abcdefghijklmnopqrstuvwxyzäöü'), 'vorherige')]",
    ];
    foreach ($expressions as $expression) {
        $nodes = $xpath->query($expression);
        if ($nodes && $nodes->length) {
            $href = trim($nodes->item(0)->getAttribute('href'));
            if ($href !== '') {
                $absolute = makeAbsoluteUrl($currentUrl, $href);
                if ($absolute) {
                    return $absolute;
                }
            }
        }
    }
    return null;
}

function handleSupergewinneDetail(PDO $pdo, array &$stats, string $detailUrl): void
{
    $xpath = fetchHtml($detailUrl);
    if (!$xpath) {
        $stats['skipped']++;
        output_line('[ERROR] Detailseite nicht erreichbar: ' . $detailUrl);
        return;
    }
    $endDate = extractDateFromDocument($xpath);
    if (!$endDate) {
        $stats['skipped']++;
        output_line('[SKIP] Kein Datum erkannt: ' . $detailUrl);
        return;
    }
    $actionLink = findSupergewinneActionLink($xpath, $detailUrl);
    if (!$actionLink) {
        $stats['skipped']++;
        output_line('[SKIP] Kein Mitmach-Link: ' . $detailUrl);
        return;
    }
    $externalUrl = resolveExternalUrl($actionLink);
    if (!$externalUrl) {
        $stats['skipped']++;
        output_line('[SKIP] Externe URL nicht erreichbar: ' . $actionLink);
        return;
    }
    $status = determineStatus($endDate);
    $message = saveGewinnspiel($pdo, $externalUrl, $endDate, $status, $stats);
    output_line(sprintf('%s %s – %s – %s', $message, $externalUrl, $endDate->format('Y-m-d'), $status));
}

function extractDateFromDocument(DOMXPath $xpath): ?DateTime
{
    $doc = $xpath->document;
    $html = $doc instanceof DOMDocument ? $doc->saveHTML() : '';
    if ($html && preg_match('/(\d{1,2}\.\d{1,2}\.\d{2,4})/', $html, $matches)) {
        return normalizeDate($matches[1]);
    }
    return null;
}

function findSupergewinneActionLink(DOMXPath $xpath, string $baseUrl): ?string
{
    $expressions = [
        "//a[contains(translate(normalize-space(string()), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ', 'abcdefghijklmnopqrstuvwxyzäöü'), 'jetzt mitmachen') or contains(translate(normalize-space(string()), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ', 'abcdefghijklmnopqrstuvwxyzäöü'), 'zum gewinnspiel') or contains(translate(normalize-space(string()), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ', 'abcdefghijklmnopqrstuvwxyzäöü'), 'teilnehmen') or contains(@class,'btn') or contains(@class,'button')]",
        "//div[contains(@class,'modal')]//a[@href]",
    ];
    foreach ($expressions as $expression) {
        $nodes = $xpath->query($expression);
        if (!$nodes) {
            continue;
        }
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $candidateLinks = [];
            $href = trim($node->getAttribute('href'));
            if ($href !== '') {
                $candidateLinks[] = $href;
            }
            foreach (['data-href', 'data-url', 'data-link'] as $attribute) {
                $value = trim($node->getAttribute($attribute));
                if ($value !== '') {
                    $candidateLinks[] = $value;
                }
            }
            $onclick = $node->getAttribute('onclick');
            if ($onclick && preg_match("#https?://[^'\"]+#", $onclick, $match)) {
                $candidateLinks[] = $match[0];
            }
            foreach ($candidateLinks as $candidateLink) {
                $absolute = makeAbsoluteUrl($baseUrl, $candidateLink);
                if ($absolute) {
                    return $absolute;
                }
            }
        }
    }
    return null;
}

function saveGewinnspiel(PDO $pdo, string $externalUrl, DateTime $endDate, string $status, array &$stats): string
{
    $stmt = $pdo->prepare('INSERT INTO gewinnspiele (link_zur_webseite, beschreibung, status, endet_am) VALUES (:link, :beschreibung, :status, :endet_am)'
        . ' ON DUPLICATE KEY UPDATE status = VALUES(status), endet_am = VALUES(endet_am)');
    $stmt->execute([
        ':link' => $externalUrl,
        ':beschreibung' => '',
        ':status' => $status,
        ':endet_am' => $endDate->format('Y-m-d'),
    ]);
    $rowCount = $stmt->rowCount();
    if ($rowCount === 1) {
        $stats['created']++;
        return '[OK] Neu';
    }
    if ($rowCount >= 2) {
        $stats['updated']++;
        return '[UPDATE] Aktualisiert';
    }
    $stats['skipped']++;
    return '[SKIP] Unverändert';
}

function determineStatus(DateTime $endDate): string
{
    $today = new DateTime('today');
    return $endDate < $today ? 'Ende' : 'Aktiv';
}

function resolveExternalUrl(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => true,
    ]);
    curl_exec($ch);
    if (curl_errno($ch)) {
        output_line('[ERROR] cURL: ' . curl_error($ch) . ' – ' . $url);
        curl_close($ch);
        usleep(HTTP_DELAY_MICROSECONDS);
        return null;
    }
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    usleep(HTTP_DELAY_MICROSECONDS);
    return $effectiveUrl ?: null;
}

function fetchHtml(string $url): ?DOMXPath
{
    $body = performHttpRequest($url);
    if ($body === null) {
        return null;
    }
    $body = mb_convert_encoding($body, 'HTML-ENTITIES', 'UTF-8, ISO-8859-1, ISO-8859-15');
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$doc->loadHTML($body)) {
        libxml_clear_errors();
        return null;
    }
    libxml_clear_errors();
    return new DOMXPath($doc);
}

function performHttpRequest(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_ENCODING => '',
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        output_line('[ERROR] cURL: ' . curl_error($ch) . ' – ' . $url);
        curl_close($ch);
        usleep(HTTP_DELAY_MICROSECONDS);
        return null;
    }
    curl_close($ch);
    usleep(HTTP_DELAY_MICROSECONDS);
    return $response;
}

function makeAbsoluteUrl(string $baseUrl, string $relative): ?string
{
    if ($relative === '') {
        return null;
    }
    if (parse_url($relative, PHP_URL_SCHEME)) {
        return $relative;
    }
    if (str_starts_with($relative, '//')) {
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $relative;
    }
    $parts = parse_url($baseUrl);
    if (!$parts || !isset($parts['scheme'], $parts['host'])) {
        return null;
    }
    $scheme = $parts['scheme'];
    $host = $parts['host'];
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path = $parts['path'] ?? '/';
    $path = preg_replace('#/[^/]*$#', '/', $path);
    if (str_starts_with($relative, '/')) {
        $path = '';
    }
    $abs = $scheme . '://' . $host . $port . $path . $relative;
    $abs = preg_replace('#/\./#', '/', $abs);
    while (str_contains($abs, '../')) {
        $abs = preg_replace('#[^/]+/\.\./#', '', $abs, 1);
    }
    return $abs;
}

function normalizeUrl(string $url): string
{
    if ($url === '') {
        return '';
    }
    $url = preg_replace('/#.+$/', '', $url);
    $parts = parse_url($url);
    if (!$parts || !isset($parts['scheme'], $parts['host'])) {
        return $url;
    }
    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host']);
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path = $parts['path'] ?? '/';
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    if ($path !== '/' && str_ends_with($path, '/')) {
        $path = rtrim($path, '/');
    }
    return $scheme . '://' . $host . $port . $path . $query;
}

function normalizeDate(string $raw): ?DateTime
{
    $raw = trim($raw);
    $raw = rtrim($raw, '.');
    $formats = ['d.m.Y', 'd.m.y'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $raw);
        if ($date instanceof DateTime) {
            $year = (int) $date->format('Y');
            if ($year < 2000) {
                $date->modify('+100 years');
            }
            $date->setTime(0, 0, 0);
            return $date;
        }
    }
    return null;
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return substr($haystack, -strlen($needle)) === $needle;
    }
}
