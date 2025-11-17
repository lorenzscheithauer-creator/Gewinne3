<?php
declare(strict_types=1);

const DB_HOST = 'localhost';
const DB_NAME = 'gewinne3';
const DB_USER = 'root';
const DB_PASS = '';

function output_line(string $message): void
{
    if (php_sapi_name() !== 'cli') {
        echo htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "<br>\n";
    } else {
        echo $message . PHP_EOL;
    }
}

function string_starts_with(string $haystack, string $needle): bool
{
    return strncmp($haystack, $needle, strlen($needle)) === 0;
}

class SupergewinneImporter
{
    private PDO $pdo;
    private array $visitedListings = [];
    private array $stats = [
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,
    ];

    public function __construct()
    {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
        $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function run(): void
    {
        $entryPoints = $this->collectEntryPoints();
        foreach ($entryPoints as $url) {
            $this->crawlListingPages($url);
        }
        $summary = sprintf('Fertig: %d eingefügt, %d aktualisiert, %d übersprungen.', $this->stats['inserted'], $this->stats['updated'], $this->stats['skipped']);
        output_line($summary);
    }

    private function collectEntryPoints(): array
    {
        $entryPoints = [
            'https://www.supergewinne.de/',
            'https://www.supergewinne.de/gewinnspiele/',
        ];

        $xpath = $this->fetchHtml('https://www.supergewinne.de/');
        if ($xpath !== null) {
            $nodes = $xpath->query("//nav[contains(@class,'menu') or contains(@class,'navigation')]//a[contains(@href,'/gewinnspiele/')]");
            if ($nodes) {
                foreach ($nodes as $node) {
                    $href = $node->getAttribute('href');
                    if (!$href) {
                        continue;
                    }
                    $absolute = $this->makeAbsolute('https://www.supergewinne.de/', $href);
                    if ($absolute && !in_array($absolute, $entryPoints, true)) {
                        $entryPoints[] = $absolute;
                    }
                }
            }
        }
        return $entryPoints;
    }

    private function crawlListingPages(string $url): void
    {
        $queue = [$url];
        while ($queue) {
            $current = array_shift($queue);
            if (isset($this->visitedListings[$current])) {
                continue;
            }
            $this->visitedListings[$current] = true;
            $xpath = $this->fetchHtml($current);
            if ($xpath === null) {
                output_line('[ERROR] Konnte Listing nicht laden: ' . $current);
                continue;
            }
            $this->processListing($current, $xpath);
            $paginationLinks = $xpath->query("//a[contains(@class,'page-numbers') or contains(@class,'pagination') or contains(@rel,'next') or contains(@rel,'prev')]");
            if ($paginationLinks) {
                foreach ($paginationLinks as $node) {
                    $href = $node->getAttribute('href');
                    if (!$href) {
                        continue;
                    }
                    $absolute = $this->makeAbsolute($current, $href);
                    if ($absolute && !isset($this->visitedListings[$absolute])) {
                        $queue[] = $absolute;
                    }
                }
            }
        }
    }

    private function processListing(string $listingUrl, DOMXPath $xpath): void
    {
        $nodes = $xpath->query("//a[contains(translate(normalize-space(text()), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ', 'abcdefghijklmnopqrstuvwxyzäöü'), 'mehr lesen')]");
        if (!$nodes) {
            return;
        }
        foreach ($nodes as $node) {
            $href = $node->getAttribute('href');
            if (!$href) {
                continue;
            }
            $detailUrl = $this->makeAbsolute($listingUrl, $href);
            if (!$detailUrl) {
                continue;
            }
            $this->handleDetail($detailUrl);
        }
    }

    private function handleDetail(string $detailUrl): void
    {
        $xpath = $this->fetchHtml($detailUrl);
        if ($xpath === null) {
            output_line('[ERROR] Detailseite nicht erreichbar: ' . $detailUrl);
            $this->stats['skipped']++;
            return;
        }
        $endDate = $this->extractEndDate($xpath);
        if (!$endDate) {
            output_line('[ERROR] Kein Datum gefunden bei: ' . $detailUrl);
            $this->stats['skipped']++;
            return;
        }
        $actionLink = $this->findActionLink($xpath, $detailUrl);
        if (!$actionLink) {
            output_line('[ERROR] Kein Mitmach-Link gefunden bei: ' . $detailUrl);
            $this->stats['skipped']++;
            return;
        }
        $externalUrl = $this->resolveExternalUrl($actionLink);
        if (!$externalUrl) {
            output_line('[ERROR] Externe URL konnte nicht aufgelöst werden: ' . $actionLink);
            $this->stats['skipped']++;
            return;
        }
        $status = ($endDate < new DateTime('today')) ? 'Ende' : 'Aktiv';
        $result = $this->saveGewinnspiel($externalUrl, $endDate, $status);
        output_line(sprintf('%s %s – endet am %s – Status: %s', $result['message'], $externalUrl, $endDate->format('Y-m-d'), $status));
    }

    private function extractEndDate(DOMXPath $xpath): ?DateTime
    {
        $doc = $xpath->document;
        $html = $doc instanceof DOMDocument ? $doc->saveHTML() : '';
        if (!$html) {
            return null;
        }
        if (!preg_match_all('/(\d{1,2}\.\d{1,2}\.\d{4})/', $html, $matches)) {
            return null;
        }
        foreach ($matches[1] as $dateString) {
            $date = DateTime::createFromFormat('d.m.Y', $dateString);
            if ($date instanceof DateTime) {
                $date->setTime(23, 59, 59);
                return $date;
            }
        }
        return null;
    }

    private function findActionLink(DOMXPath $xpath, string $baseUrl): ?string
    {
        $nodes = $xpath->query("//a[contains(translate(normalize-space(string()), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ', 'abcdefghijklmnopqrstuvwxyzäöü'), 'jetzt direkt mitmachen') or contains(translate(normalize-space(string()), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ', 'abcdefghijklmnopqrstuvwxyzäöü'), 'zum gewinnspiel')]");
        if (!$nodes || !$nodes->length) {
            return null;
        }
        $href = $nodes->item(0)->getAttribute('href');
        return $this->makeAbsolute($baseUrl, $href);
    }

    private function resolveExternalUrl(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SupergewinneImporter/1.0)',
        ]);
        curl_exec($ch);
        if (curl_errno($ch)) {
            curl_close($ch);
            return null;
        }
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return $effectiveUrl ?: null;
    }

    private function saveGewinnspiel(string $externalUrl, DateTime $endDate, string $status): array
    {
        $stmt = $this->pdo->prepare('SELECT id, endet_am, status FROM gewinnspiele WHERE link_zur_webseite = :url LIMIT 1');
        $stmt->execute([':url' => $externalUrl]);
        $existing = $stmt->fetch();
        if ($existing) {
            $currentEnd = $existing['endet_am'] ?? null;
            $needsUpdate = $currentEnd !== $endDate->format('Y-m-d H:i:s') || $existing['status'] !== $status;
            if ($needsUpdate) {
                $updateStmt = $this->pdo->prepare('UPDATE gewinnspiele SET endet_am = :endet_am, status = :status WHERE id = :id');
                $updateStmt->execute([
                    ':endet_am' => $endDate->format('Y-m-d H:i:s'),
                    ':status' => $status,
                    ':id' => $existing['id'],
                ]);
                $this->stats['updated']++;
                return ['message' => '[UPDATE] Aktualisiert'];
            }
            $this->stats['skipped']++;
            return ['message' => '[SKIP] Bereits vorhanden'];
        }
        $insertStmt = $this->pdo->prepare('INSERT INTO gewinnspiele (link_zur_webseite, beschreibung, status, endet_am) VALUES (:link, :beschreibung, :status, :endet_am)');
        $insertStmt->execute([
            ':link' => $externalUrl,
            ':beschreibung' => '',
            ':status' => $status,
            ':endet_am' => $endDate->format('Y-m-d H:i:s'),
        ]);
        $this->stats['inserted']++;
        return ['message' => '[OK] Eingefügt'];
    }

    private function fetchHtml(string $url): ?DOMXPath
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SupergewinneImporter/1.0)',
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            curl_close($ch);
            return null;
        }
        curl_close($ch);
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

    private function makeAbsolute(string $base, string $relative): ?string
    {
        if (!$relative) {
            return null;
        }
        if (parse_url($relative, PHP_URL_SCHEME)) {
            return $relative;
        }
        if (string_starts_with($relative, '//')) {
            $parsedBase = parse_url($base);
            return ($parsedBase['scheme'] ?? 'https') . ':' . $relative;
        }
        $parsedBase = parse_url($base);
        if (!$parsedBase || !isset($parsedBase['scheme'], $parsedBase['host'])) {
            return null;
        }
        $scheme = $parsedBase['scheme'];
        $host = $parsedBase['host'];
        $port = isset($parsedBase['port']) ? ':' . $parsedBase['port'] : '';
        $path = $parsedBase['path'] ?? '/';
        $path = preg_replace('#/[^/]*$#', '/', $path);
        if ($relative[0] === '/') {
            $path = '';
        }
        $abs = "$scheme://$host$port$path$relative";
        $abs = preg_replace('#/\./#', '/', $abs);
        while (strpos($abs, '../') !== false) {
            $abs = preg_replace('#[^/]+/\.\./#', '', $abs, 1);
        }
        return $abs;
    }
}

try {
    $importer = new SupergewinneImporter();
    $importer->run();
} catch (Throwable $e) {
    output_line('Fehler: ' . $e->getMessage());
}
