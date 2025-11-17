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
const USER_AGENT = 'Mozilla/5.0 (compatible; 12GewinnCrawler/2.0)';
const BASE_URL_12GEWINN = 'https://www.12gewinn.de/';

$pdo = createPdo();
$stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];
$visitedListings = [];
$visitedDetailUrls = [];

crawl12Gewinn($pdo, $stats, $visitedListings, $visitedDetailUrls);
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

function crawl12Gewinn(PDO $pdo, array &$stats, array &$visitedListings, array &$visitedDetailUrls): void
{
    $entryPoints = collect12GewinnEntryPoints();
    foreach ($entryPoints as $startUrl) {
        crawlListingWithPagination($startUrl, $visitedListings, function (DOMXPath $xpath, string $currentUrl) use ($pdo, &$stats, &$visitedDetailUrls): void {
            $detailLinks = [];
            $nodes = $xpath->query("//a[contains(@href,'/gewinnspiel') and not(contains(@href,'#'))]");
            if ($nodes) {
                foreach ($nodes as $node) {
                    $href = trim($node->getAttribute('href'));
                    if ($href === '') {
                        continue;
                    }
                    $detailUrl = makeAbsoluteUrl($currentUrl, $href);
                    if ($detailUrl && str_contains($detailUrl, '12gewinn.de')) {
                        $detailLinks[$detailUrl] = true;
                    }
                }
            }
            foreach (array_keys($detailLinks) as $detailUrl) {
                if (isset($visitedDetailUrls[$detailUrl])) {
                    continue;
                }
                $visitedDetailUrls[$detailUrl] = true;
                handle12GewinnDetail($pdo, $stats, $detailUrl);
            }
        });
    }
}

function collect12GewinnEntryPoints(): array
{
    $entryPoints = [BASE_URL_12GEWINN];
    $xpath = fetchHtml(BASE_URL_12GEWINN);
    if (!$xpath) {
        return $entryPoints;
    }
    $linkNodes = $xpath->query("//nav//a | //a[contains(@href,'gewinnspiel')]");
    if ($linkNodes) {
        foreach ($linkNodes as $node) {
            $href = trim($node->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            $absolute = makeAbsoluteUrl(BASE_URL_12GEWINN, $href);
            if (!$absolute) {
                continue;
            }
            $host = parse_url($absolute, PHP_URL_HOST) ?: '';
            if (str_contains($host, '12gewinn.de') && !in_array($absolute, $entryPoints, true)) {
                $entryPoints[] = $absolute;
            }
        }
    }
    return $entryPoints;
}

function crawlListingWithPagination(string $startUrl, array &$visitedListings, callable $processListing): void
{
    $nextUrl = $startUrl;
    while ($nextUrl !== null) {
        if (isset($visitedListings[$nextUrl])) {
            break;
        }
        $visitedListings[$nextUrl] = true;
        $xpath = fetchHtml($nextUrl);
        if (!$xpath) {
            output_line('[ERROR] Konnte Listing nicht laden: ' . $nextUrl);
            $nextUrl = null;
            continue;
        }
        $processListing($xpath, $nextUrl);
        $candidate = findNextPageUrl($xpath, $nextUrl);
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

function handle12GewinnDetail(PDO $pdo, array &$stats, string $detailUrl): void
{
    $xpath = fetchHtml($detailUrl);
    if (!$xpath) {
        $stats['skipped']++;
        output_line('[ERROR] Detailseite nicht erreichbar: ' . $detailUrl);
        return;
    }
    $endDate = extract12GewinnDate($xpath);
    if (!$endDate) {
        $stats['skipped']++;
        output_line('[SKIP] Kein Datum erkannt: ' . $detailUrl);
        return;
    }
    $externalUrl = find12GewinnExternalUrl($xpath, $detailUrl);
    if (!$externalUrl) {
        $stats['skipped']++;
        output_line('[SKIP] Kein externer Link: ' . $detailUrl);
        return;
    }
    $status = determineStatus($endDate);
    $message = saveGewinnspiel($pdo, $externalUrl, $endDate, $status, $stats);
    output_line(sprintf('%s %s – %s – %s', $message, $externalUrl, $endDate->format('Y-m-d'), $status));
}

function extract12GewinnDate(DOMXPath $xpath): ?DateTime
{
    $nodes = $xpath->query("//*[contains(translate(normalize-space(string()), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜß', 'abcdefghijklmnopqrstuvwxyzäöüß'), 'einsendeschluss') or contains(translate(normalize-space(string()), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜß', 'abcdefghijklmnopqrstuvwxyzäöüß'), 'einsendeschluß')]");
    if ($nodes) {
        foreach ($nodes as $node) {
            $text = trim($node->textContent ?? '');
            if ($text !== '' && preg_match('/(\d{1,2}\.\d{1,2}\.\d{4})/', $text, $matches)) {
                $date = DateTime::createFromFormat('d.m.Y', $matches[1]);
                if ($date) {
                    $date->setTime(0, 0, 0);
                    return $date;
                }
            }
        }
    }
    $doc = $xpath->document;
    $html = $doc instanceof DOMDocument ? $doc->saveHTML() : '';
    if ($html && preg_match('/(\d{1,2}\.\d{1,2}\.\d{4})/', $html, $matches)) {
        $date = DateTime::createFromFormat('d.m.Y', $matches[1]);
        if ($date) {
            $date->setTime(0, 0, 0);
            return $date;
        }
    }
    return null;
}

function find12GewinnExternalUrl(DOMXPath $xpath, string $baseUrl): ?string
{
    $nodes = $xpath->query("//a[contains(translate(normalize-space(string()), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ', 'abcdefghijklmnopqrstuvwxyzäöü'), 'zum gewinnspiel') or contains(@class,'btn-secondary')]");
    if (!$nodes || !$nodes->length) {
        return null;
    }
    foreach ($nodes as $node) {
        $href = trim($node->getAttribute('href'));
        if ($href === '') {
            continue;
        }
        $absolute = makeAbsoluteUrl($baseUrl, $href);
        if (!$absolute) {
            continue;
        }
        $resolved = resolveExternalUrl($absolute);
        if ($resolved) {
            return $resolved;
        }
    }
    return null;
}

function saveGewinnspiel(PDO $pdo, string $externalUrl, DateTime $endDate, string $status, array &$stats): string
{
    $stmt = $pdo->prepare('INSERT INTO gewinnspiele (link_zur_webseite, beschreibung, status, endet_am) VALUES (:link, :beschreibung, :status, :endet_am)
        ON DUPLICATE KEY UPDATE status = VALUES(status), endet_am = VALUES(endet_am)');
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

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
