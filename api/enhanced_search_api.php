<?php
require_once '../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

class EnhancedCarScraper {
    private $cache;
    private $rateLimiter;
    private $logger;
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
    
    public function __construct() {
        $this->cache = new Cache();
        $this->rateLimiter = new RateLimiter();
        $this->logger = new Logger();
    }
    
    public function searchCars($site, $brand, $model = '', $maxPrice = null) {
        $startTime = microtime(true);
        
        // Create cache key
        $cacheKey = $this->generateCacheKey($site, $brand, $model, $maxPrice);
        
        // Try to get from cache first
        $cachedResults = $this->cache->get($cacheKey);
        if ($cachedResults !== null) {
            $this->logger->info("Cache hit for search", [
                'site' => $site,
                'brand' => $brand,
                'model' => $model,
                'cache_key' => $cacheKey
            ]);
            return $cachedResults;
        }
        
        // Perform actual search
        try {
            $results = $this->performSearch($site, $brand, $model, $maxPrice);
            
            // Cache the results
            $this->cache->set($cacheKey, $results, 1800); // Cache for 30 minutes
            
            // Log search
            $duration = (microtime(true) - $startTime) * 1000;
            $this->logSearch($site, $brand, $model, $maxPrice, count($results), $duration);
            
            return $results;
            
        } catch (Exception $e) {
            $this->logger->error("Search failed", [
                'site' => $site,
                'brand' => $brand,
                'model' => $model,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    private function generateCacheKey($site, $brand, $model, $maxPrice) {
        return 'search_' . md5($site . '_' . strtolower($brand) . '_' . strtolower($model) . '_' . $maxPrice);
    }
    
    private function logSearch($site, $brand, $model, $maxPrice, $resultCount, $duration) {
        try {
            $db = Database::getInstance();
            $db->query(
                "INSERT INTO search_history (ip_address, brand, model, max_price, sites_searched, results_count, search_duration_ms) 
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    getUserIP(),
                    $brand,
                    $model,
                    $maxPrice,
                    json_encode([$site]),
                    $resultCount,
                    round($duration)
                ]
            );
            
            // Update popular searches
            $db->query(
                "INSERT INTO popular_searches (brand, model, search_count) VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE search_count = search_count + 1",
                [$brand, $model]
            );
            
        } catch (Exception $e) {
            $this->logger->error("Failed to log search", ['error' => $e->getMessage()]);
        }
    }
    
    private function performSearch($site, $brand, $model, $maxPrice) {
        switch ($site) {
            case 'finn':
                return $this->searchFinn($brand, $model, $maxPrice);
            case 'auto24':
                return $this->searchAuto24($brand, $model, $maxPrice);
            case 'ss':
                return $this->searchSS($brand, $model, $maxPrice);
            case 'autoplius':
                return $this->searchAutoplius($brand, $model, $maxPrice);
            case 'autoscout24':
                return $this->searchAutoScout24($brand, $model, $maxPrice);
            case 'mobile':
                return $this->searchMobile($brand, $model, $maxPrice);
            default:
                throw new Exception('Unsupported site: ' . $site);
        }
    }
    
    private function makeRequest($url, $headers = []) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_HTTPHEADER => array_merge([
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5',
                'Accept-Encoding: gzip, deflate',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1',
            ], $headers)
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false || !empty($error)) {
            throw new Exception("cURL Error: $error");
        }
        
        if ($httpCode !== 200) {
            throw new Exception("HTTP Error: $httpCode");
        }
        
        return $response;
    }
    
    private function searchFinn($brand, $model, $maxPrice) {
        try {
            $query = urlencode(trim("$brand $model"));
            $url = "https://www.finn.no/car/used/search.html?q=$query";
            
            if ($maxPrice) {
                $url .= "&price_to=$maxPrice";
            }
            
            $html = $this->makeRequest($url);
            
            // Use regex for better parsing when DOM parsing fails
            $results = [];
            
            // Look for JSON data in the page (FINN often embeds data)
            if (preg_match('/window\.__INITIAL_STATE__\s*=\s*({.+?});/', $html, $matches)) {
                $jsonData = json_decode($matches[1], true);
                if (isset($jsonData['searchResult']['ads'])) {
                    foreach ($jsonData['searchResult']['ads'] as $ad) {
                        if (count($results) >= 10) break;
                        
                        $results[] = [
                            'title' => $ad['heading'] ?? 'N/A',
                            'price' => isset($ad['price']['amount']) ? 'kr ' . number_format($ad['price']['amount']) : 'N/A',
                            'year' => $ad['year'] ?? '',
                            'mileage' => isset($ad['mileage']) ? number_format($ad['mileage']) . ' km' : '',
                            'location' => $ad['location'] ?? '',
                            'engine' => $ad['engineType'] ?? '',
                            'url' => 'https://www.finn.no' . ($ad['canonical_url'] ?? '#')
                        ];
                    }
                }
            }
            
            // Fallback to DOM parsing if JSON parsing fails
            if (empty($results)) {
                $dom = new DOMDocument();
                @$dom->loadHTML($html);
                $xpath = new DOMXPath($dom);
                
                $ads = $xpath->query('//article[contains(@class, "ads__unit")]');
                
                foreach ($ads as $ad) {
                    if (count($results) >= 10) break;
                    
                    $titleNode = $xpath->query('.//h2//a', $ad)->item(0);
                    $priceNode = $xpath->query('.//*[contains(@class, "ads__unit__content__price")]', $ad)->item(0);
                    
                    if ($titleNode && $priceNode) {
                        $title = trim($titleNode->textContent);
                        $price = trim($pr