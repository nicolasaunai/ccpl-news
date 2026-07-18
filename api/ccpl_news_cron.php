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

function ccpl_news_scrape_neopse(string $commune, string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CCPLNewsAggregator/1.0)',
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($errno !== 0 || $httpCode >= 400 || $body === false) {
        ccpl_news_log("FETCH_FAIL commune={$commune} url={$url} httpCode={$httpCode} curlError={$error}");
        return [];
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $body);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " marketList ")]');

    if ($nodes === false || $nodes->length === 0) {
        ccpl_news_log("PARSE_FAIL commune={$commune} url={$url} error=no_articles_found");
        return [];
    }

    $parsed = parse_url($url);
    $base = $parsed['scheme'] . '://' . $parsed['host'];

    $articles = [];
    foreach ($nodes as $node) {
        // Note : le template Néopse a un bug de balise <a> mal fermée juste avant le <h2>.
        // libxml (DOMDocument) récupère cette erreur en fusionnant l'attribut class du <h2>
        // sur le <a> lui-même et en supprimant le <h2> — vérifié empiriquement, pas une supposition.
        // Le titre ET le lien se trouvent donc tous les deux sur ce même <a class="card-title ...">.
        $titleNode = $xpath->query('.//a[contains(@class,"card-title")]', $node)->item(0);

        if ($titleNode === null) {
            continue;
        }

        $href = trim($titleNode->getAttribute('href'));
        $title = html_entity_decode(trim($titleNode->textContent), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($href === '' || $title === '') {
            continue;
        }

        $link = str_starts_with($href, 'http') ? $href : $base . $href;

        $articles[] = [
            'id' => $commune . ':' . $link,
            'commune' => $commune,
            'title' => $title,
            'url' => $link,
            'published' => null,
            'source' => 'scrape',
        ];
    }

    return $articles;
}

function ccpl_news_scrape_gometz(): array
{
    $commune = 'Gometz-la-Ville';
    $url = 'https://mairie-gometzlaville.fr/toutes-les-actualites/';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CCPLNewsAggregator/1.0)',
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($errno !== 0 || $httpCode >= 400 || $body === false) {
        ccpl_news_log("FETCH_FAIL commune={$commune} url={$url} httpCode={$httpCode} curlError={$error}");
        return [];
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $body);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//div[@id="all-actus"]//div[contains(concat(" ", normalize-space(@class), " "), " jet-listing-grid__item ")]');

    if ($nodes === false || $nodes->length === 0) {
        ccpl_news_log("PARSE_FAIL commune={$commune} url={$url} error=no_articles_found");
        return [];
    }

    $articles = [];
    foreach ($nodes as $node) {
        $linkNode = $xpath->query('.//a[contains(@class,"jet-engine-listing-overlay-link")]/@href', $node)->item(0);
        $titleNodes = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " jet-listing-dynamic-field__content ")]', $node);
        $timeNode = $xpath->query('.//div[contains(@class,"jet-listing-dynamic-meta__date")]//time/@datetime', $node)->item(0);

        if ($linkNode === null || $titleNodes->length === 0 || $timeNode === null) {
            continue;
        }

        $link = trim($linkNode->nodeValue);
        $title = html_entity_decode(trim($titleNodes->item(0)->textContent), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $isoDate = trim($timeNode->nodeValue);
        $timestamp = strtotime($isoDate);

        if ($link === '' || $title === '' || $timestamp === false) {
            continue;
        }

        $articles[] = [
            'id' => $commune . ':' . $link,
            'commune' => $commune,
            'title' => $title,
            'url' => $link,
            'published' => date('c', $timestamp),
            'source' => 'scrape',
        ];
    }

    return $articles;
}

function ccpl_news_scrape_saintjean(): array
{
    $commune = 'Saint-Jean-de-Beauregard';
    $url = 'https://mairie-saintjeandebeauregard.fr/actualites/';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CCPLNewsAggregator/1.0)',
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($errno !== 0 || $httpCode >= 400 || $body === false) {
        ccpl_news_log("FETCH_FAIL commune={$commune} url={$url} httpCode={$httpCode} curlError={$error}");
        return [];
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $body);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//article[contains(concat(" ", normalize-space(@class), " "), " news-card ")]');

    if ($nodes === false || $nodes->length === 0) {
        ccpl_news_log("PARSE_FAIL commune={$commune} url={$url} error=no_articles_found");
        return [];
    }

    $articles = [];
    foreach ($nodes as $node) {
        $linkNode = $xpath->query('.//h2[contains(@class,"news-card__title")]/a', $node)->item(0);
        $timeNode = $xpath->query('.//time[contains(@class,"news-card__date")]/@datetime', $node)->item(0);

        if ($linkNode === null || $timeNode === null) {
            continue;
        }

        $link = trim($linkNode->getAttribute('href'));
        $title = html_entity_decode(trim($linkNode->textContent), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $isoDate = trim($timeNode->nodeValue);
        $timestamp = strtotime($isoDate);

        if ($link === '' || $title === '' || $timestamp === false) {
            continue;
        }

        $articles[] = [
            'id' => $commune . ':' . $link,
            'commune' => $commune,
            'title' => $title,
            'url' => $link,
            'published' => date('c', $timestamp),
            'source' => 'scrape',
        ];
    }

    return $articles;
}

function ccpl_news_parse_french_date(string $text): int|false
{
    static $months = [
        'janvier' => 1, 'février' => 2, 'mars' => 3, 'avril' => 4,
        'mai' => 5, 'juin' => 6, 'juillet' => 7, 'août' => 8,
        'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'décembre' => 12,
    ];

    if (!preg_match('/(\d{1,2})\s+([A-Za-zÀ-ÿ]+)\s+(\d{4})/u', trim($text), $matches)) {
        return false;
    }

    $day = (int) $matches[1];
    $monthName = mb_strtolower($matches[2], 'UTF-8');
    $year = (int) $matches[3];

    if (!isset($months[$monthName])) {
        return false;
    }

    return mktime(0, 0, 0, $months[$monthName], $day, $year);
}

function ccpl_news_scrape_vaugrigneuse(): array
{
    $commune = 'Vaugrigneuse';
    $url = 'https://www.ville-vaugrigneuse.fr/actualites/index.php';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CCPLNewsAggregator/1.0)',
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($errno !== 0 || $httpCode >= 400 || $body === false) {
        ccpl_news_log("FETCH_FAIL commune={$commune} url={$url} httpCode={$httpCode} curlError={$error}");
        return [];
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $body);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query("//div[@class='mb-50']");

    if ($nodes === false || $nodes->length === 0) {
        ccpl_news_log("PARSE_FAIL commune={$commune} url={$url} error=no_articles_found");
        return [];
    }

    $articles = [];
    foreach ($nodes as $node) {
        $titleNode = $xpath->query('.//h3', $node)->item(0);
        $timeNode = $xpath->query('.//time', $node)->item(0);
        $anchorNode = $xpath->query('preceding-sibling::a[@name][1]', $node)->item(0);

        if ($titleNode === null || $timeNode === null || $anchorNode === null) {
            continue;
        }

        $title = html_entity_decode(trim($titleNode->textContent), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $timestamp = ccpl_news_parse_french_date($timeNode->textContent);
        $anchorId = $anchorNode->getAttribute('name');

        if ($title === '' || $timestamp === false || $anchorId === '') {
            continue;
        }

        $link = $url . '#' . $anchorId;

        $articles[] = [
            'id' => $commune . ':' . $link,
            'commune' => $commune,
            'title' => $title,
            'url' => $link,
            'published' => date('c', $timestamp),
            'source' => 'scrape',
        ];
    }

    return $articles;
}

function ccpl_news_scrape_ccpl(): array
{
    $commune = 'CCPL';
    $url = 'https://www.cc-paysdelimours.fr/actualites';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CCPLNewsAggregator/1.0)',
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($errno !== 0 || $httpCode >= 400 || $body === false) {
        ccpl_news_log("FETCH_FAIL commune={$commune} url={$url} httpCode={$httpCode} curlError={$error}");
        return [];
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $body);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " news ") and contains(concat(" ", normalize-space(@class), " "), " col-lg-4 ")]');

    if ($nodes === false || $nodes->length === 0) {
        ccpl_news_log("PARSE_FAIL commune={$commune} url={$url} error=no_articles_found");
        return [];
    }

    $articles = [];
    foreach ($nodes as $node) {
        $linkNode = $xpath->query('.//a[contains(@class,"news-image-wrapper")]/@href', $node)->item(0);
        $titleNode = $xpath->query('.//h3[contains(@class,"news-title")]', $node)->item(0);

        if ($linkNode === null || $titleNode === null) {
            continue;
        }

        $link = trim($linkNode->nodeValue);
        $title = html_entity_decode(trim($titleNode->textContent), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($link === '' || $title === '') {
            continue;
        }

        $articles[] = [
            'id' => $commune . ':' . $link,
            'commune' => $commune,
            'title' => $title,
            'url' => $link,
            'published' => null,
            'source' => 'scrape',
        ];
    }

    return $articles;
}

function ccpl_news_resolve_missing_dates(array $incoming, array $existing): array
{
    $existingById = [];
    foreach ($existing as $article) {
        $existingById[$article['id']] = $article;
    }

    foreach ($incoming as &$article) {
        if ($article['published'] === null) {
            $article['published'] = $existingById[$article['id']]['published'] ?? date('c');
        }
    }
    unset($article);

    return $incoming;
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

    $scrapeSources = [
        'Angervilliers' => fn() => ccpl_news_scrape_neopse('Angervilliers', 'https://ville-angervilliers.fr/fr/nw/488373/actualites-407'),
        'Saint-Maurice-Montcouronne' => fn() => ccpl_news_scrape_neopse('Saint-Maurice-Montcouronne', 'http://www.mairie-saint-maurice-montcouronne.fr/fr/nw/1764336/actualites-260'),
        'Gometz-la-Ville' => 'ccpl_news_scrape_gometz',
        'Saint-Jean-de-Beauregard' => 'ccpl_news_scrape_saintjean',
        'Vaugrigneuse' => 'ccpl_news_scrape_vaugrigneuse',
        'CCPL' => 'ccpl_news_scrape_ccpl',
    ];

    foreach ($scrapeSources as $commune => $scraper) {
        $items = $scraper();
        ccpl_news_log("FEED_OK commune={$commune} items=" . count($items));
        $incoming = array_merge($incoming, $items);
    }

    $incoming = ccpl_news_resolve_missing_dates($incoming, $existing);

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
