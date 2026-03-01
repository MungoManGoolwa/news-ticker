<?php
/**
 * feed.php — RSS Feed Aggregator for Iran/Israel/US Conflict News Ticker
 *
 * Fetches multiple RSS feeds, deduplicates, sorts by date, caches results.
 * Returns JSON array of news items.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Cache settings
define('CACHE_DIR', '/tmp/news_ticker_cache');
define('CACHE_TTL', 120); // 2 minutes
define('CACHE_FILE', CACHE_DIR . '/feed_cache.json');
define('MAX_ITEMS', 80);
define('FETCH_TIMEOUT', 10);

// RSS feed sources
$feeds = [
    [
        'url'    => 'https://news.google.com/rss/search?q=iran+israel+war&hl=en-AU&gl=AU&ceid=AU:en',
        'source' => 'Google News',
    ],
    [
        'url'    => 'https://news.google.com/rss/search?q=iran+conflict+middle+east&hl=en-AU&gl=AU&ceid=AU:en',
        'source' => 'Google News',
    ],
    [
        'url'    => 'https://news.google.com/rss/search?q=iran+US+military&hl=en-AU&gl=AU&ceid=AU:en',
        'source' => 'Google News',
    ],
    [
        'url'    => 'https://www.aljazeera.com/xml/rss/all.xml',
        'source' => 'Al Jazeera',
    ],
    [
        'url'    => 'https://rss.nytimes.com/services/xml/rss/nyt/MiddleEast.xml',
        'source' => 'NY Times',
    ],
    [
        'url'    => 'https://feeds.bbci.co.uk/news/world/middle_east/rss.xml',
        'source' => 'BBC',
    ],
    [
        'url'    => 'https://www.abc.net.au/news/feed/2942460/rss.xml',
        'source' => 'ABC News AU',
    ],
];

// Conflict-related keyword patterns for filtering general feeds
// Uses word-boundary regex to avoid false positives (e.g. "striker", "identified")
$keyword_patterns = [
    '/\biran\b/i', '/\bisrael\b/i', '/\btehran\b/i', '/\bidf\b/i',
    '/\bhezbollah\b/i', '/\bhouthi/i', '/\bgaza\b/i', '/\bhamas\b/i',
    '/\bnetanyahu\b/i', '/\bkhamenei\b/i', '/\birgc\b/i',
    '/\bstrait of hormuz\b/i', '/\bpersian gulf\b/i', '/\bbeirut\b/i',
    '/\blebanon\b/i', '/\bmissile/i', '/\bairstrike/i',
    '/\bnuclear\b/i', '/\benrichment\b/i', '/\bsanction/i',
    '/\bpentagon\b/i', '/\bcentcom\b/i', '/\bmiddle east\b/i',
    '/\bwest bank\b/i', '/\brafah\b/i', '/\bceasefire\b/i',
    '/\bmilitia/i', '/\bred sea\b/i', '/\byemen\b/i',
    '/\bescalation\b/i', '/\bretaliat/i', '/\bwar\b/i',
    '/\bairstrikes?\b/i', '/\bbombing\b/i', '/\bbombed\b/i',
    '/\bshelling\b/i', '/\binvasion\b/i', '/\boccupation\b/i',
];

/**
 * Check if cached data is still valid
 */
function getCachedData(): ?array {
    if (!file_exists(CACHE_FILE)) {
        return null;
    }
    $mtime = filemtime(CACHE_FILE);
    if ($mtime === false || (time() - $mtime) > CACHE_TTL) {
        return null;
    }
    $data = file_get_contents(CACHE_FILE);
    if ($data === false) {
        return null;
    }
    $decoded = json_decode($data, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Save data to cache
 */
function saveCache(array $data): void {
    if (!is_dir(CACHE_DIR)) {
        mkdir(CACHE_DIR, 0755, true);
    }
    file_put_contents(CACHE_FILE, json_encode($data), LOCK_EX);
}

/**
 * Fetch a single RSS feed via cURL
 */
function fetchFeed(string $url): ?string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => FETCH_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'NewsTickerBot/1.0 (+https://news.rexe.info)',
        CURLOPT_ENCODING       => '',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false || $httpCode >= 400) {
        return null;
    }
    return $result;
}

/**
 * Parse RSS/Atom XML into normalized items
 */
function parseFeed(string $xml, string $sourceName): array {
    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    if ($doc === false) {
        return [];
    }

    $items = [];

    // Standard RSS 2.0
    if (isset($doc->channel->item)) {
        foreach ($doc->channel->item as $item) {
            $title = trim((string)$item->title);
            $link  = trim((string)$item->link);
            $date  = (string)$item->pubDate;
            $desc  = strip_tags((string)$item->description);

            if ($title === '' || $link === '') continue;

            $items[] = [
                'title'     => htmlspecialchars(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'link'      => filter_var($link, FILTER_SANITIZE_URL),
                'source'    => $sourceName,
                'published' => $date ? strtotime($date) : time(),
                'snippet'   => htmlspecialchars(html_entity_decode(mb_substr($desc, 0, 400), ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ];
        }
    }

    // Atom format
    if (isset($doc->entry)) {
        foreach ($doc->entry as $entry) {
            $title = trim((string)$entry->title);
            $link  = '';
            if (isset($entry->link['href'])) {
                $link = trim((string)$entry->link['href']);
            }
            $date = (string)($entry->published ?? $entry->updated ?? '');
            $desc = strip_tags((string)($entry->summary ?? $entry->content ?? ''));

            if ($title === '' || $link === '') continue;

            $items[] = [
                'title'     => htmlspecialchars(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'link'      => filter_var($link, FILTER_SANITIZE_URL),
                'source'    => $sourceName,
                'published' => $date ? strtotime($date) : time(),
                'snippet'   => htmlspecialchars(html_entity_decode(mb_substr($desc, 0, 400), ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ];
        }
    }

    return $items;
}

/**
 * Check if an item is relevant to the Iran/Israel/US conflict
 */
function isRelevant(array $item, string $feedSource): bool {
    // Google News search feeds are already filtered by query
    if (str_contains($feedSource, 'Google News')) {
        return true;
    }
    // For general feeds, check keyword patterns with word boundaries
    global $keyword_patterns;
    $text = $item['title'] . ' ' . ($item['snippet'] ?? '');
    foreach ($keyword_patterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return true;
        }
    }
    return false;
}

/**
 * Deduplicate items by similar titles
 */
function deduplicateItems(array $items): array {
    $seen = [];
    $unique = [];
    foreach ($items as $item) {
        // Normalize title for comparison
        $normalized = strtolower(preg_replace('/[^a-z0-9]/', '', $item['title']));
        $key = substr($normalized, 0, 60);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[] = $item;
    }
    return $unique;
}

// Main execution
try {
    // Try cache first
    $cached = getCachedData();
    if ($cached !== null) {
        echo json_encode($cached);
        exit;
    }

    // Fetch all feeds
    $allItems = [];
    foreach ($feeds as $feed) {
        $xml = fetchFeed($feed['url']);
        if ($xml === null) continue;

        $parsed = parseFeed($xml, $feed['source']);
        foreach ($parsed as $item) {
            if (isRelevant($item, $feed['source'])) {
                $allItems[] = $item;
            }
        }
    }

    // Deduplicate
    $allItems = deduplicateItems($allItems);

    // Sort by date descending
    usort($allItems, fn($a, $b) => ($b['published'] ?? 0) <=> ($a['published'] ?? 0));

    // Limit
    $allItems = array_slice($allItems, 0, MAX_ITEMS);

    // Format timestamps for output
    $output = array_map(function ($item) {
        $item['time_ago'] = timeAgo($item['published'] ?? time());
        $item['published_fmt'] = date('D, d M H:i T', $item['published'] ?? time());
        return $item;
    }, $allItems);

    // Cache and return
    saveCache($output);
    echo json_encode($output);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Feed fetch failed']);
}

/**
 * Human-readable relative time
 */
function timeAgo(int $timestamp): string {
    $diff = time() - $timestamp;
    if ($diff < 0) return 'just now';
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('d M', $timestamp);
}
