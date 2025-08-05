<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

class CarScraper {
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
    
    public function searchCars($site, $brand, $model = '', $maxPrice = null) {
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
                throw new Exception('Unsupported site');
        }
    }
    
    private function makeRequest($url, $headers = []) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
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
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            
            $results = [];
            $ads = $xpath->query('//article[contains(@class, "ads__unit")]');
            
            foreach ($ads as $ad) {
                $titleNode = $xpath->query('.//h2[contains(@class, "ads__unit__content__title")]/a', $ad)->item(0);
                $priceNode = $xpath->query('.//*[contains(@class, "ads__unit__content__price")]', $ad)->item(0);
                $detailsNode = $xpath->query('.//*[contains(@class, "ads__unit__content__details")]', $ad)->item(0);
                
                if ($titleNode && $priceNode) {
                    $title = trim($titleNode->textContent);
                    $price = trim($priceNode->textContent);
                    $url = 'https://www.finn.no' . $titleNode->getAttribute('href');
                    $details = $detailsNode ? trim($detailsNode->textContent) : '';
                    
                    // Extract year and mileage from details
                    $year = '';
                    $mileage = '';
                    if (preg_match('/(\d{4})/', $details, $matches)) {
                        $year = $matches[1];
                    }
                    if (preg_match('/(\d+[\s,]*\d*)\s*km/', $details, $matches)) {
                        $mileage = $matches[1] . ' km';
                    }
                    
                    $results[] = [
                        'title' => $title,
                        'price' => $price,
                        'year' => $year,
                        'mileage' => $mileage,
                        'location' => '',
                        'engine' => '',
                        'url' => $url
                    ];
                }
                
                if (count($results) >= 10) break;
            }
            
            return $results;
        } catch (Exception $e) {
            return ['error' => 'Failed to search FINN.no: ' . $e->getMessage()];
        }
    }
    
    private function searchAuto24($brand, $model, $maxPrice) {
        try {
            $query = urlencode(trim("$brand $model"));
            $url = "https://www.auto24.ee/used/search?q=$query";
            
            if ($maxPrice) {
                $url .= "&price_max=$maxPrice";
            }
            
            $html = $this->makeRequest($url);
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            
            $results = [];
            $ads = $xpath->query('//div[contains(@class, "result-row")]');
            
            foreach ($ads as $ad) {
                $titleNode = $xpath->query('.//a[contains(@class, "result-title")]', $ad)->item(0);
                $priceNode = $xpath->query('.//*[contains(@class, "result-price")]', $ad)->item(0);
                
                if ($titleNode && $priceNode) {
                    $title = trim($titleNode->textContent);
                    $price = trim($priceNode->textContent);
                    $url = 'https://www.auto24.ee' . $titleNode->getAttribute('href');
                    
                    $results[] = [
                        'title' => $title,
                        'price' => $price,
                        'year' => '',
                        'mileage' => '',
                        'location' => 'Estonia',
                        'engine' => '',
                        'url' => $url
                    ];
                }
                
                if (count($results) >= 10) break;
            }
            
            return $results;
        } catch (Exception $e) {
            return ['error' => 'Failed to search Auto24: ' . $e->getMessage()];
        }
    }
    
    private function searchSS($brand, $model, $maxPrice) {
        try {
            $query = urlencode(trim("$brand $model"));
            $url = "https://www.ss.lv/lv/transport/cars/search/?q=$query";
            
            $html = $this->makeRequest($url);
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            
            $results = [];
            $rows = $xpath->query('//tr[contains(@id, "tr_")]');
            
            foreach ($rows as $row) {
                $titleNode = $xpath->query('.//a[contains(@class, "am")]', $row)->item(0);
                $priceNode = $xpath->query('.//*[contains(@class, "price")]', $row)->item(0);
                
                if ($titleNode && $priceNode) {
                    $title = trim($titleNode->textContent);
                    $price = trim($priceNode->textContent);
                    $url = 'https://www.ss.lv' . $titleNode->getAttribute('href');
                    
                    // Filter by max price if specified
                    if ($maxPrice) {
                        $numericPrice = (int) preg_replace('/[^\d]/', '', $price);
                        if ($numericPrice > $maxPrice) continue;
                    }
                    
                    $results[] = [
                        'title' => $title,
                        'price' => $price,
                        'year' => '',
                        'mileage' => '',
                        'location' => 'Latvia',
                        'engine' => '',
                        'url' => $url
                    ];
                }
                
                if (count($results) >= 10) break;
            }
            
            return $results;
        } catch (Exception $e) {
            return ['error' => 'Failed to search SS.lv: ' . $e->getMessage()];
        }
    }
    
    private function searchAutoplius($brand, $model, $maxPrice) {
        try {
            $query = urlencode(trim("$brand $model"));
            $url = "https://lv.m.autoplius.lt/skelbimai/search?q=$query";
            
            $html = $this->makeRequest($url);
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            
            $results = [];
            $ads = $xpath->query('//div[contains(@class, "announcement-item")]');
            
            foreach ($ads as $ad) {
                $titleNode = $xpath->query('.//a[contains(@class, "announcement-title")]', $ad)->item(0);
                $priceNode = $xpath->query('.//*[contains(@class, "announcement-price")]', $ad)->item(0);
                
                if ($titleNode && $priceNode) {
                    $title = trim($titleNode->textContent);
                    $price = trim($priceNode->textContent);
                    $url = $titleNode->getAttribute('href');
                    
                    if (!str_starts_with($url, 'http')) {
                        $url = 'https://lv.m.autoplius.lt' . $url;
                    }
                    
                    $results[] = [
                        'title' => $title,
                        'price' => $price,
                        'year' => '',
                        'mileage' => '',
                        'location' => 'Lithuania',
                        'engine' => '',
                        'url' => $url
                    ];
                }
                
                if (count($results) >= 10) break;
            }
            
            return $results;
        } catch (Exception $e) {
            return ['error' => 'Failed to search Autoplius: ' . $e->getMessage()];
        }
    }
    
    private function searchAutoScout24($brand, $model, $maxPrice) {
        try {
            $query = urlencode(trim("$brand $model"));
            $url = "https://www.autoscout24.com/lst/$query";
            
            if ($maxPrice) {
                $url .= "?priceto=$maxPrice";
            }
            
            $html = $this->makeRequest($url);
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            
            $results = [];
            $ads = $xpath->query('//div[contains(@class, "ListItem_article")]');
            
            foreach ($ads as $ad) {
                $titleNode = $xpath->query('.//h2[contains(@class, "ListItem_title")]/a', $ad)->item(0);
                $priceNode = $xpath->query('.//*[contains(@class, "Price_price")]', $ad)->item(0);
                $detailsNode = $xpath->query('.//*[contains(@class, "VehicleDetailTable")]', $ad)->item(0);
                
                if ($titleNode && $priceNode) {
                    $title = trim($titleNode->textContent);
                    $price = trim($priceNode->textContent);
                    $url = 'https://www.autoscout24.com' . $titleNode->getAttribute('href');
                    $details = $detailsNode ? trim($detailsNode->textContent) : '';
                    
                    // Extract details
                    $year = '';
                    $mileage = '';
                    $engine = '';
                    
                    if (preg_match('/(\d{4})/', $details, $matches)) {
                        $year = $matches[1];
                    }
                    if (preg_match('/(\d+[\s,]*\d*)\s*km/', $details, $matches)) {
                        $mileage = $matches[1] . ' km';
                    }
                    if (preg_match('/(Petrol|Diesel|Electric|Hybrid)/i', $details, $matches)) {
                        $engine = $matches[1];
                    }
                    
                    $results[] = [
                        'title' => $title,
                        'price' => $price,
                        'year' => $year,
                        'mileage' => $mileage,
                        'location' => 'Europe',
                        'engine' => $engine,
                        'url' => $url
                    ];
                }
                
                if (count($results) >= 10) break;
            }
            
            return $results;
        } catch (Exception $e) {
            return ['error' => 'Failed to search AutoScout24: ' . $e->getMessage()];
        }
    }
    
    private function searchMobile($brand, $model, $maxPrice) {
        try {
            $query = urlencode(trim("$brand $model"));
            $url = "https://suchen.mobile.de/fahrzeuge/search.html?dam=0&isSearchRequest=true&ms=$query";
            
            if ($maxPrice) {
                $url .= "&p=$maxPrice";
            }
            
            $html = $this->makeRequest($url);
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            
            $results = [];
            $ads = $xpath->query('//div[contains(@class, "result-item")]');
            
            foreach ($ads as $ad) {
                $titleNode = $xpath->query('.//h2[contains(@class, "result-title")]/a', $ad)->item(0);
                $priceNode = $xpath->query('.//*[contains(@class, "price-block")]', $ad)->item(0);
                $detailsNode = $xpath->query('.//*[contains(@class, "vehicle-data")]', $ad)->item(0);
                
                if ($titleNode && $priceNode) {
                    $title = trim($titleNode->textContent);
                    $price = trim($priceNode->textContent);
                    $url = 'https://suchen.mobile.de' . $titleNode->getAttribute('href');
                    $details = $detailsNode ? trim($detailsNode->textContent) : '';
                    
                    // Extract details
                    $year = '';
                    $mileage = '';
                    $engine = '';
                    
                    if (preg_match('/(\d{4})/', $details, $matches)) {
                        $year = $matches[1];
                    }
                    if (preg_match('/(\d+[\s.]*\d*)\s*km/', $details, $matches)) {
                        $mileage = str_replace('.', ',', $matches[1]) . ' km';
                    }
                    if (preg_match('/(Benzin|Diesel|Elektro|Hybrid)/i', $details, $matches)) {
                        $engineMap = [
                            'Benzin' => 'Petrol',
                            'Diesel' => 'Diesel',
                            'Elektro' => 'Electric',
                            'Hybrid' => 'Hybrid'
                        ];
                        $engine = $engineMap[$matches[1]] ?? $matches[1];
                    }
                    
                    $results[] = [
                        'title' => $title,
                        'price' => $price,
                        'year' => $year,
                        'mileage' => $mileage,
                        'location' => 'Germany',
                        'engine' => $engine,
                        'url' => $url
                    ];
                }
                
                if (count($results) >= 10) break;
            }
            
            return $results;
        } catch (Exception $e) {
            return ['error' => 'Failed to search Mobile.de: ' . $e->getMessage()];
        }
    }
}

// Main execution
try {
    $input = json_decode(file_get_contents('php://input'), true);
    $site = $_GET['site'] ?? '';
    $brand = $input['brand'] ?? '';
    $model = $input['model'] ?? '';
    $maxPrice = $input['maxPrice'] ?? null;
    
    if (empty($site) || empty($brand)) {
        throw new Exception('Site and brand parameters are required');
    }
    
    $scraper = new CarScraper();
    $results = $scraper->searchCars($site, $brand, $model, $maxPrice);
    
    echo json_encode([
        'success' => true,
        'results' => $results,
        'site' => $site,
        'query' => trim("$brand $model")
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}