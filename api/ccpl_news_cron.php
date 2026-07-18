<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

function ccpl_news_log(string $message): void
{
    $line = '[' . date('c') . '] ' . $message . PHP_EOL;
    file_put_contents(__DIR__ . '/ccpl_news_cron.log', $line, FILE_APPEND | LOCK_EX);
}

function ccpl_news_fetch_feed(string $commune, string $feedUrl): array
{
    $ch = curl_init($feedUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'CCPLNewsAggregator/1.0 (+https://courson-monteloup.fr/)',
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($errno !== 0 || $httpCode >= 400 || $body === false) {
        ccpl_news_log("FETCH_FAIL commune={$commune} url={$feedUrl} httpCode={$httpCode} curlError={$error}");
        return [];
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    if ($xml === false) {
        $xmlErrors = array_map(fn($e) => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        ccpl_news_log("PARSE_FAIL commune={$commune} url={$feedUrl} errors=" . implode('; ', $xmlErrors));
        return [];
    }

    $items = $xml->channel->item ?? [];
    $articles = [];
    foreach ($items as $item) {
        $link = trim((string) $item->link);
        $title = html_entity_decode(trim((string) $item->title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $pubDate = trim((string) $item->pubDate);
        if ($link === '' || $title === '') {
            continue;
        }
        $timestamp = strtotime($pubDate);
        if ($timestamp === false) {
            continue;
        }
        $published = date('c', $timestamp);
        $articles[] = [
            'id' => $commune . ':' . $link,
            'commune' => $commune,
            'title' => $title,
            'url' => $link,
            'published' => $published,
            'source' => 'rss',
        ];
    }

    return $articles;
}

function ccpl_news_merge(array $existing, array $incoming, int $retentionDays = 30): array
{
    $byId = [];
    foreach (array_merge($existing, $incoming) as $article) {
        $byId[$article['id']] = $article;
    }

    $cutoff = strtotime("-{$retentionDays} days");
    $merged = array_values(array_filter($byId, function (array $article) use ($cutoff) {
        $ts = strtotime($article['published']);
        return $ts !== false && $ts >= $cutoff;
    }));

    usort($merged, function (array $a, array $b) {
        return strtotime($b['published']) <=> strtotime($a['published']);
    });

    return $merged;
}

const CCPL_NEWS_FEEDS = [
    'Boullay-les-Troux' => 'https://www.boullay-les-troux.fr/fr/rss',
    'Briis-sous-Forges' => 'https://www.briis.fr/category/actualite/feed/',
    'Fontenay-lès-Briis' => 'https://www.fontenay-les-briis.fr/category/actualites/feed/',
    'Forges-les-Bains' => 'https://www.forges-les-bains.fr/feed/?post_type=post',
    'Les Molières' => 'https://www.lesmolieres.fr/feed/',
    'Limours' => 'https://www.mairie-limours.fr/feed/',
    'Pecqueuse' => 'https://mairie-pecqueuse.fr/feed/',
];

function ccpl_news_run(string $dataFile): void
{
    $existingRaw = @file_get_contents($dataFile);
    $existing = [];
    if ($existingRaw !== false) {
        $decoded = json_decode($existingRaw, true);
        if (is_array($decoded) && isset($decoded['articles']) && is_array($decoded['articles'])) {
            $existing = $decoded['articles'];
        }
    }

    $incoming = [];
    foreach (CCPL_NEWS_FEEDS as $commune => $feedUrl) {
        $items = ccpl_news_fetch_feed($commune, $feedUrl);
        ccpl_news_log("FEED_OK commune={$commune} items=" . count($items));
        $incoming = array_merge($incoming, $items);
    }

    $merged = ccpl_news_merge($existing, $incoming);

    $output = [
        'generated_at' => date('c'),
        'articles' => $merged,
    ];

    file_put_contents(
        $dataFile,
        json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    ccpl_news_log('RUN_OK articles=' . count($merged));
}

ccpl_news_run(__DIR__ . '/../data/ccpl_news.json');
