<?php
/**
 * predictions.php — AI War Prediction Engine
 *
 * Reads the existing feed cache, sends top headlines to Claude Haiku,
 * returns structured predictions for 9 categories.
 * Caches predictions for 30 minutes.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');

// ── Config ──────────────────────────────────────────────────────────
define('FEED_CACHE',       '/tmp/news_ticker_cache/feed_cache.json');
define('PRED_CACHE_DIR',   '/tmp/news_ticker_cache');
define('PRED_CACHE_FILE',  PRED_CACHE_DIR . '/predictions_cache.json');
define('PRED_CACHE_TTL',   1800); // 30 minutes
define('STALE_TTL',        7200); // serve stale up to 2 hours
define('MAX_HEADLINES',    20);
define('API_TIMEOUT',      30);
define('CLAUDE_MODEL',     'claude-haiku-4-5-20251001');
define('MAX_TOKENS',       2048);

// ── Load API key from .env ──────────────────────────────────────────
function loadApiKey(): ?string {
    $envFile = dirname(__DIR__) . '/.env';
    if (!file_exists($envFile)) return null;
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (str_starts_with($line, 'ANTHROPIC_API_KEY=')) {
            return substr($line, strlen('ANTHROPIC_API_KEY='));
        }
    }
    return null;
}

// ── Read feed cache (no duplicate RSS fetching) ─────────────────────
function getHeadlines(): array {
    if (!file_exists(FEED_CACHE)) return [];
    $data = json_decode(file_get_contents(FEED_CACHE), true);
    if (!is_array($data)) return [];
    return array_slice($data, 0, MAX_HEADLINES);
}

// ── Prediction cache ────────────────────────────────────────────────
function getCachedPredictions(bool $allowStale = false): ?array {
    if (!file_exists(PRED_CACHE_FILE)) return null;
    $mtime = filemtime(PRED_CACHE_FILE);
    if ($mtime === false) return null;
    $age = time() - $mtime;
    $ttl = $allowStale ? STALE_TTL : PRED_CACHE_TTL;
    if ($age > $ttl) return null;
    $data = json_decode(file_get_contents(PRED_CACHE_FILE), true);
    return is_array($data) ? $data : null;
}

function savePredictions(array $data): void {
    if (!is_dir(PRED_CACHE_DIR)) mkdir(PRED_CACHE_DIR, 0755, true);
    file_put_contents(PRED_CACHE_FILE, json_encode($data), LOCK_EX);
}

// ── Call Claude API ─────────────────────────────────────────────────
function callClaude(string $apiKey, array $headlines): ?array {
    $headlineText = '';
    foreach ($headlines as $i => $h) {
        $n = $i + 1;
        $headlineText .= "{$n}. {$h['title']} ({$h['source']}, {$h['time_ago']})\n";
    }

    $systemPrompt = <<<'SYS'
You are a geopolitical military analyst AI. Analyze the provided headlines about the Iran/Israel/US conflict and generate predictions across 9 categories. Be data-driven and base assessments on the tone, content, and frequency of the headlines.

Return ONLY valid JSON — no markdown fences, no commentary, no explanation. The JSON must match the exact schema provided.
SYS;

    $userPrompt = <<<USER
Here are the latest {$n} headlines from the Iran/Israel/US conflict:

{$headlineText}

Analyze these headlines and return predictions in this exact JSON schema:

{
  "war_duration": {
    "estimate": "string — e.g. '3-6 months', 'Ongoing indefinitely'",
    "detail": "string — brief explanation",
    "confidence": number between 0-100,
    "trend": "escalating" | "stable" | "de-escalating"
  },
  "predicted_outcome": {
    "summary": "string — most likely outcome in 1-2 sentences",
    "confidence": number between 0-100,
    "trend": "escalating" | "stable" | "de-escalating"
  },
  "casualties": {
    "civilian_range": "string — e.g. '10,000-50,000'",
    "military_range": "string — e.g. '5,000-15,000'",
    "injuries_range": "string — e.g. '50,000-100,000'",
    "detail": "string — brief context",
    "trend": "increasing" | "stable" | "decreasing"
  },
  "country_involvement": {
    "direct": number — count of directly involved nations,
    "proxy": number — count of proxy-involved nations,
    "at_risk": number — count of at-risk nations,
    "key_players": "string — top 3-4 countries",
    "trend": "expanding" | "stable" | "contracting"
  },
  "ww3_probability": {
    "percentage": number between 0-100,
    "detail": "string — key escalation/de-escalation factor",
    "trend": "increasing" | "stable" | "decreasing"
  },
  "economic_impact": {
    "severity": "critical" | "severe" | "moderate" | "low",
    "oil_impact": "string — e.g. 'Oil prices +15-25%'",
    "detail": "string — broader economic effects",
    "trend": "worsening" | "stable" | "improving"
  },
  "humanitarian_crisis": {
    "severity": "critical" | "severe" | "moderate" | "low",
    "displaced": "string — e.g. '2-5 million'",
    "detail": "string — aid/refugee situation",
    "trend": "worsening" | "stable" | "improving"
  },
  "nuclear_risk": {
    "percentage": number between 0-100,
    "primary_risk": "string — main nuclear concern",
    "detail": "string — context",
    "trend": "increasing" | "stable" | "decreasing"
  },
  "cyber_proxy": {
    "severity": "critical" | "severe" | "moderate" | "low",
    "summary": "string — key cyber/proxy developments",
    "detail": "string — specifics",
    "trend": "increasing" | "stable" | "decreasing"
  }
}
USER;

    $payload = json_encode([
        'model'      => CLAUDE_MODEL,
        'max_tokens' => MAX_TOKENS,
        'system'     => $systemPrompt,
        'messages'   => [
            ['role' => 'user', 'content' => $userPrompt],
        ],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => API_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        error_log("Claude API error: HTTP {$httpCode}, curl: {$curlError}");
        return null;
    }

    $body = json_decode($response, true);
    if (!isset($body['content'][0]['text'])) {
        error_log("Claude API unexpected response structure");
        return null;
    }

    $text = $body['content'][0]['text'];

    // Strip markdown fences if present
    $text = preg_replace('/^```(?:json)?\s*\n?/m', '', $text);
    $text = preg_replace('/\n?```\s*$/m', '', $text);
    $text = trim($text);

    $predictions = json_decode($text, true);
    if (!is_array($predictions)) {
        error_log("Claude API returned invalid JSON: " . substr($text, 0, 200));
        return null;
    }

    return $predictions;
}

// ── Main ────────────────────────────────────────────────────────────
try {
    // 1. Try fresh cache
    $cached = getCachedPredictions(false);
    if ($cached !== null) {
        echo json_encode([
            'status'      => 'ok',
            'source'      => 'cache',
            'generated'   => $cached['generated'] ?? null,
            'predictions' => $cached['predictions'] ?? $cached,
        ]);
        exit;
    }

    // 2. Load API key
    $apiKey = loadApiKey();
    if (!$apiKey) {
        // Try stale cache
        $stale = getCachedPredictions(true);
        if ($stale) {
            echo json_encode([
                'status'      => 'ok',
                'source'      => 'stale_cache',
                'generated'   => $stale['generated'] ?? null,
                'predictions' => $stale['predictions'] ?? $stale,
            ]);
            exit;
        }
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'API key not configured']);
        exit;
    }

    // 3. Get headlines
    $headlines = getHeadlines();
    if (empty($headlines)) {
        $stale = getCachedPredictions(true);
        if ($stale) {
            echo json_encode([
                'status'      => 'ok',
                'source'      => 'stale_cache',
                'generated'   => $stale['generated'] ?? null,
                'predictions' => $stale['predictions'] ?? $stale,
            ]);
            exit;
        }
        http_response_code(503);
        echo json_encode(['status' => 'error', 'message' => 'No headlines available']);
        exit;
    }

    // 4. Call Claude
    $predictions = callClaude($apiKey, $headlines);
    if ($predictions === null) {
        // API failed — try stale cache
        $stale = getCachedPredictions(true);
        if ($stale) {
            echo json_encode([
                'status'      => 'ok',
                'source'      => 'stale_cache',
                'generated'   => $stale['generated'] ?? null,
                'predictions' => $stale['predictions'] ?? $stale,
            ]);
            exit;
        }
        http_response_code(502);
        echo json_encode(['status' => 'error', 'message' => 'Prediction generation failed']);
        exit;
    }

    // 5. Cache and return
    $result = [
        'generated'   => date('c'),
        'predictions' => $predictions,
    ];
    savePredictions($result);

    echo json_encode([
        'status'      => 'ok',
        'source'      => 'fresh',
        'generated'   => $result['generated'],
        'predictions' => $predictions,
    ]);

} catch (\Throwable $e) {
    error_log("predictions.php error: " . $e->getMessage());
    $stale = getCachedPredictions(true);
    if ($stale) {
        echo json_encode([
            'status'      => 'ok',
            'source'      => 'stale_cache',
            'generated'   => $stale['generated'] ?? null,
            'predictions' => $stale['predictions'] ?? $stale,
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Internal error']);
    }
}
