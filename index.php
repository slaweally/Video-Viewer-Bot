<?php
// video-viewer.php - Enhanced Video Viewer Bot with Proxy Support

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session only once
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Log file
$logFile = 'video_viewer.log';

// Logging function
function logMessage($message) {
    global $logFile;
    $timestamp = date('[Y-m-d H:i:s]');
    $log = "$timestamp $message" . PHP_EOL;
    file_put_contents($logFile, $log, FILE_APPEND);
    return $log;
}

// Video Viewer Class
class VideoViewer {
    private $url;
    private $cookieFile;
    private $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/119.0',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (iPad; CPU OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Android 13; Mobile; rv:109.0) Gecko/113.0 Firefox/113.0',
        // Adding more diverse user agents
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36 Edg/125.0.0.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
        'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0'
    ];
    private $userAgent;
    private $referrer;
    private $logs = [];
    private $siteType = 'unknown';
    private $proxies = []; // Proxy list
    private $useProxy = false; // Enable proxy usage
    private $customHeaders = []; // Custom HTTP headers
    private $popupMode = false; // Pop-up mode flag
    private $currentProxy = null; // Currently used proxy
    private $proxyRetries = 3; // Number of retries with different proxies
    
    public function __construct($url, $popupMode = false) {
        $this->url = $url;
        $this->cookieFile = dirname(__FILE__) . '/cookies_' . md5($url) . '.txt';
        $this->userAgent = $this->userAgents[array_rand($this->userAgents)];
        $this->referrer = 'https://www.google.com/search?q=' . urlencode(parse_url($url, PHP_URL_HOST));
        $this->popupMode = $popupMode;
        
        // Create cookie file
        file_put_contents($this->cookieFile, '');
        
        // Determine site type
        $this->determineSiteType();
        
        // Default HTTP headers
        $this->customHeaders = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1',
            'Cache-Control: max-age=0',
            'Sec-Ch-Ua: "Chromium";v="124", "Google Chrome";v="124"',
            'Sec-Ch-Ua-Mobile: ?0',
            'Sec-Ch-Ua-Platform: "Windows"',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-User: ?1',
            'DNT: 1' // Do Not Track
        ];
    }
    
    // Parse and load proxies from the provided list format
    public function parseProxyList($proxyContent) {
        $parsedProxies = [];
        $lines = explode("\n", $proxyContent);
        
        $headerFound = false;
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Skip header rows
            if (strpos($line, 'IP Address') !== false && strpos($line, 'Port') !== false) {
                $headerFound = true;
                continue;
            }
            
            // Skip line after header that contains separators
            if ($headerFound && (strpos($line, '---') !== false || strpos($line, '===') !== false)) {
                continue;
            }
            
            // Process data rows - use regex to extract IP and port
            if (preg_match('/^(\d+\.\d+\.\d+\.\d+)\s+(\d+)/', $line, $matches)) {
                $ip = $matches[1];
                $port = $matches[2];
                
                // Extract additional fields if available
                $countryCode = '';
                $isHttps = false;
                $isElite = false;
                
                // Check if HTTPS supported
                if (strpos($line, 'yes') !== false && strpos($line, 'https') !== false) {
                    $isHttps = true;
                }
                
                // Check if it's an elite proxy
                if (strpos($line, 'elite') !== false) {
                    $isElite = true;
                }
                
                // Extract country code (assumes format with tabs or multiple spaces between columns)
                if (preg_match('/\d+\s+([A-Z]{2})\s+/', $line, $countryMatches)) {
                    $countryCode = $countryMatches[1];
                }
                
                // Add to parsed proxies with metadata
                $parsedProxies[] = [
                    'proxy' => "$ip:$port",
                    'country' => $countryCode,
                    'https' => $isHttps,
                    'elite' => $isElite
                ];
            }
        }
        
        $this->setProxies($parsedProxies);
        return $parsedProxies;
    }
    
    // Load proxies from file
    public function loadProxies($file) {
        if (file_exists($file)) {
            $content = file_get_contents($file);
            $this->parseProxyList($content);
            $this->logs[] = logMessage("Proxy list loaded: " . count($this->proxies) . " proxies");
        }
        return $this;
    }
    
    // Set proxies directly
    public function setProxies(array $proxies) {
        $this->proxies = $proxies;
        if (!empty($this->proxies)) {
            $this->useProxy = true;
            $this->logs[] = logMessage("Proxy list set: " . count($this->proxies) . " proxies");
        }
        return $this;
    }
    
    // Enable/disable proxy usage
    public function enableProxy($enable = true) {
        $this->useProxy = $enable;
        $this->logs[] = logMessage("Proxy usage: " . ($enable ? "Enabled" : "Disabled"));
        return $this;
    }
    
    // Set popup mode
    public function setPopupMode($enable = true) {
        $this->popupMode = $enable;
        $this->logs[] = logMessage("Popup mode: " . ($enable ? "Enabled" : "Disabled"));
        return $this;
    }
    
    // Determine site type
    private function determineSiteType() {
        $host = parse_url($this->url, PHP_URL_HOST);
        $path = parse_url($this->url, PHP_URL_PATH);
        
        // Major platforms
        if (strpos($host, 'youtube.com') !== false) {
            if (strpos($path, '/shorts/') !== false) {
                $this->siteType = 'youtube_shorts';
            } else {
                $this->siteType = 'youtube';
            }
        } elseif (strpos($host, 'youtu.be') !== false) {
            $this->siteType = 'youtube';
        } elseif (strpos($host, 'vimeo.com') !== false) {
            $this->siteType = 'vimeo';
        } elseif (strpos($host, 'hdfilmcehennemi') !== false || 
                 strpos($host, 'fullhdfilmizlesene') !== false || 
                 strpos($host, 'filmizletv') !== false) {
            $this->siteType = 'hdfilm';
        } elseif (strpos($host, 'instagram.com') !== false) {
            if (strpos($path, '/reel/') !== false) {
                $this->siteType = 'instagram_reel';
            } else {
                $this->siteType = 'instagram';
            }
        } elseif (strpos($host, 'tiktok.com') !== false) {
            $this->siteType = 'tiktok';
        } elseif (strpos($host, 'twitter.com') !== false || strpos($host, 'x.com') !== false) {
            $this->siteType = 'twitter';
        } elseif (strpos($host, 'facebook.com') !== false || strpos($host, 'fb.com') !== false) {
            if (strpos($path, '/reel/') !== false) {
                $this->siteType = 'facebook_reel';
            } else {
                $this->siteType = 'facebook';
            }
        } elseif (strpos($host, 'dailymotion.com') !== false) {
            $this->siteType = 'dailymotion';
        } elseif (strpos($host, 'twitch.tv') !== false) {
            $this->siteType = 'twitch';
        } elseif (strpos($host, 'netflix.com') !== false) {
            $this->siteType = 'netflix';
        } elseif (strpos($host, 'disneyplus.com') !== false) {
            $this->siteType = 'disneyplus';
        } elseif (strpos($host, 'hulu.com') !== false) {
            $this->siteType = 'hulu';
        } elseif (strpos($host, 'primevideo.com') !== false || strpos($host, 'amazon.com/gp/video') !== false) {
            $this->siteType = 'primevideo';
        } elseif (strpos($host, 'your-domain.com') !== false) {
            $this->siteType = 'your_site';
        }
        
        $this->logs[] = logMessage("Site type determined: " . $this->siteType);
    }
    
    // Select a proxy based on criteria
    private function selectProxy($preferHttps = false, $preferElite = true) {
        if (empty($this->proxies)) {
            return null;
        }
        
        // Filter proxies based on criteria
        $filteredProxies = $this->proxies;
        
        if ($preferHttps) {
            $httpsProxies = array_filter($filteredProxies, function($p) {
                return isset($p['https']) && $p['https'] === true;
            });
            
            if (!empty($httpsProxies)) {
                $filteredProxies = $httpsProxies;
            }
        }
        
        if ($preferElite) {
            $eliteProxies = array_filter($filteredProxies, function($p) {
                return isset($p['elite']) && $p['elite'] === true;
            });
            
            if (!empty($eliteProxies)) {
                $filteredProxies = $eliteProxies;
            }
        }
        
        // If no proxies match criteria, fall back to all proxies
        if (empty($filteredProxies)) {
            $filteredProxies = $this->proxies;
        }
        
        // Select a random proxy from the filtered list
        $proxy = $filteredProxies[array_rand($filteredProxies)];
        return $proxy['proxy'];
    }
    
    // Fetch page content
    public function fetchPage($url, $extraHeaders = []) {
        $retries = $this->proxyRetries;
        $ch = null;
        $response = false;
        $httpCode = 0;
        $error = '';
        
        while ($retries > 0 && ($httpCode < 200 || $httpCode >= 400)) {
            if ($ch !== null) {
                curl_close($ch);
            }
            
            $ch = curl_init();
            
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
            curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
            curl_setopt($ch, CURLOPT_REFERER, $this->referrer);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            // Add anti-bot detection headers
            $headers = $this->customHeaders;
            
            // Add extra headers
            if (!empty($extraHeaders)) {
                $headers = array_merge($headers, $extraHeaders);
            }
            
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            
            // Use proxy if enabled
            if ($this->useProxy && !empty($this->proxies)) {
                // For HTTPS URLs, prefer HTTPS proxies
                $preferHttps = (parse_url($url, PHP_URL_SCHEME) === 'https');
                $proxy = $this->selectProxy($preferHttps);
                
                if ($proxy) {
                    curl_setopt($ch, CURLOPT_PROXY, $proxy);
                    $this->currentProxy = $proxy;
                    $this->logs[] = logMessage("Using proxy: " . $proxy);
                }
            }
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            
            // If proxy failed, retry with a different one
            if (($httpCode < 200 || $httpCode >= 400 || $error) && $this->useProxy) {
                $this->logs[] = logMessage("Proxy error or bad response (HTTP $httpCode): $error. Retrying with different proxy...");
                $retries--;
                
                // Short pause before retry
                usleep(500000); // 500ms
                continue;
            }
            
            break;
        }
        
        if ($ch !== null) {
            curl_close($ch);
        }
        
        if ($error) {
            $this->logs[] = logMessage("CURL error: $error");
            return false;
        }
        
        // Redirect check
        if ($finalUrl != $url) {
            $this->logs[] = logMessage("Redirect detected: $url -> $finalUrl");
            
            // Login page redirect check
            if (strpos($finalUrl, 'login') !== false || 
                strpos($finalUrl, 'signin') !== false || 
                strpos($finalUrl, 'accounts.google.com') !== false) {
                $this->logs[] = logMessage("Redirected to login page. Bot detection protection may be active.");
            }
        }
        
        $this->logs[] = logMessage("Page fetched: $url (HTTP: $httpCode)");
        return $response;
    }
    
    // Scan video page
    public function scanVideoPage() {
        $html = $this->fetchPage($this->url);
        if (!$html) {
            $this->logs[] = logMessage("Failed to load page: " . $this->url);
            return false;
        }
        
        // Get page title
        $pageTitle = '';
        if (preg_match('/<title>(.*?)<\/title>/i', $html, $titleMatch)) {
            $pageTitle = trim($titleMatch[1]);
        }
        
        // Extract video source URL based on site type
        $videoData = $this->extractVideoData($html);
        
        return [
            'title' => $pageTitle,
            'url' => $this->url,
            'video_url' => $videoData['video_url'] ?? '',
            'video_id' => $videoData['video_id'] ?? '',
            'alternatives' => $videoData['alternatives'] ?? [],
            'site_type' => $this->siteType,
            'duration' => $videoData['duration'] ?? 0,
            'extra_data' => $videoData['extra_data'] ?? [],
            'popup_html' => $this->generatePopupHtml($videoData),
        ];
    }
    
    // Generate popup HTML for direct video viewing
    private function generatePopupHtml($videoData) {
        $videoUrl = $videoData['video_url'] ?? '';
        $videoId = $videoData['video_id'] ?? '';
        $siteType = $this->siteType;
        $title = htmlspecialchars($videoData['title'] ?? 'Video Player');
        
        if (empty($videoUrl)) {
            return '';
        }
        
        $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $title . '</title>
    <style>
        body, html {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background-color: #000;
            font-family: Arial, sans-serif;
        }
        .video-container {
            position: relative;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        iframe, video {
            width: 100%;
            height: 100%;
            border: none;
        }
        .controls {
            position: absolute;
            bottom: 20px;
            right: 20px;
            z-index: 10;
            display: flex;
            gap: 10px;
        }
        .control-btn {
            background: rgba(0,0,0,0.7);
            color: #fff;
            border: none;
            border-radius: 4px;
            padding: 8px 12px;
            cursor: pointer;
            font-size: 14px;
        }
        .control-btn:hover {
            background: rgba(0,0,0,0.9);
        }
        .loading {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #fff;
            font-size: 18px;
        }
    </style>
</head>
<body>
    <div class="video-container">
        <div class="loading">Loading video...</div>';
        
        // Different embedding based on site type
        switch ($siteType) {
            case 'youtube':
            case 'youtube_shorts':
                $html .= '<iframe src="' . $videoUrl . '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
                break;
                
            case 'vimeo':
                $html .= '<iframe src="' . $videoUrl . '" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>';
                break;
                
            case 'instagram':
            case 'instagram_reel':
                $html .= '<iframe src="' . $videoUrl . '" frameborder="0" scrolling="no" allowtransparency="true"></iframe>';
                break;
                
            case 'tiktok':
                $html .= '<iframe src="' . $videoUrl . '" frameborder="0" scrolling="no" allowfullscreen></iframe>';
                break;
                
            case 'twitter':
                $html .= '<iframe src="' . $videoUrl . '" frameborder="0" scrolling="no" allowtransparency="true"></iframe>';
                break;
                
            case 'facebook':
            case 'facebook_reel':
                $html .= '<iframe src="' . $videoUrl . '" frameborder="0" scrolling="no" allowfullscreen></iframe>';
                break;
                
            case 'dailymotion':
                $html .= '<iframe src="' . $videoUrl . '" frameborder="0" allow="autoplay; fullscreen" allowfullscreen></iframe>';
                break;
                
            case 'hdfilm':
                // For movie sites, try to embed the iframe directly
                if (strpos($videoUrl, '<iframe') !== false) {
                    $html .= $videoUrl; // If videoUrl already contains iframe HTML
                } else {
                    $html .= '<iframe src="' . $videoUrl . '" frameborder="0" allowfullscreen></iframe>';
                }
                break;
                
            default:
                // Check if it's a direct video file
                if (preg_match('/\.(mp4|webm|ogg)(\?.*)?$/i', $videoUrl)) {
                    $html .= '<video src="' . $videoUrl . '" controls autoplay></video>';
                } else {
                    $html .= '<iframe src="' . $videoUrl . '" frameborder="0" allowfullscreen></iframe>';
                }
                break;
        }
        
        $html .= '
        <div class="controls">
            <button class="control-btn" onclick="window.close()">Close</button>
        </div>
    </div>
    <script>
        // Hide loading message when content loads
        window.addEventListener("load", function() {
            document.querySelector(".loading").style.display = "none";
        });
        
        // Auto-close detection for some platforms
        let videoWatched = false;
        setTimeout(function() {
            videoWatched = true;
        }, 5000);
        
        // Detect when video ends for direct video files
        const videoElement = document.querySelector("video");
        if (videoElement) {
            videoElement.addEventListener("ended", function() {
                if (confirm("Video ended. Close this window?")) {
                    window.close();
                }
            });
        }
    </script>
</body>
</html>';

        return $html;
    }
    
    // Extract video data based on site type
    private function extractVideoData($html) {
        switch ($this->siteType) {
            case 'youtube':
                return $this->extractYouTubeVideo($html);
                
            case 'youtube_shorts':
                return $this->extractYouTubeShortsVideo($html);
                
            case 'vimeo':
                return $this->extractVimeoVideo($html);
                
            case 'hdfilm':
                return $this->extractHDFilmVideo($html);
                
            case 'instagram':
            case 'instagram_reel':
                return $this->extractInstagramVideo($html);
                
            case 'tiktok':
                return $this->extractTikTokVideo($html);
                
            case 'twitter':
                return $this->extractTwitterVideo($html);
                
            case 'facebook':
            case 'facebook_reel':
                return $this->extractFacebookVideo($html);
                
            case 'dailymotion':
                return $this->extractDailymotionVideo($html);
                
            case 'your_site':
                return $this->extractYourSiteVideo($html);
                
            default:
                return $this->extractGenericVideo($html);
        }
    }
    
    // YouTube video data extraction
    private function extractYouTubeVideo($html) {
        $videoId = '';
        $videoUrl = '';
        $duration = 0;
        $title = '';
        
        // Extract video ID from URL
        if (preg_match('/youtube\.com\/watch\?v=([^&]+)/', $this->url, $match)) {
            $videoId = $match[1];
        } elseif (preg_match('/youtu\.be\/([^?&]+)/', $this->url, $match)) {
            $videoId = $match[1];
        }
        
        if ($videoId) {
            // Use embed URL directly
            $videoUrl = 'https://www.youtube.com/embed/' . $videoId . '?autoplay=1&enablejsapi=1';
            $this->logs[] = logMessage("YouTube video ID: $videoId, Embed URL: $videoUrl");
            
            // Try to find video duration
            if (preg_match('/"lengthSeconds":"(\d+)"/', $html, $durationMatch)) {
                $duration = intval($durationMatch[1]);
                $this->logs[] = logMessage("Video duration: $duration seconds");
            }
            
            // Try to extract title
            if (preg_match('/<title>(.*?)\s*-\s*YouTube<\/title>/', $html, $titleMatch)) {
                $title = trim($titleMatch[1]);
            }
        } else {
            $this->logs[] = logMessage("YouTube video ID not found.");
            
            // Try to extract video ID from HTML
            if (preg_match('/"videoId":"([^"]+)"/', $html, $match)) {
                $videoId = $match[1];
                $videoUrl = 'https://www.youtube.com/embed/' . $videoId . '?autoplay=1&enablejsapi=1';
                $this->logs[] = logMessage("Extracted YouTube video ID from HTML: $videoId, Embed URL: $videoUrl");
            }
        }
        
        return [
            'video_id' => $videoId,
            'video_url' => $videoUrl,
            'duration' => $duration,
            'title' => $title
        ];
    }
    
    // YouTube Shorts video data extraction
    private function extractYouTubeShortsVideo($html) {
        $videoId = '';
        $videoUrl = '';
        $title = '';
        
        // Extract video ID from URL
        if (preg_match('/youtube\.com\/shorts\/([^?&\/]+)/', $this->url, $match)) {
            $videoId = $match[1];
        }
        
        if ($videoId) {
            // Special embed URL for Shorts
            $videoUrl = 'https://www.youtube.com/embed/' . $videoId . '?autoplay=1&loop=1&playlist=' . $videoId;
            $this->logs[] = logMessage("YouTube Shorts video ID: $videoId, Embed URL: $videoUrl");
            
            // Try to extract title
            if (preg_match('/<title>(.*?)\s*-\s*YouTube<\/title>/', $html, $titleMatch)) {
                $title = trim($titleMatch[1]);
            }
        } else {
            $this->logs[] = logMessage("YouTube Shorts video ID not found.");
        }
        
        return [
            'video_id' => $videoId,
            'video_url' => $videoUrl,
            'duration' => 60, // Shorts are typically 60 seconds or less
            'title' => $title
        ];
    }
    
    // Vimeo video data extraction
    private function extractVimeoVideo($html) {
        $videoId = '';
        $videoUrl = '';
        $title = '';
        
        // Extract video ID from URL
        if (preg_match('/vimeo\.com\/(\d+)/', $this->url, $match)) {
            $videoId = $match[1];
        }
        
        if ($videoId) {
            // Use embed URL directly
            $videoUrl = 'https://player.vimeo.com/video/' . $videoId . '?autoplay=1';
            $this->logs[] = logMessage("Vimeo video ID: $videoId, Embed URL: $videoUrl");
            
            // Try to extract title
            if (preg_match('/<title>(.*?)\s*on\s*Vimeo<\/title>/', $html, $titleMatch)) {
                $title = trim($titleMatch[1]);
            }
        } else {
            $this->logs[] = logMessage("Vimeo video ID not found.");
            
            // Try to extract player URL from HTML
            if (preg_match('/player\.vimeo\.com\/video\/(\d+)/', $html, $match)) {
                $videoId = $match[1];
                $videoUrl = 'https://player.vimeo.com/video/' . $videoId . '?autoplay=1';
                $this->logs[] = logMessage("Extracted Vimeo video ID from HTML: $videoId, Embed URL: $videoUrl");
            }
        }
        
        return [
            'video_id' => $videoId,
            'video_url' => $videoUrl,
            'duration' => 0, // Vimeo duration can be fetched via API
            'title' => $title
        ];
    }
    
    // HDFilm video data extraction
    private function extractHDFilmVideo($html) {
        $videoUrl = '';
        $alternatives = [];
        $title = '';
        
        // Find iframe URL
        if (preg_match('/<iframe.*?data-src="(.*?)".*?>/i', $html, $iframeMatch)) {
            $videoUrl = $iframeMatch[1];
            $this->logs[] = logMessage("HDFilm data-src iframe URL found: " . $videoUrl);
        } elseif (preg_match('/<iframe.*?src="(.*?)".*?>/i', $html, $iframeMatch)) {
            $videoUrl = $iframeMatch[1];
            $this->logs[] = logMessage("HDFilm iframe URL found: " . $videoUrl);
        }
        
        // Find video alternatives
        if (preg_match_all('/<button class="alternative-link".*?data-video="(\d+)".*?>(.*?)<\/button>/is', $html, $alternativeMatches, PREG_SET_ORDER)) {
            foreach ($alternativeMatches as $match) {
                $videoId = $match[1];
                $videoTitle = trim(strip_tags($match[2]));
                
                $alternatives[] = [
                    'id' => $videoId,
                    'title' => $videoTitle
                ];
            }
            
            $this->logs[] = logMessage("HDFilm video alternatives found: " . count($alternatives));
        }
        
        // If no iframe found, try other extraction methods
        if (empty($videoUrl)) {
            // Look for any iframe
            if (preg_match_all('/<iframe[^>]*src=[\'"]([^\'"]+)[\'"][^>]*>/i', $html, $allIframes, PREG_SET_ORDER)) {
                foreach ($allIframes as $iframe) {
                    // Skip common ad iframes
                    if (strpos($iframe[1], 'google') !== false || 
                        strpos($iframe[1], 'facebook') !== false ||
                        strpos($iframe[1], 'analytics') !== false) {
                        continue;
                    }
                    
                    $videoUrl = $iframe[1];
                    $this->logs[] = logMessage("Found potential video iframe: " . $videoUrl);
                    break;
                }
            }
        }
        
        // Try to extract title
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $html, $titleMatch)) {
            $title = trim($titleMatch[1]);
        } elseif (preg_match('/<title>(.*?)<\/title>/i', $html, $titleMatch)) {
            $title = trim($titleMatch[1]);
        }
        
        return [
            'video_url' => $videoUrl,
            'alternatives' => $alternatives,
            'duration' => 0, // Movie duration estimate
            'title' => $title
        ];
    }
    
    // Instagram video data extraction
    private function extractInstagramVideo($html) {
        $videoId = '';
        $videoUrl = '';
        $title = '';
        
        // Extract video ID from URL
        if (preg_match('/instagram\.com\/(?:p|reel)\/([^\/\?]+)/', $this->url, $match)) {
            $videoId = $match[1];
        }
        
        // Instagram API doesn't directly provide video URL
        // Use embed method
        if ($videoId) {
            $videoUrl = 'https://www.instagram.com/p/' . $videoId . '/embed/';
            $this->logs[] = logMessage("Instagram video ID: $videoId, Embed URL: $videoUrl");
            
            // Try to extract title/username
            if (preg_match('/<title>(.*?)\s*on\s*Instagram/', $html, $titleMatch)) {
                $title = trim($titleMatch[1]);
            }
        } else {
            $this->logs[] = logMessage("Instagram video ID not found.");
        }
        
        // If we're dealing with a reel and have the ID
        if ($this->siteType === 'instagram_reel' && $videoId) {
            $videoUrl = 'https://www.instagram.com/reel/' . $videoId . '/embed/';
        }
        
        return [
            'video_id' => $videoId,
            'video_url' => $videoUrl,
            'duration' => 30, // Instagram reels typically 15-30 seconds
            'title' => $title
        ];
    }
    
    // TikTok video data extraction
    private function extractTikTokVideo($html) {
        $videoId = '';
        $videoUrl = '';
        $title = '';
        
        // Extract video ID from URL
        if (preg_match('/tiktok\.com\/@[^\/]+\/video\/(\d+)/', $this->url, $match)) {
            $videoId = $match[1];
        }
        
        if ($videoId) {
            $videoUrl = 'https://www.tiktok.com/embed/v2/' . $videoId;
            $this->logs[] = logMessage("TikTok video ID: $videoId, Embed URL: $videoUrl");
            
            // Try to extract title/username
            if (preg_match('/<title>(.*?)(?:\s*\|[^|]*TikTok|\s*on\s*TikTok)<\/title>/i', $html, $titleMatch)) {
                $title = trim($titleMatch[1]);
            }
        } else {
            $this->logs[] = logMessage("TikTok video ID not found.");
        }
        
        return [
            'video_id' => $videoId,
            'video_url' => $videoUrl,
            'duration' => 15, // TikTok videos typically 15-60 seconds
            'title' => $title
        ];
    }
    
    // Twitter video data extraction
    private function extractTwitterVideo($html) {
        $tweetId = '';
        $videoUrl = '';
        $title = '';
        
        // Extract tweet ID from URL
        if (preg_match('/(?:twitter|x)\.com\/(?:[^\/]+)\/status\/(\d+)/', $this->url, $match)) {
            $tweetId = $match[1];
        }
        
        if ($tweetId) {
            $videoUrl = 'https://platform.twitter.com/embed/Tweet.html?id=' . $tweetId;
            $this->logs[] = logMessage("Twitter tweet ID: $tweetId, Embed URL: $videoUrl");
            
            // Try to extract username/text
            if (preg_match('/<title>(.*?)(?:\s*on\s*(?:Twitter|X)|\s*\|\s*(?:Twitter|X))<\/title>/i', $html, $titleMatch)) {
                $title = trim($titleMatch[1]);
            }
        } else {
            $this->logs[] = logMessage("Twitter tweet ID not found.");
        }
        
        return [
            'video_id' => $tweetId,
            'video_url' => $videoUrl,
            'duration' => 30, // Twitter videos vary in length
            'title' => $title
        ];
    }
    
    // Facebook video data extraction
    private function extractFacebookVideo($html) {
        $videoId = '';
        $videoUrl = '';
        $title = '';
        
        // Extract video ID from URL
        if (preg_match('/facebook\.com\/(?:[^\/]+)\/videos\/(\d+)/', $this->url, $match)) {
            $videoId = $match[1];
        } elseif (preg_match('/facebook\.com\/watch\/\?v=(\d+)/', $this->url, $match)) {
            $videoId = $match[1];
        } elseif (preg_match('/facebook\.com\/reel\/(\d+)/', $this->url, $match)) {
            $videoId = $match[1];
        }
        
        if ($videoId) {
            $videoUrl = 'https://www.facebook.com/plugins/video.php?href=https://www.facebook.com/watch/?v=' . $videoId;
            $this->logs[] = logMessage("Facebook video ID: $videoId, Embed URL: $videoUrl");
            
            // Try to extract title
            if (preg_match('/<title>(.*?)(?:\s*\|\s*Facebook)<\/title>/i', $html, $titleMatch)) {
                $title = trim($titleMatch[1]);
            }
        } else {
            $this->logs[] = logMessage("Facebook video ID not found.");
        }
        
        // If we're dealing with a reel and have the ID
        if ($this->siteType === 'facebook_reel' && $videoId) {
            $videoUrl = 'https://www.facebook.com/plugins/video.php?href=https://www.facebook.com/reel/' . $videoId;
        }
        
        return [
            'video_id' => $videoId,
            'video_url' => $videoUrl,
            'duration' => 60, // Facebook videos vary in length
            'title' => $title
        ];
    }
    
    // Dailymotion video data extraction
    private function extractDailymotionVideo($html) {
        $videoId = '';
        $videoUrl = '';
        $title = '';
        
        // Extract video ID from URL
        if (preg_match('/dailymotion\.com\/video\/([^_&]+)/', $this->url, $match)) {
            $videoId = $match[1];
        }
        
        if ($videoId) {
            // Use embed URL directly
            $videoUrl = 'https://www.dailymotion.com/embed/video/' . $videoId . '?autoplay=1';
            $this->logs[] = logMessage("Dailymotion video ID: $videoId, Embed URL: $videoUrl");
            
            // Try to extract title
            if (preg_match('/<title>(.*?)(?:\s*-\s*Dailymotion)<\/title>/i', $html, $titleMatch)) {
                $title = trim($titleMatch[1]);
            }
        } else {
            $this->logs[] = logMessage("Dailymotion video ID not found.");
            
            // Try to extract player URL from HTML
            if (preg_match('/dailymotion\.com\/embed\/video\/([^"&]+)/', $html, $match)) {
                $videoId = $match[1];
                $videoUrl = 'https://www.dailymotion.com/embed/video/' . $videoId . '?autoplay=1';
                $this->logs[] = logMessage("Extracted Dailymotion video ID from HTML: $videoId, Embed URL: $videoUrl");
            }
        }
        
        return [
            'video_id' => $videoId,
            'video_url' => $videoUrl,
            'duration' => 0, // Dailymotion duration can be fetched via API
            'title' => $title
        ];
    }
    
    // Your site video data extraction
    private function extractYourSiteVideo($html) {
        $videoId = '';
        $videoUrl = '';
        $alternatives = [];
        $title = '';
        
        // Customize this method for your site's structure
        // Example: <video src="video.mp4"></video>
        if (preg_match('/<video.*?src=[\'"]([^\'"]+)[\'"].*?>/is', $html, $videoMatch)) {
            $videoUrl = $videoMatch[1];
            $this->logs[] = logMessage("Video URL found from your site: " . $videoUrl);
        }
        
        // Or iframe
        elseif (preg_match('/<iframe.*?src=[\'"]([^\'"]+)[\'"].*?>/is', $html, $iframeMatch)) {
            $videoUrl = $iframeMatch[1];
            $this->logs[] = logMessage("Iframe URL found from your site: " . $videoUrl);
        }
        
        // Or data-video attribute
        elseif (preg_match('/data-video=[\'"]([^\'"]+)[\'"]/', $html, $dataMatch)) {
            $videoUrl = $dataMatch[1];
            $this->logs[] = logMessage("Data-video URL found from your site: " . $videoUrl);
        }
        
        // Try to extract title
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $html, $titleMatch)) {
            $title = trim($titleMatch[1]);
        } elseif (preg_match('/<title>(.*?)<\/title>/i', $html, $titleMatch)) {
            $title = trim($titleMatch[1]);
        }
        
        return [
            'video_id' => $videoId,
            'video_url' => $videoUrl,
            'alternatives' => $alternatives,
            'duration' => 0, // Set your video duration
            'title' => $title
        ];
    }
    
    // Generic video data extraction
    private function extractGenericVideo($html) {
        $videoUrl = '';
        $title = '';
        
        // Find iframe src
        if (preg_match('/<iframe.*?src=[\'"]([^\'"]+)[\'"].*?>/i', $html, $iframeMatch)) {
            $videoUrl = $iframeMatch[1];
            $this->logs[] = logMessage("Generic iframe URL found: " . $videoUrl);
        }
        
        // Find video src
        elseif (preg_match('/<video.*?src=[\'"]([^\'"]+)[\'"].*?>/i', $html, $videoMatch)) {
            $videoUrl = $videoMatch[1];
            $this->logs[] = logMessage("Generic video URL found: " . $videoUrl);
        }
        
        // Find source src
        elseif (preg_match('/<source.*?src=[\'"]([^\'"]+)[\'"].*?>/i', $html, $sourceMatch)) {
            $videoUrl = $sourceMatch[1];
            $this->logs[] = logMessage("Generic source URL found: " . $videoUrl);
        }
        
        // More aggressive search for video files
        elseif (preg_match('/[\'"]([^\'"]+(\.mp4|\.webm|\.m3u8)[^\'"]*)[\'"]/', $html, $fileMatch)) {
            $videoUrl = $fileMatch[1];
            $this->logs[] = logMessage("Video file URL found: " . $videoUrl);
        }
        
        // Try to extract title
        if (preg_match('/<title>(.*?)<\/title>/i', $html, $titleMatch)) {
            $title = trim($titleMatch[1]);
        }
        
        return [
            'video_url' => $videoUrl,
            'duration' => 0,
            'title' => $title
        ];
    }
    
    // Watch video
    public function watchVideo($duration = 120) {
        $scanResult = $this->scanVideoPage();
        
        if (!$scanResult) {
            $this->logs[] = logMessage("Failed to scan video page.");
            return false;
        }
        
        if (empty($scanResult['video_url'])) {
            $this->logs[] = logMessage("No video URL found to watch.");
            return false;
        }
        
        $this->logs[] = logMessage("Starting to watch video: " . $scanResult['title']);
        $this->logs[] = logMessage("Video URL: " . $scanResult['video_url']);
        
        // Check video duration
        $videoDuration = $scanResult['duration'];
        if ($videoDuration > 0 && $duration > $videoDuration) {
            $this->logs[] = logMessage("Specified watch time ($duration) is longer than video duration ($videoDuration). Using video duration.");
            $duration = $videoDuration;
        }
        
        // If popup mode is enabled, create and store popup HTML
        if ($this->popupMode) {
            $_SESSION['popup_html'] = $scanResult['popup_html'];
            $this->logs[] = logMessage("Popup HTML generated and stored in session.");
        }
        
        // Watch video based on site type
        switch ($this->siteType) {
            case 'youtube':
                return $this->watchYouTubeVideo($scanResult, $duration);
                
            case 'youtube_shorts':
                return $this->watchYouTubeShortsVideo($scanResult, $duration);
                
            case 'vimeo':
                return $this->watchVimeoVideo($scanResult, $duration);
                
            case 'hdfilm':
                return $this->watchHDFilmVideo($scanResult, $duration);
                
            case 'instagram':
            case 'instagram_reel':
                return $this->watchInstagramVideo($scanResult, $duration);
                
            case 'tiktok':
                return $this->watchTikTokVideo($scanResult, $duration);
                
            case 'twitter':
                return $this->watchTwitterVideo($scanResult, $duration);
                
            case 'facebook':
            case 'facebook_reel':
                return $this->watchFacebookVideo($scanResult, $duration);
                
            case 'your_site':
                return $this->watchYourSiteVideo($scanResult, $duration);
                
            default:
                return $this->watchGenericVideo($scanResult, $duration);
        }
    }
    
    // YouTube video watching
    private function watchYouTubeVideo($videoData, $duration) {
        // Fetch embed page
        $embedHtml = $this->fetchPage($videoData['video_url']);
        if (!$embedHtml) {
            $this->logs[] = logMessage("Failed to load YouTube embed page.");
            return false;
        }
        
        // Send watch data
        $watchEndpoint = 'https://www.youtube.com/api/stats/watchtime';
        $params = [
            'ns' => 'yt',
            'el' => 'detailpage',
            'cpn' => $this->generateRandomString(16),
            'ver' => 2,
            'fmt' => 22, // HD quality
            'fs' => 0,
            'rt' => mt_rand(5, 15),
            'of' => $this->generateRandomString(3),
            'euri' => '',
            'lact' => mt_rand(1000, 9000),
            'cl' => $this->generateRandomString(9),
            'state' => 'playing',
            'vm' => 1,
            'volume' => 100, // Full volume
            'cbr' => 'Chrome',
            'cbrver' => '124.0.0.0',
            'c' => 'WEB',
            'cver' => '2.20250601',
            'cplayer' => 'UNIPLAYER',
            'cos' => 'Windows',
            'cosver' => '10.0',
            'cplatform' => 'DESKTOP'
        ];
        
        $watchUrl = $watchEndpoint . '?' . http_build_query($params);
        $this->fetchPage($watchUrl, [
            'X-YouTube-Client-Name: 1', 
            'X-YouTube-Client-Version: 2.20250601.00.00',
            'Referer: ' . $videoData['video_url']
        ]);
        
        // Simulate realistic viewing
        $this->logs[] = logMessage("Watching YouTube video... Duration: $duration seconds");
        $this->simulateViewing($duration, $videoData);
        
        // Send completion data
        $completionParams = $params;
        $completionParams['state'] = 'end';
        $completionUrl = $watchEndpoint . '?' . http_build_query($completionParams);
        $this->fetchPage($completionUrl, [
            'X-YouTube-Client-Name: 1', 
            'X-YouTube-Client-Version: 2.20250601.00.00',
            'Referer: ' . $videoData['video_url']
        ]);
        
        $this->logs[] = logMessage("Video watching completed.");
        return true;
    }
    
    // YouTube Shorts watching
    private function watchYouTubeShortsVideo($videoData, $duration) {
        // Fetch embed page
        $embedHtml = $this->fetchPage($videoData['video_url']);
        if (!$embedHtml) {
            $this->logs[] = logMessage("Failed to load YouTube Shorts embed page.");
            return false;
        }
        
        // Send watch data with Shorts-specific parameters
        $watchEndpoint = 'https://www.youtube.com/api/stats/watchtime';
        $params = [
            'ns' => 'yt',
            'el' => 'shorts',
            'cpn' => $this->generateRandomString(16),
            'ver' => 2,
            'fmt' => 22,
            'fs' => 0,
            'rt' => mt_rand(5, 15),
            'of' => $this->generateRandomString(3),
            'euri' => '',
            'lact' => mt_rand(1000, 9000),
            'cl' => $this->generateRandomString(9),
            'state' => 'playing',
            'vm' => 1,
            'cbr' => 'Chrome',
            'cbrver' => '124.0.0.0',
            'c' => 'WEB',
            'cver' => '2.20250601',
            'cplayer' => 'UNIPLAYER',
            'cos' => 'Windows',
            'cosver' => '10.0',
            'cplatform' => 'DESKTOP'
        ];
        
        $watchUrl = $watchEndpoint . '?' . http_build_query($params);
        $this->fetchPage($watchUrl, [
            'X-YouTube-Client-Name: 1', 
            'X-YouTube-Client-Version: 2.20250601.00.00',
            'Referer: ' . $videoData['video_url']
        ]);
        
        // Simulate realistic viewing
        $this->logs[] = logMessage("Watching YouTube Shorts... Duration: $duration seconds");
        $this->simulateViewing($duration, $videoData);
        
        // For Shorts, simulate viewing again to register a loop
        $loopParams = $params;
        $loopParams['state'] = 'playing';
        $loopParams['rt'] = mt_rand(5, 15);
        $loopUrl = $watchEndpoint . '?' . http_build_query($loopParams);
        $this->fetchPage($loopUrl, [
            'X-YouTube-Client-Name: 1', 
            'X-YouTube-Client-Version: 2.20250601.00.00',
            'Referer: ' . $videoData['video_url']
        ]);
        
        $this->logs[] = logMessage("Shorts watching completed.");
        return true;
    }
    
    // Vimeo video watching
    private function watchVimeoVideo($videoData, $duration) {
        // Fetch embed page
        $embedHtml = $this->fetchPage($videoData['video_url']);
        if (!$embedHtml) {
            $this->logs[] = logMessage("Failed to load Vimeo embed page.");
            return false;
        }
        
        // Simulate realistic viewing
        $this->logs[] = logMessage("Watching Vimeo video... Duration: $duration seconds");
        $this->simulateViewing($duration, $videoData);
        
        $this->logs[] = logMessage("Video watching completed.");
        return true;
    }
    
    // HDFilm video watching
    private function watchHDFilmVideo($videoData, $duration) {
        // Fetch iframe content
        $iframeHtml = $this->fetchPage($videoData['video_url']);
        if (!$iframeHtml) {
            $this->logs[] = logMessage("Failed to load HDFilm iframe content.");
            return false;
        }
        
        // Try to find actual video source URL
        $videoSourceUrl = $this->extractVideoSource($iframeHtml);
        
        if ($videoSourceUrl) {
            $this->logs[] = logMessage("HDFilm video source URL: " . $videoSourceUrl);
            
            // Send request to video source (to increase view counter)
            $sourceResponse = $this->fetchPage($videoSourceUrl);
            if (!$sourceResponse) {
                $this->logs[] = logMessage("Failed to access HDFilm video source.");
            }
        }
        
        // Simulate realistic viewing
        $this->logs[] = logMessage("Watching HDFilm video... Duration: $duration seconds");
        $this->simulateViewing($duration, $videoData);
        
        $this->logs[] = logMessage("Video watching completed.");
        return true;
    }
    
    // Instagram video watching
    private function watchInstagramVideo($videoData, $duration) {
        // Fetch embed page
        $embedHtml = $this->fetchPage($videoData['video_url']);
        if (!$embedHtml) {
            $this->logs[] = logMessage("Failed to load Instagram embed page.");
            return false;
        }
        
        // Simulate realistic viewing
        $this->logs[] = logMessage("Watching Instagram video/reel... Duration: $duration seconds");
        $this->simulateViewing($duration, $videoData);
        
        $this->logs[] = logMessage("Video watching completed.");
        return true;
    }
    
    // TikTok video watching
    private function watchTikTokVideo($videoData, $duration) {
        // Fetch embed page
        $embedHtml = $this->fetchPage($videoData['video_url']);
        if (!$embedHtml) {
            $this->logs[] = logMessage("Failed to load TikTok embed page.");
            return false;
        }
        
        // Simulate realistic viewing
        $this->logs[] = logMessage("Watching TikTok video... Duration: $duration seconds");
        $this->simulateViewing($duration, $videoData);
        
        $this->logs[] = logMessage("Video watching completed.");
        return true;
    }
    
    // Twitter video watching
    private function watchTwitterVideo($videoData, $duration) {
        // Fetch embed page
        $embedHtml = $this->fetchPage($videoData['video_url']);
        if (!$embedHtml) {
            $this->logs[] = logMessage("Failed to load Twitter embed page.");
            return false;
        }
        
        // Simulate realistic viewing
        $this->logs[] = logMessage("Watching Twitter video... Duration: $duration seconds");
        $this->simulateViewing($duration, $videoData);
        
        $this->logs[] = logMessage("Video watching completed.");
        return true;
    }
    
    // Facebook video watching
    private function watchFacebookVideo($videoData, $duration) {
        // Fetch embed page
        $embedHtml = $this->fetchPage($videoData['video_url']);
        if (!$embedHtml) {
            $this->logs[] = logMessage("Failed to load Facebook embed page.");
            return false;
        }
        
        // Simulate realistic viewing
        $this->logs[] = logMessage("Watching Facebook video... Duration: $duration seconds");
        $this->simulateViewing($duration, $videoData);
        
        $this->logs[] = logMessage("Video watching completed.");
        return true;
    }
    
    // Your site video watching
    private function watchYourSiteVideo($videoData, $duration) {
        // Add your custom video watching code here
        $this->logs[] = logMessage("Watching video from your site... Duration: $duration seconds");
        $this->simulateViewing($duration, $videoData);
        
        $this->logs[] = logMessage("Video watching completed.");
        return true;
    }
    
    // Generic video watching
    private function watchGenericVideo($videoData, $duration) {
        // Fetch iframe content
        $iframeHtml = $this->fetchPage($videoData['video_url']);
        if (!$iframeHtml) {
            $this->logs[] = logMessage("Failed to load video iframe content.");
            return false;
        }
        
        // Simulate realistic viewing
        $this->logs[] = logMessage("Watching video... Duration: $duration seconds");
        $this->simulateViewing($duration, $videoData);
        
        $this->logs[] = logMessage("Video watching completed.");
        return true;
    }
    
    // Simulate realistic viewing
    private function simulateViewing($duration, $videoData = []) {
        $startTime = time();
        $endTime = $startTime + $duration;
        
        // Video start notification
        $this->logs[] = logMessage("Video started: " . date('H:i:s'));
        
        // Divide viewing time into small segments
        $segments = min(5, $duration / 10); // Maximum 5 segments
        if ($segments < 1) $segments = 1;
        $segmentTime = $duration / $segments;
        
        for ($i = 1; $i <= $segments; $i++) {
            $sleepTime = $segmentTime;
            
            // Add small random variations for all but the last segment
            if ($i < $segments) {
                $variance = $segmentTime * 0.1; // 10% variance
                $sleepTime = $segmentTime + (mt_rand(-100, 100) / 100) * $variance;
            }
            
            // Wait for segment time
            usleep($sleepTime * 1000000);
            
            // Progress update
            $progress = ($i / $segments) * 100;
            $this->logs[] = logMessage(sprintf("Viewing progress: %.1f%% (%.1f seconds)", $progress, $i * $segmentTime));
            
            // Simulated user interactions
            if ($i == 2 && $segments > 3) {
                $this->logs[] = logMessage("User interaction: Volume level changed");
                
                // Simulate volume change request for certain platforms
                if ($this->siteType === 'youtube' || $this->siteType === 'youtube_shorts') {
                    $volumeEndpoint = 'https://www.youtube.com/api/stats/playback';
                    $volumeParams = [
                        'ns' => 'yt',
                        'el' => $this->siteType === 'youtube_shorts' ? 'shorts' : 'detailpage',
                        'cpn' => $this->generateRandomString(16),
                        'docid' => $videoData['video_id'] ?? '',
                        'ver' => 2,
                        'referrer' => $this->url,
                        'cmt' => round($i * $segmentTime),
                        'ei' => $this->generateRandomString(16),
                        'fmt' => 22,
                        'fs' => 0,
                        'volume' => 80, // Changed volume
                        'rt' => mt_rand(5, 15),
                        'of' => $this->generateRandomString(3),
                        'euri' => '',
                        'lact' => mt_rand(1000, 9000),
                        'cl' => $this->generateRandomString(9),
                        'state' => 'playing'
                    ];
                    
                    $volumeUrl = $volumeEndpoint . '?' . http_build_query($volumeParams);
                    $this->fetchPage($volumeUrl, [
                        'X-YouTube-Client-Name: 1', 
                        'X-YouTube-Client-Version: 2.20250601.00.00',
                        'Referer: ' . ($videoData['video_url'] ?? $this->url)
                    ]);
                }
            }
            
            if ($i == 3 && $segments > 4) {
                $this->logs[] = logMessage("User interaction: Video paused and resumed");
                
                // Simulate pause for certain platforms
                if ($this->siteType === 'youtube' || $this->siteType === 'youtube_shorts') {
                    $pauseEndpoint = 'https://www.youtube.com/api/stats/playback';
                    $pauseParams = [
                        'ns' => 'yt',
                        'el' => $this->siteType === 'youtube_shorts' ? 'shorts' : 'detailpage',
                        'cpn' => $this->generateRandomString(16),
                        'docid' => $videoData['video_id'] ?? '',
                        'ver' => 2,
                        'referrer' => $this->url,
                        'cmt' => round($i * $segmentTime),
                        'ei' => $this->generateRandomString(16),
                        'fmt' => 22,
                        'fs' => 0,
                        'rt' => mt_rand(5, 15),
                        'of' => $this->generateRandomString(3),
                        'euri' => '',
                        'lact' => mt_rand(1000, 9000),
                        'cl' => $this->generateRandomString(9),
                        'state' => 'paused'
                    ];
                    
                    $pauseUrl = $pauseEndpoint . '?' . http_build_query($pauseParams);
                    $this->fetchPage($pauseUrl, [
                        'X-YouTube-Client-Name: 1', 
                        'X-YouTube-Client-Version: 2.20250601.00.00',
                        'Referer: ' . ($videoData['video_url'] ?? $this->url)
                    ]);
                }
                
                sleep(1); // Pause simulation
                
                // Simulate resume for certain platforms
                if ($this->siteType === 'youtube' || $this->siteType === 'youtube_shorts') {
                    $resumeParams = $pauseParams;
                    $resumeParams['state'] = 'playing';
                    $resumeUrl = $pauseEndpoint . '?' . http_build_query($resumeParams);
                    $this->fetchPage($resumeUrl, [
                        'X-YouTube-Client-Name: 1', 
                        'X-YouTube-Client-Version: 2.20250601.00.00',
                        'Referer: ' . ($videoData['video_url'] ?? $this->url)
                    ]);
                }
            }
            
            // For YouTube, send progress update
            if (($this->siteType === 'youtube' || $this->siteType === 'youtube_shorts') && 
                isset($videoData['video_id']) && !empty($videoData['video_id'])) {
                
                $progressEndpoint = 'https://www.youtube.com/api/stats/playback';
                $progressParams = [
                    'ns' => 'yt',
                    'el' => $this->siteType === 'youtube_shorts' ? 'shorts' : 'detailpage',
                    'cpn' => $this->generateRandomString(16),
                    'docid' => $videoData['video_id'],
                    'ver' => 2,
                    'referrer' => $this->url,
                    'cmt' => round($i * $segmentTime),
                    'ei' => $this->generateRandomString(16),
                    'fmt' => 22,
                    'fs' => 0,
                    'rt' => mt_rand(5, 15),
                    'of' => $this->generateRandomString(3),
                    'euri' => '',
                    'lact' => mt_rand(1000, 9000),
                    'cl' => $this->generateRandomString(9),
                    'state' => 'playing'
                ];
                
                $progressUrl = $progressEndpoint . '?' . http_build_query($progressParams);
                $this->fetchPage($progressUrl, [
                    'X-YouTube-Client-Name: 1', 
                    'X-YouTube-Client-Version: 2.20250601.00.00',
                    'Referer: ' . ($videoData['video_url'] ?? $this->url)
                ]);
            }
        }
        
        $this->logs[] = logMessage("Video completed: " . date('H:i:s'));
        $actualDuration = time() - $startTime;
        $this->logs[] = logMessage("Actual viewing time: $actualDuration seconds");
    }
    
    // Extract video source from iframe
    private function extractVideoSource($html) {
        // Look for m3u8 format video sources
        if (preg_match('/source\s+src=[\'"](.*?\.m3u8.*?)[\'"]/', $html, $m3u8Match)) {
            return $m3u8Match[1];
        }
        
        // Look for mp4 format video sources
        if (preg_match('/source\s+src=[\'"](.*?\.mp4.*?)[\'"]/', $html, $mp4Match)) {
            return $mp4Match[1];
        }
        
        // Look for video sources in JavaScript
        if (preg_match('/file:\s*[\'"](.+?)[\'"]/', $html, $jsMatch)) {
            return $jsMatch[1];
        }
        
        // Look for general source tags
        if (preg_match('/<source.*?src=[\'"](.*?)[\'"].*?>/i', $html, $sourceMatch)) {
            return $sourceMatch[1];
        }
        
        // Look for JSON data containing video URLs
        if (preg_match('/["\'](https?:\/\/[^"\']*?\.(?:mp4|m3u8|webm)[^"\']*?)["\']/i', $html, $jsonMatch)) {
            return $jsonMatch[1];
        }
        
        $this->logs[] = logMessage("No video source found.");
        return null;
    }
    
    // Generate random string
    private function generateRandomString($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }
    
    // Get logs
    public function getLogs() {
        return $this->logs;
    }
    
    // Get current proxy
    public function getCurrentProxy() {
        return $this->currentProxy;
    }
}

// ==================== PAGE PROCESSING ====================

// Process form
$message = '';
$logs = [];
$videoUrl = '';
$viewTime = 120;
$scanResult = null;
$proxyList = [];
$proxyContent = '';

// Check if there's a popup request
if (isset($_GET['popup']) && isset($_SESSION['popup_html'])) {
    echo $_SESSION['popup_html'];
    exit;
}

// Default proxy list from the provided file
if (isset($_FILES['proxy_file']) && $_FILES['proxy_file']['error'] === UPLOAD_ERR_OK) {
    $proxyContent = file_get_contents($_FILES['proxy_file']['tmp_name']);
} elseif (isset($_POST['proxy_list'])) {
    $proxyContent = $_POST['proxy_list'];
} else {
    // Use the provided proxy list as default
    $proxyContent = '
IP Address	Port	Code	Country	Anonymity	Google	Https	Last Checked
154.65.39.7	80	SN	Senegal	elite proxy	yes	no	15 secs ago
87.248.129.32	80	AE	United Arab Emirates	anonymous	no	no	19 secs ago
38.54.71.67	80	NP	Nepal	elite proxy	no	no	19 secs ago
67.43.236.20	11973	CA	Canada	elite proxy		no	19 secs ago
143.198.42.182	31280	CA	Canada	elite proxy	no	yes	19 secs ago
47.245.117.43	80	SG	Singapore	anonymous		no	19 secs ago
219.65.73.81	80	IN	India	anonymous	no	no	19 secs ago
91.103.120.39	80	HK	Hong Kong	anonymous	no	no	19 secs ago
57.129.81.201	8080	DE	Germany	anonymous	no	yes	19 secs ago
51.81.245.3	17981	US	United States	anonymous	no	yes	19 secs ago
133.18.234.13	80	JP	Japan	anonymous	no	no	19 secs ago
209.135.168.41	80	US	United States	anonymous	no	no	19 secs ago
98.191.238.177	80	US	United States	anonymous	no	no	19 secs ago
103.154.87.12	80	ID	Indonesia	anonymous	no	no	19 secs ago
91.103.120.37	80	HK	Hong Kong	anonymous	no	no	19 secs ago
41.59.90.175	80	TZ	Tanzania	anonymous	no	no	19 secs ago
4.156.78.45	80	US	United States	anonymous		no	19 secs ago
185.234.65.66	1080	NL	Netherlands	elite proxy	no	yes	19 secs ago
91.103.120.40	80	HK	Hong Kong	anonymous	yes	no	19 secs ago
198.23.143.74	80	US	United States	elite proxy	no	no	19 secs ago
123.140.146.21	5031	KR	South Korea	elite proxy	yes	no	19 secs ago
91.103.120.57	80	HK	Hong Kong	anonymous	yes	no	19 secs ago
37.60.230.56	8888	DE	Germany	anonymous	no	yes	19 secs ago
37.60.230.27	8888	DE	Germany	anonymous	no	yes	19 secs ago
77.237.76.83	80	IR	Iran	anonymous	no	no	19 secs ago
188.245.239.104	4001	DE	Germany	anonymous	no	yes	19 secs ago
123.140.146.20	5031	KR	South Korea	elite proxy	yes	no	19 secs ago
';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        // Proxy settings
        $useProxy = isset($_POST['use_proxy']) && $_POST['use_proxy'] == '1';
        $popupMode = isset($_POST['popup_mode']) && $_POST['popup_mode'] == '1';
        
        // Parse proxy list if provided
        if ($useProxy && !empty($proxyContent)) {
            $viewer = new VideoViewer('');
            $proxyList = $viewer->parseProxyList($proxyContent);
        }
        
        if ($action === 'scan' && isset($_POST['video_url'])) {
            $videoUrl = trim($_POST['video_url']);
            
            if (filter_var($videoUrl, FILTER_VALIDATE_URL)) {
                $viewer = new VideoViewer($videoUrl, $popupMode);
                
                // Apply proxy settings
                if ($useProxy && !empty($proxyList)) {
                    $viewer->setProxies($proxyList);
                    $viewer->enableProxy(true);
                }
                
                $scanResult = $viewer->scanVideoPage();
                
                if ($scanResult) {
                    // Store scan result in session
                    $_SESSION['scan_result'] = $scanResult;
                    $_SESSION['use_proxy'] = $useProxy;
                    $_SESSION['proxy_list'] = $proxyList;
                    $_SESSION['popup_mode'] = $popupMode;
                    
                    $logs = $viewer->getLogs();
                    $message = "Video page successfully scanned.";
                    
                    // If we're using a proxy, store which one was used
                    if ($useProxy) {
                        $_SESSION['current_proxy'] = $viewer->getCurrentProxy();
                    }
                } else {
                    $message = "Failed to scan video page.";
                    $logs = $viewer->getLogs();
                }
            } else {
                $message = "Please enter a valid URL.";
            }
        }
        elseif ($action === 'watch') {
            if (isset($_SESSION['scan_result'])) {
                $scanResult = $_SESSION['scan_result'];
                $viewTime = isset($_POST['view_time']) ? intval($_POST['view_time']) : 120;
                $useProxy = isset($_SESSION['use_proxy']) ? $_SESSION['use_proxy'] : false;
                $proxyList = isset($_SESSION['proxy_list']) ? $_SESSION['proxy_list'] : [];
                $popupMode = isset($_SESSION['popup_mode']) ? $_SESSION['popup_mode'] : false;
                
                $viewer = new VideoViewer($scanResult['url'], $popupMode);
                
                // Apply proxy settings
                if ($useProxy && !empty($proxyList)) {
                    $viewer->setProxies($proxyList);
                    $viewer->enableProxy(true);
                }
                
                $watchResult = $viewer->watchVideo($viewTime);
                
                $logs = $viewer->getLogs();
                
                if ($watchResult) {
                    $message = "Video successfully watched!";
                    
                    // If popup mode is enabled, provide a link to open the popup
                    if ($popupMode && isset($_SESSION['popup_html'])) {
                        $popupUrl = '?popup=1';
                        $message .= ' <a href="' . $popupUrl . '" target="_blank" class="popup-link">Open video in popup window</a>';
                    }
                } else {
                    $message = "Video watching failed.";
                }
            } else {
                $message = "Please scan the video page first.";
            }
        } elseif ($action === 'direct_watch' && isset($_POST['direct_url'])) {
            $directUrl = trim($_POST['direct_url']);
            $viewTime = isset($_POST['direct_time']) ? intval($_POST['direct_time']) : 120;
            $popupMode = isset($_POST['direct_popup']) && $_POST['direct_popup'] == '1';
            
            if (filter_var($directUrl, FILTER_VALIDATE_URL)) {
                $viewer = new VideoViewer($directUrl, $popupMode);
                
                // Apply proxy settings
                if ($useProxy && !empty($proxyList)) {
                    $viewer->setProxies($proxyList);
                    $viewer->enableProxy(true);
                }
                
                $watchResult = $viewer->watchVideo($viewTime);
                
                $logs = $viewer->getLogs();
                
                if ($watchResult) {
                    $message = "Video successfully watched!";
                    
                    // If popup mode is enabled, provide a link to open the popup
                    if ($popupMode && isset($_SESSION['popup_html'])) {
                        $popupUrl = '?popup=1';
                        $message .= ' <a href="' . $popupUrl . '" target="_blank" class="popup-link">Open video in popup window</a>';
                    }
                } else {
                    $message = "Video watching failed.";
                }
            } else {
                $message = "Please enter a valid URL.";
            }
        } elseif ($action === 'batch_watch' && isset($_POST['batch_urls'])) {
            $batchUrls = explode("\n", trim($_POST['batch_urls']));
            $viewTime = isset($_POST['batch_time']) ? intval($_POST['batch_time']) : 120;
            $popupMode = isset($_POST['batch_popup']) && $_POST['batch_popup'] == '1';
            $batchResults = [];
            
            foreach ($batchUrls as $url) {
                $url = trim($url);
                if (empty($url)) continue;
                
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    $viewer = new VideoViewer($url, $popupMode);
                    
                    // Apply proxy settings
                    if ($useProxy && !empty($proxyList)) {
                        $viewer->setProxies($proxyList);
                        $viewer->enableProxy(true);
                    }
                    
                    $watchResult = $viewer->watchVideo($viewTime);
                    $batchResults[] = [
                        'url' => $url,
                        'success' => $watchResult,
                        'logs' => $viewer->getLogs(),
                        'proxy' => $viewer->getCurrentProxy()
                    ];
                    
                    // Add logs
                    $logs = array_merge($logs, $viewer->getLogs());
                    
                    // Wait between videos to avoid rate limiting
                    sleep(mt_rand(2, 5));
                }
            }
            
            $_SESSION['batch_results'] = $batchResults;
            $message = "Batch watching completed. " . count($batchResults) . " videos processed.";
        } elseif ($action === 'auto_increase' && isset($_POST['auto_url'])) {
            $autoUrl = trim($_POST['auto_url']);
            $viewCount = isset($_POST['view_count']) ? intval($_POST['view_count']) : 10;
            $viewTime = isset($_POST['auto_time']) ? intval($_POST['auto_time']) : 30;
            $interval = isset($_POST['interval']) ? intval($_POST['interval']) : 5;
            $popupMode = isset($_POST['auto_popup']) && $_POST['auto_popup'] == '1';
            
            if (filter_var($autoUrl, FILTER_VALIDATE_URL)) {
                // Create a session variable for auto view increase
                $_SESSION['auto_increase'] = [
                    'url' => $autoUrl,
                    'count' => $viewCount,
                    'time' => $viewTime,
                    'interval' => $interval,
                    'current' => 0,
                    'start_time' => time(),
                    'use_proxy' => $useProxy,
                    'proxy_list' => $proxyList,
                    'popup_mode' => $popupMode,
                    'used_proxies' => [] // Track used proxies
                ];
                
                // Start first viewing
                $viewer = new VideoViewer($autoUrl, $popupMode);
                
                // Apply proxy settings
                if ($useProxy && !empty($proxyList)) {
                    $viewer->setProxies($proxyList);
                    $viewer->enableProxy(true);
                }
                
                $watchResult = $viewer->watchVideo($viewTime);
                
                $_SESSION['auto_increase']['current'] = 1;
                $_SESSION['auto_increase']['used_proxies'][] = $viewer->getCurrentProxy();
                $logs = $viewer->getLogs();
                
                $message = "Automatic view increase started. First viewing completed. Remaining: " . 
                           ($viewCount - 1) . " views.";
                           
                // If popup mode is enabled, provide a link to open the popup
                if ($popupMode && isset($_SESSION['popup_html'])) {
                    $popupUrl = '?popup=1';
                    $message .= ' <a href="' . $popupUrl . '" target="_blank" class="popup-link">Open video in popup window</a>';
                }
            } else {
                $message = "Please enter a valid URL.";
            }
        } elseif ($action === 'cancel_auto') {
            if (isset($_SESSION['auto_increase'])) {
                unset($_SESSION['auto_increase']);
                $message = "Automatic view increase canceled.";
            }
        } elseif ($action === 'save_settings') {
            $_SESSION['proxy_content'] = $proxyContent;
            $message = "Settings saved successfully.";
        }
    }
}

// Get scan result from session
if (isset($_SESSION['scan_result'])) {
    $scanResult = $_SESSION['scan_result'];
}

// Auto view increase check
if (isset($_SESSION['auto_increase'])) {
    $autoIncrease = $_SESSION['auto_increase'];
    $currentTime = time();
    $nextViewTime = $autoIncrease['start_time'] + ($autoIncrease['current'] * $autoIncrease['interval'] * 60);
    
    if ($autoIncrease['current'] < $autoIncrease['count'] && $currentTime >= $nextViewTime) {
        // Start next viewing
        $viewer = new VideoViewer($autoIncrease['url'], $autoIncrease['popup_mode']);
        
        // Apply proxy settings
        if ($autoIncrease['use_proxy'] && !empty($autoIncrease['proxy_list'])) {
            $viewer->setProxies($autoIncrease['proxy_list']);
            $viewer->enableProxy(true);
        }
        
        $watchResult = $viewer->watchVideo($autoIncrease['time']);
        
        $_SESSION['auto_increase']['current']++;
        $_SESSION['auto_increase']['used_proxies'][] = $viewer->getCurrentProxy();
        $logs = array_merge($logs, $viewer->getLogs());
        
        $message = "Auto view: " . $_SESSION['auto_increase']['current'] . "/" . 
                   $autoIncrease['count'] . " completed.";
        
        // If all viewings completed, clean up
        if ($_SESSION['auto_increase']['current'] >= $autoIncrease['count']) {
            $message .= " All automatic viewings completed!";
            unset($_SESSION['auto_increase']);
        }
        
        // If popup mode is enabled, provide a link to open the popup
        if ($autoIncrease['popup_mode'] && isset($_SESSION['popup_html'])) {
            $popupUrl = '?popup=1';
            $message .= ' <a href="' . $popupUrl . '" target="_blank" class="popup-link">Open video in popup window</a>';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced Video Viewer Bot</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f8f9fa;
            color: #333;
        }
        .container {
            background-color: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            position: relative;
        }
        h1, h2, h3 {
            color: #2c3e50;
        }
        h1 {
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #495057;
        }
        input[type="text"], input[type="number"], textarea, select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            box-sizing: border-box;
            transition: border-color 0.3s;
            font-size: 15px;
        }
        input[type="text"]:focus, input[type="number"]:focus, textarea:focus, select:focus {
            border-color: #4CAF50;
            outline: none;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }
        textarea {
            min-height: 120px;
            font-family: monospace;
            font-size: 14px;
            resize: vertical;
        }
        button {
            background-color: #4CAF50;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: background-color 0.3s;
        }
        button:hover {
            background-color: #3d8b40;
        }
        button.secondary {
            background-color: #6c757d;
        }
        button.secondary:hover {
            background-color: #5a6268;
        }
        button.warning {
            background-color: #ffc107;
            color: #212529;
        }
        button.warning:hover {
            background-color: #e0a800;
        }
        button.danger {
            background-color: #dc3545;
        }
        button.danger:hover {
            background-color: #bd2130;
        }
        .tabs {
            display: flex;
            flex-wrap: wrap;
            margin-bottom: 25px;
            border-bottom: 1px solid #dee2e6;
        }
        .tab {
            padding: 12px 20px;
            cursor: pointer;
            margin-right: 5px;
            margin-bottom: -1px;
            border-radius: 6px 6px 0 0;
            background-color: #f8f9fa;
            border: 1px solid transparent;
            font-weight: 500;
            transition: all 0.2s;
        }
        .tab:hover {
            background-color: #e9ecef;
        }
        .tab.active {
            background-color: white;
            border: 1px solid #dee2e6;
            border-bottom: 1px solid white;
            color: #4CAF50;
        }
        .tab-content {
            display: none;
            animation: fadeIn 0.3s;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .tab-content.active {
            display: block;
        }
        .message {
            margin: 20px 0;
            padding: 15px;
            border-radius: 6px;
            font-weight: 500;
        }
        .success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .log {
            margin-top: 25px;
            padding: 15px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            max-height: 400px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.5;
        }
        .log-entry {
            margin-bottom: 5px;
            border-bottom: 1px solid #e9ecef;
            padding-bottom: 5px;
        }
        .video-info {
            margin-top: 25px;
            padding: 20px;
            background-color: #e7f5fe;
            border: 1px solid #b8daff;
            border-radius: 6px;
        }
        .video-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 15px;
            color: #0056b3;
        }
        .video-url {
            word-break: break-all;
            font-family: monospace;
            font-size: 13px;
            background-color: #f1f1f1;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            border: 1px solid #e1e1e1;
        }
        .alternatives {
            margin-top: 15px;
        }
        .alternatives ul {
            padding-left: 20px;
        }
        .alternatives li {
            margin-bottom: 5px;
        }
        .note {
            margin-top: 20px;
            padding: 15px;
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            color: #856404;
        }
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-youtube, .badge-youtube_shorts {
            background-color: #FF0000;
            color: white;
        }
        .badge-vimeo {
            background-color: #1AB7EA;
            color: white;
        }
        .badge-hdfilm {
            background-color: #FF5722;
            color: white;
        }
        .badge-dailymotion {
            background-color: #0066DC;
            color: white;
        }
        .badge-instagram, .badge-instagram_reel {
            background-color: #E4405F;
            color: white;
        }
        .badge-tiktok {
            background-color: #000000;
            color: white;
        }
        .badge-twitter {
            background-color: #1DA1F2;
            color: white;
        }
        .badge-facebook, .badge-facebook_reel {
            background-color: #4267B2;
            color: white;
        }
        .badge-your_site {
            background-color: #673AB7;
            color: white;
        }
        .badge-unknown {
            background-color: #777777;
            color: white;
        }
        .checkbox-group {
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }
        .checkbox-group input[type="checkbox"] {
            margin-right: 8px;
            transform: scale(1.2);
        }
        .checkbox-group label {
            display: inline-block;
            font-weight: normal;
            margin: 0;
            cursor: pointer;
        }
        .batch-results {
            margin-top: 25px;
        }
        .batch-result-item {
            padding: 15px;
            margin-bottom: 15px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            background-color: #f8f9fa;
        }
        .batch-result-item.success {
            border-left: 4px solid #4CAF50;
        }
        .batch-result-item.error {
            border-left: 4px solid #dc3545;
        }
        .auto-progress {
            margin-top: 25px;
            padding: 20px;
            background-color: #e8f5e9;
            border: 1px solid #a5d6a7;
            border-radius: 6px;
        }
        .progress-bar {
            height: 20px;
            background-color: #e0e0e0;
            border-radius: 30px;
            margin-top: 15px;
            overflow: hidden;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);
        }
        .progress-fill {
            height: 100%;
            background-color: #4CAF50;
            border-radius: 30px;
            transition: width 0.3s ease;
            background-image: linear-gradient(45deg, rgba(255,255,255,.15) 25%, transparent 25%, transparent 50%, rgba(255,255,255,.15) 50%, rgba(255,255,255,.15) 75%, transparent 75%, transparent);
            background-size: 1rem 1rem;
            animation: progress-bar-stripes 1s linear infinite;
        }
        @keyframes progress-bar-stripes {
            from { background-position: 1rem 0; }
            to { background-position: 0 0; }
        }
        .proxy-info {
            margin-top: 10px;
            font-size: 14px;
            color: #6c757d;
        }
        .popup-link {
            display: inline-block;
            margin-left: 10px;
            padding: 6px 12px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
        }
        .popup-link:hover {
            background-color: #0069d9;
        }
        .features-list {
            margin-bottom: 20px;
            background-color: #f0f9ff;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #0d6efd;
        }
        .features-list h3 {
            margin-top: 0;
            color: #0d6efd;
        }
        .features-list ul {
            padding-left: 20px;
        }
        .features-list li {
            margin-bottom: 8px;
        }
        .file-upload {
            margin-top: 10px;
        }
        .help-text {
            font-size: 14px;
            color: #6c757d;
            margin-top: 5px;
        }
        .proxy-stats {
            margin-top: 10px;
            font-size: 14px;
        }
        .proxy-stats span {
            font-weight: bold;
        }

        /* Language Selector Styles */
        .language-selector {
            position: absolute;
            top: 15px;
            right: 25px;
            display: flex;
            align-items: center;
            z-index: 100;
        }
        .language-btn {
            background-color: transparent;
            border: 1px solid #ced4da;
            color: #495057;
            padding: 6px 12px;
            font-size: 14px;
            font-weight: 500;
            border-radius: 4px;
            cursor: pointer;
            margin-left: 5px;
            transition: all 0.2s;
        }
        .language-btn:hover {
            background-color: #f8f9fa;
        }
        .language-btn.active {
            background-color: #4CAF50;
            color: white;
            border-color: #4CAF50;
        }
        .language-btn img {
            width: 20px;
            height: 14px;
            margin-right: 5px;
            vertical-align: middle;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Language Selector -->
        <div class="language-selector">
            <button class="language-btn" data-lang="en" id="lang-en">
                <img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMjM1IDY1MCIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiPgo8ZGVmcz4KPGcgaWQ9InVuaW9uIj4KPHJlY3QgeT0iLTEiIHdpZHRoPSI3LjA3MSIgaGVpZ2h0PSIyIiBmaWxsPSIjZmZmIi8+CjxwYXRoIGQ9Ik0gMCwwIEwgNy4wNzEsLTEgTCA3LjA3MSwxIEwgMCwwIHoiIGZpbGw9IiNmZmYiLz4KPC9nPgo8ZyBpZD0iYmFzZSI+Cjx1c2UgeGxpbms6aHJlZj0iI3VuaW9uIi8+CjxnIHRyYW5zZm9ybT0icm90YXRlKDYwKSI+PHVzZSB4bGluazpocmVmPSIjdW5pb24iLz48L2c+CjxnIHRyYW5zZm9ybT0icm90YXRlKDEyMCkiPjx1c2UgeGxpbms6aHJlZj0iI3VuaW9uIi8+PC9nPgo8ZyB0cmFuc2Zvcm09InJvdGF0ZSgxODApIj48dXNlIHhsaW5rOmhyZWY9IiN1bmlvbiIvPjwvZz4KPGcgdHJhbnNmb3JtPSJyb3RhdGUoMjQwKSI+PHVzZSB4bGluazpocmVmPSIjdW5pb24iLz48L2c+CjxnIHRyYW5zZm9ybT0icm90YXRlKDMwMCkiPjx1c2UgeGxpbms6aHJlZj0iI3VuaW9uIi8+PC9nPgo8L2c+CjwvZGVmcz4KPHJlY3Qgd2lkdGg9IjEyMzUiIGhlaWdodD0iNjUwIiBmaWxsPSIjMDEyMTY5Ii8+Cjx1c2UgeGxpbms6aHJlZj0iI2Jhc2UiIHRyYW5zZm9ybT0idHJhbnNsYXRlKDI0Ny41LDEyNSkgc2NhbGUoNSkiLz4KPHVzZSB4bGluazpocmVmPSIjYmFzZSIgdHJhbnNmb3JtPSJ0cmFuc2xhdGUoMjQ3LjUsNDEwKSBzY2FsZSg1KSIvPgo8dXNlIHhsaW5rOmhyZWY9IiNiYXNlIiB0cmFuc2Zvcm09InRyYW5zbGF0ZSg3NDcuNSwxMjUpIHNjYWxlKDUpIi8+Cjx1c2UgeGxpbms6aHJlZj0iI2Jhc2UiIHRyYW5zZm9ybT0idHJhbnNsYXRlKDc0Ny41LDQxMCkgc2NhbGUoNSkiLz4KPHBhdGggZD0iTTAgMCBMIDEyMzUgNjUwIE0gMTIzNSAwIEwgMCA2NTAiIHN0cm9rZT0iI2ZmZiIgc3Ryb2tlLXdpZHRoPSIxMDAiLz4KPHBhdGggZD0iTSAwIDAgTCAxMjM1IDY1MCBNIDEyMzUgMCBMIDAgNjUwIiBzdHJva2U9IiNDODEwMkUiIHN0cm9rZS13aWR0aD0iNjAiLz4KPHBhdGggZD0iTSA2MTcuNSAwIEwgNjE3LjUgNjUwIE0gMCAzMjUgTCAxMjM1IDMyNSIgc3Ryb2tlPSIjZmZmIiBzdHJva2Utd2lkdGg9IjEwMCIvPgo8cGF0aCBkPSJNIDYxNy41IDAgTCA2MTcuNSA2NTAgTSAwIDMyNSBMIDEyMzUgMzI1IiBzdHJva2U9IiNDODEwMkUiIHN0cm9rZS13aWR0aD0iNjAiLz4KPC9zdmc+Cg==" alt="English">
                EN
            </button>
            <button class="language-btn" data-lang="tr" id="lang-tr">
                <img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMjAwIDgwMCI+DQo8cmVjdCB3aWR0aD0iMTIwMCIgaGVpZ2h0PSI4MDAiIGZpbGw9IiNFMzBBMTciLz4NCjxjaXJjbGUgY3g9IjQyNSIgY3k9IjQwMCIgcj0iMjAwIiBmaWxsPSIjZmZmZmZmIi8+DQo8Y2lyY2xlIGN4PSI0NzUiIGN5PSI0MDAiIHI9IjE2MCIgZmlsbD0iI0UzMEExNyIvPg0KPHBvbHlnb24gcG9pbnRzPSI1ODMuMzM0LDQwMCA4MDAsNDAwIDYzMi41LDUyOC4xNjkgNzA4LjI1MywzMzguMTcwIDcwOC4yNTMsNDYxLjgzMCA2MzIuNSwyNzEuODMxIiBmaWxsPSIjZmZmZmZmIi8+DQo8L3N2Zz4NCg==" alt="Türkçe">
                TR
            </button>
        </div>

        <h1 data-lang-key="title">Enhanced Video Viewer Bot with Proxy Support</h1>
        
        <div class="features-list">
            <h3 data-lang-key="key_features">Key Features</h3>
            <ul>
                <li><strong data-lang-key="proxy_integration">Proxy Integration:</strong> <span data-lang-key="proxy_integration_desc">Use the provided free proxy list to route traffic through different IPs</span></li>
                <li><strong data-lang-key="ad_free_popup">Ad-free Popup Player:</strong> <span data-lang-key="ad_free_popup_desc">Open videos in a clean popup window to bypass ads</span></li>
                <li><strong data-lang-key="multi_platform">Multi-Platform Support:</strong> <span data-lang-key="multi_platform_desc">Works with YouTube, Vimeo, Instagram, TikTok, Twitter, Facebook, and more</span></li>
                <li><strong data-lang-key="realistic_viewing">Realistic Viewing:</strong> <span data-lang-key="realistic_viewing_desc">Simulates real user behavior with volume changes, pauses, and progress updates</span></li>
                <li><strong data-lang-key="batch_automated">Batch & Automated Modes:</strong> <span data-lang-key="batch_automated_desc">Process multiple videos or schedule repeated views</span></li>
            </ul>
        </div>
        
        <div class="tabs">
            <div class="tab active" onclick="switchTab('standard')" data-lang-key="standard_mode">Standard Mode</div>
            <div class="tab" onclick="switchTab('direct')" data-lang-key="direct_url">Direct URL</div>
            <div class="tab" onclick="switchTab('batch')" data-lang-key="batch_viewing">Batch Viewing</div>
            <div class="tab" onclick="switchTab('auto')" data-lang-key="auto_increase">Auto Increase</div>
            <div class="tab" onclick="switchTab('settings')" data-lang-key="proxy_settings">Proxy Settings</div>
            <div class="tab" onclick="switchTab('help')" data-lang-key="help">Help</div>
        </div>
        
        <div class="tab-content active" id="standard">
            <h2 data-lang-key="standard_video_viewing">Standard Video Viewing</h2>
            
            <form method="post" action="" enctype="multipart/form-data">
                <input type="hidden" name="action" value="scan">
                
                <div class="form-group">
                    <label for="video_url" data-lang-key="video_page_url">Video Page URL:</label>
                    <input type="text" id="video_url" name="video_url" placeholder="https://www.youtube.com/watch?v=dQw4w9WgXcQ" value="<?php echo htmlspecialchars($videoUrl); ?>" required>
                    <div class="help-text" data-lang-key="video_url_help">Enter the URL of any video page from YouTube, Vimeo, Instagram, TikTok, etc.</div>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="use_proxy" name="use_proxy" value="1" <?php echo (isset($_SESSION['use_proxy']) && $_SESSION['use_proxy']) ? 'checked' : ''; ?>>
                    <label for="use_proxy" data-lang-key="use_proxy">Use Proxy</label>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="popup_mode" name="popup_mode" value="1" <?php echo (isset($_SESSION['popup_mode']) && $_SESSION['popup_mode']) ? 'checked' : ''; ?>>
                    <label for="popup_mode" data-lang-key="enable_popup">Enable Ad-Free Popup Player</label>
                </div>
                
                <button type="submit" data-lang-key="scan_video">Scan Video</button>
            </form>
            
            <?php if ($scanResult): ?>
                <div class="video-info">
                    <div class="video-title">
                        <?php echo htmlspecialchars($scanResult['title'] ?? 'Untitled Video'); ?>
                        <span class="badge badge-<?php echo $scanResult['site_type']; ?>"><?php echo $scanResult['site_type']; ?></span>
                    </div>
                    
                    <div>
                        <strong data-lang-key="page_url">Page URL:</strong>
                        <div class="video-url"><?php echo htmlspecialchars($scanResult['url']); ?></div>
                    </div>
                    
                    <?php if (!empty($scanResult['video_url'])): ?>
                        <div>
                            <strong data-lang-key="video_url">Video URL:</strong>
                            <div class="video-url"><?php echo htmlspecialchars($scanResult['video_url']); ?></div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($scanResult['duration']) && $scanResult['duration'] > 0): ?>
                        <div>
                            <strong data-lang-key="video_duration">Video Duration:</strong>
                            <span><?php echo $scanResult['duration']; ?> <span data-lang-key="seconds">seconds</span></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['current_proxy']) && !empty($_SESSION['current_proxy'])): ?>
                        <div class="proxy-info">
                            <strong data-lang-key="used_proxy">Used Proxy:</strong> <?php echo htmlspecialchars($_SESSION['current_proxy']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($scanResult['alternatives'])): ?>
                        <div class="alternatives">
                            <strong data-lang-key="video_alternatives">Video Alternatives:</strong>
                            <ul>
                                <?php foreach ($scanResult['alternatives'] as $alt): ?>
                                    <li><?php echo htmlspecialchars($alt['title']); ?> (ID: <?php echo $alt['id']; ?>)</li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <form method="post" action="">
                        <input type="hidden" name="action" value="watch">
                        
                        <div class="form-group">
                            <label for="view_time" data-lang-key="viewing_time">Viewing Time (seconds):</label>
                            <input type="number" id="view_time" name="view_time" min="10" max="3600" value="<?php echo $viewTime; ?>">
                            <div class="help-text" data-lang-key="viewing_time_help">Recommended: 60-120 seconds for short videos, 300+ for longer content</div>
                        </div>
                        
                        <button type="submit" data-lang-key="watch_video">Watch Video</button>
                        
                        <?php if (isset($_SESSION['popup_mode']) && $_SESSION['popup_mode'] && isset($_SESSION['popup_html'])): ?>
                            <a href="?popup=1" target="_blank" class="popup-link" data-lang-key="open_popup">Open in Popup</a>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="tab-content" id="direct">
            <h2 data-lang-key="direct_url_viewing">Direct URL Viewing</h2>
            <p data-lang-key="direct_url_desc">If you know the direct video URL or embed URL, you can watch it directly without scanning.</p>
            
            <form method="post" action="">
                <input type="hidden" name="action" value="direct_watch">
                
                <div class="form-group">
                    <label for="direct_url" data-lang-key="video_url_label">Video URL:</label>
                    <input type="text" id="direct_url" name="direct_url" placeholder="https://www.youtube.com/embed/dQw4w9WgXcQ" required>
                    <div class="help-text" data-lang-key="direct_url_help">Enter a direct video URL, embed URL, or iframe source</div>
                </div>
                
                <div class="form-group">
                    <label for="direct_time" data-lang-key="viewing_time">Viewing Time (seconds):</label>
                    <input type="number" id="direct_time" name="direct_time" min="10" max="3600" value="120">
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="direct_use_proxy" name="use_proxy" value="1" <?php echo (isset($_SESSION['use_proxy']) && $_SESSION['use_proxy']) ? 'checked' : ''; ?>>
                    <label for="direct_use_proxy" data-lang-key="use_proxy">Use Proxy</label>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="direct_popup" name="direct_popup" value="1">
                    <label for="direct_popup" data-lang-key="enable_popup">Enable Ad-Free Popup Player</label>
                </div>
                
                <button type="submit" data-lang-key="watch_video">Watch Video</button>
            </form>
        </div>
        
        <div class="tab-content" id="batch">
            <h2 data-lang-key="batch_video_viewing">Batch Video Viewing</h2>
            <p data-lang-key="batch_desc">Process multiple videos in sequence. Each video will be watched with a different proxy for better anonymity.</p>
            
            <form method="post" action="">
                <input type="hidden" name="action" value="batch_watch">
                
                <div class="form-group">
                    <label for="batch_urls" data-lang-key="batch_urls_label">Video URLs (One per line):</label>
                    <textarea id="batch_urls" name="batch_urls" placeholder="https://www.youtube.com/watch?v=video1&#10;https://vimeo.com/video2&#10;https://www.instagram.com/p/post3" required></textarea>
                    <div class="help-text" data-lang-key="batch_urls_help">Each URL will be processed separately with a different proxy if available</div>
                </div>
                
                <div class="form-group">
                    <label for="batch_time" data-lang-key="batch_time_label">Viewing Time Per Video (seconds):</label>
                    <input type="number" id="batch_time" name="batch_time" min="10" max="3600" value="60">
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="batch_use_proxy" name="use_proxy" value="1" <?php echo (isset($_SESSION['use_proxy']) && $_SESSION['use_proxy']) ? 'checked' : ''; ?>>
                    <label for="batch_use_proxy" data-lang-key="use_proxy">Use Proxy</label>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="batch_popup" name="batch_popup" value="1">
                    <label for="batch_popup" data-lang-key="enable_popup">Enable Ad-Free Popup Player</label>
                    <div class="help-text" data-lang-key="batch_popup_note">Note: Only the last video's popup will be available</div>
                </div>
                
                <button type="submit" data-lang-key="start_batch">Start Batch Viewing</button>
            </form>
            
            <?php if (isset($_SESSION['batch_results']) && !empty($_SESSION['batch_results'])): ?>
                <div class="batch-results">
                    <h3 data-lang-key="batch_results">Batch Results</h3>
                    
                    <?php foreach ($_SESSION['batch_results'] as $index => $result): ?>
                        <div class="batch-result-item <?php echo $result['success'] ? 'success' : 'error'; ?>">
                            <strong data-lang-key="url_number">URL #<?php echo $index + 1; ?>:</strong> <?php echo htmlspecialchars($result['url']); ?><br>
                            <strong data-lang-key="status">Status:</strong> <span data-lang-key="<?php echo $result['success'] ? 'success_status' : 'failed_status'; ?>"><?php echo $result['success'] ? 'Success' : 'Failed'; ?></span>
                            <?php if (!empty($result['proxy'])): ?>
                                <div class="proxy-info">
                                    <strong data-lang-key="used_proxy">Used Proxy:</strong> <?php echo htmlspecialchars($result['proxy']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="tab-content" id="auto">
            <h2 data-lang-key="automatic_view_increase">Automatic View Increase</h2>
            <p data-lang-key="auto_desc">Automatically watch a video multiple times at specified intervals to increase view count.</p>
            
            <form method="post" action="">
                <input type="hidden" name="action" value="auto_increase">
                
                <div class="form-group">
                    <label for="auto_url" data-lang-key="video_url_label">Video URL:</label>
                    <input type="text" id="auto_url" name="auto_url" placeholder="https://www.youtube.com/watch?v=dQw4w9WgXcQ" required>
                </div>
                
                <div class="form-group">
                    <label for="view_count" data-lang-key="number_of_views">Number of Views:</label>
                    <input type="number" id="view_count" name="view_count" min="1" max="100" value="10">
                    <div class="help-text" data-lang-key="number_of_views_help">How many times to watch the video (1-100)</div>
                </div>
                
                <div class="form-group">
                    <label for="auto_time" data-lang-key="time_per_view">Time Per View (seconds):</label>
                    <input type="number" id="auto_time" name="auto_time" min="10" max="3600" value="30">
                    <div class="help-text" data-lang-key="time_per_view_help">How long to watch each time</div>
                </div>
                
                <div class="form-group">
                    <label for="interval" data-lang-key="interval_between_views">Interval Between Views (minutes):</label>
                    <input type="number" id="interval" name="interval" min="1" max="60" value="5">
                    <div class="help-text" data-lang-key="interval_between_views_help">Wait time between each view (1-60 minutes)</div>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="auto_use_proxy" name="use_proxy" value="1" <?php echo (isset($_SESSION['use_proxy']) && $_SESSION['use_proxy']) ? 'checked' : ''; ?>>
                    <label for="auto_use_proxy" data-lang-key="use_proxy">Use Proxy</label>
                    <div class="help-text" data-lang-key="auto_proxy_help">A different proxy will be used for each view when possible</div>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="auto_popup" name="auto_popup" value="1">
                    <label for="auto_popup" data-lang-key="enable_popup">Enable Ad-Free Popup Player</label>
                </div>
                
                <button type="submit" data-lang-key="start_auto_viewing">Start Auto Viewing</button>
            </form>
            
            <?php if (isset($_SESSION['auto_increase'])): ?>
                <div class="auto-progress">
                    <h3 data-lang-key="automatic_view_progress">Automatic View Progress</h3>
                    <p data-lang-key="url_label">URL: <?php echo htmlspecialchars($_SESSION['auto_increase']['url']); ?></p>
                    <p data-lang-key="progress_label">Progress: <?php echo $_SESSION['auto_increase']['current']; ?> / <?php echo $_SESSION['auto_increase']['count']; ?> <span data-lang-key="views">views</span></p>
                    
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo ($_SESSION['auto_increase']['current'] / $_SESSION['auto_increase']['count']) * 100; ?>%;"></div>
                    </div>
                    
                    <?php
                    $nextViewTime = $_SESSION['auto_increase']['start_time'] + ($_SESSION['auto_increase']['current'] * $_SESSION['auto_increase']['interval'] * 60);
                    $waitTime = max(0, $nextViewTime - time());
                    $waitMinutes = floor($waitTime / 60);
                    $waitSeconds = $waitTime % 60;
                    ?>
                    
                    <p data-lang-key="next_view_in">Next view in: <span class="time-remaining"><?php echo $waitMinutes; ?> <span data-lang-key="minutes">minutes</span> <?php echo $waitSeconds; ?> <span data-lang-key="seconds">seconds</span></span></p>
                    
                    <?php if (!empty($_SESSION['auto_increase']['used_proxies'])): ?>
                        <div class="proxy-stats">
                            <p data-lang-key="used_proxies_so_far">Used <span><?php echo count($_SESSION['auto_increase']['used_proxies']); ?></span> different proxies so far</p>
                        </div>
                    <?php endif; ?>
                    
                    <form method="post" action="">
                        <input type="hidden" name="action" value="cancel_auto">
                        <button type="submit" class="danger" data-lang-key="cancel_auto_viewing">Cancel Auto Viewing</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="tab-content" id="settings">
            <h2 data-lang-key="proxy_settings">Proxy Settings</h2>
            
            <form method="post" action="" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_settings">
                
                <div class="form-group">
                    <label for="proxy_list" data-lang-key="proxy_list">Proxy List:</label>
                    <textarea id="proxy_list" name="proxy_list" placeholder="IP Address&#9;Port&#9;Code&#9;Country&#9;Anonymity&#9;Google&#9;Https&#9;Last Checked&#10;154.65.39.7&#9;80&#9;SN&#9;Senegal&#9;elite proxy&#9;yes&#9;no&#9;15 secs ago"><?php echo htmlspecialchars($proxyContent); ?></textarea>
                    <div class="help-text" data-lang-key="proxy_list_help">Enter proxies in the format shown above, or upload a proxy list file</div>
                </div>
                
                <div class="file-upload">
                    <label for="proxy_file" data-lang-key="upload_proxy_file">Or Upload Proxy List File:</label>
                    <input type="file" id="proxy_file" name="proxy_file">
                </div>
                
                <?php 
                if (!empty($proxyList)) {
                    $httpsProxies = 0;
                    $eliteProxies = 0;
                    
                    foreach ($proxyList as $proxy) {
                        if (isset($proxy['https']) && $proxy['https'] === true) {
                            $httpsProxies++;
                        }
                        if (isset($proxy['elite']) && $proxy['elite'] === true) {
                            $eliteProxies++;
                        }
                    }
                    
                    echo '<div class="proxy-stats">';
                    echo '<p data-lang-key="loaded_proxies">Loaded <span>' . count($proxyList) . '</span> proxies: <span>' . $httpsProxies . '</span> HTTPS, <span>' . $eliteProxies . '</span> Elite</p>';
                    echo '</div>';
                }
                ?>
                
                <div class="note">
                    <p><strong data-lang-key="about_proxy_list">About the Proxy List:</strong></p>
                    <p data-lang-key="proxy_list_note1">The included list of free proxies is updated every 10 minutes. Elite proxies offer the highest level of anonymity. HTTPS proxies are recommended for secure sites.</p>
                    <p data-lang-key="proxy_list_note2">For best results, use a mix of proxies from different countries to simulate diverse traffic.</p>
                </div>
                
                <button type="submit" data-lang-key="save_settings">Save Settings</button>
            </form>
        </div>
        
        <div class="tab-content" id="help">
            <h2 data-lang-key="help_information">Help & Information</h2>
            
            <h3 data-lang-key="supported_platforms">Supported Platforms</h3>
            <ul>
                <li><strong data-lang-key="youtube">YouTube</strong> - <span data-lang-key="youtube_desc">Regular videos and Shorts</span></li>
                <li><strong data-lang-key="vimeo">Vimeo</strong> - <span data-lang-key="vimeo_desc">All videos</span></li>
                <li><strong data-lang-key="hdfilm">HDFilm/Movie Sites</strong> - <span data-lang-key="hdfilm_desc">Film pages with embedded players</span></li>
                <li><strong data-lang-key="instagram">Instagram</strong> - <span data-lang-key="instagram_desc">Posts and Reels</span></li>
                <li><strong data-lang-key="tiktok">TikTok</strong> - <span data-lang-key="tiktok_desc">Video content</span></li>
                <li><strong data-lang-key="twitter">Twitter/X</strong> - <span data-lang-key="twitter_desc">Video tweets</span></li>
                <li><strong data-lang-key="facebook">Facebook</strong> - <span data-lang-key="facebook_desc">Video posts and Reels</span></li>
                <li><strong data-lang-key="dailymotion">Dailymotion</strong> - <span data-lang-key="dailymotion_desc">All videos</span></li>
                <li><strong data-lang-key="other_sites">Other sites</strong> - <span data-lang-key="other_sites_desc">Generic iframe/video detection</span></li>
            </ul>
            
            <h3 data-lang-key="viewing_modes">Viewing Modes</h3>
            <ol>
                <li><strong data-lang-key="standard_mode_help">Standard Mode:</strong> <span data-lang-key="standard_mode_desc">Scan a video page first, then watch it with control over viewing time</span></li>
                <li><strong data-lang-key="direct_url_mode_help">Direct URL Mode:</strong> <span data-lang-key="direct_url_mode_desc">Watch videos directly by their embed URL, bypassing the scanning step</span></li>
                <li><strong data-lang-key="batch_mode_help">Batch Mode:</strong> <span data-lang-key="batch_mode_desc">Process multiple videos sequentially, using different proxies for each</span></li>
                <li><strong data-lang-key="auto_increase_mode_help">Auto Increase Mode:</strong> <span data-lang-key="auto_increase_mode_desc">Watch a single video repeatedly at intervals to increase view count</span></li>
            </ol>
            
            <h3 data-lang-key="ad_free_popup_player">Ad-Free Popup Player</h3>
            <p data-lang-key="ad_free_popup_player_desc">The popup player feature allows you to watch videos without the surrounding page elements and ads. It works by:</p>
            <ol>
                <li data-lang-key="popup_feature1">Extracting the video embed URL from the original page</li>
                <li data-lang-key="popup_feature2">Creating a clean, minimal player window</li>
                <li data-lang-key="popup_feature3">Loading only the video content directly</li>
                <li data-lang-key="popup_feature4">Bypassing most pre-roll and overlay ads</li>
            </ol>
            <p data-lang-key="popup_feature_note">This feature is especially useful for film sites with excessive ads and popups.</p>
            
            <h3 data-lang-key="using_proxies_effectively">Using Proxies Effectively</h3>
            <p data-lang-key="proxy_rotation_helps">Proxy rotation helps avoid detection and rate limiting:</p>
            <ul>
                <li data-lang-key="proxy_tip1">For YouTube, use Elite proxies that support HTTPS</li>
                <li data-lang-key="proxy_tip2">For general browsing, anonymous proxies are sufficient</li>
                <li data-lang-key="proxy_tip3">In Batch mode, each video gets a different proxy automatically</li>
                <li data-lang-key="proxy_tip4">In Auto Increase mode, proxies rotate between views</li>
            </ul>
            
            <h3 data-lang-key="finding_video_urls">Finding Video URLs</h3>
            <ol>
                <li data-lang-key="find_url_step1">Open the video page in your browser</li>
                <li data-lang-key="find_url_step2">Copy the page URL and scan it with the "Scan Video" button</li>
                <li data-lang-key="find_url_step3">If scanning fails, try these methods:</li>
                <li data-lang-key="find_url_step4">Right-click on the page and select "Inspect" or "Inspect Element"</li>
                <li data-lang-key="find_url_step5">Go to the "Network" tab in the developer tools</li>
                <li data-lang-key="find_url_step6">Refresh the page (F5) and click the video play button</li>
                <li data-lang-key="find_url_step7">Look for requests with "m3u8", "mp4" extensions or "iframe" in the Name column</li>
                <li data-lang-key="find_url_step8">Copy the URL of these requests and use it in the "Direct URL" tab</li>
            </ol>
            
            <div class="note">
                <strong data-lang-key="note">Note:</strong> <span data-lang-key="disclaimer">This tool is for educational purposes only. Using automated tools to artificially increase view counts may violate the terms of service of video platforms. The responsibility for using this tool lies with the user.</span>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?php echo strpos($message, 'success') !== false || strpos($message, 'completed') !== false ? 'success' : 'error'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($logs)): ?>
            <div class="log">
                <h3 data-lang-key="process_logs">Process Logs:</h3>
                <?php foreach ($logs as $log): ?>
                    <div class="log-entry"><?php echo htmlspecialchars($log); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Language configurations
        const languages = {
            en: {
                // Header and General
                "title": "Enhanced Video Viewer Bot with Proxy Support",
                "key_features": "Key Features",
                "proxy_integration": "Proxy Integration",
                "proxy_integration_desc": "Use the provided free proxy list to route traffic through different IPs",
                "ad_free_popup": "Ad-free Popup Player",
                "ad_free_popup_desc": "Open videos in a clean popup window to bypass ads",
                "multi_platform": "Multi-Platform Support",
                "multi_platform_desc": "Works with YouTube, Vimeo, Instagram, TikTok, Twitter, Facebook, and more",
                "realistic_viewing": "Realistic Viewing",
                "realistic_viewing_desc": "Simulates real user behavior with volume changes, pauses, and progress updates",
                "batch_automated": "Batch & Automated Modes",
                "batch_automated_desc": "Process multiple videos or schedule repeated views",
                
                // Tabs
                "standard_mode": "Standard Mode",
                "direct_url": "Direct URL",
                "batch_viewing": "Batch Viewing",
                "auto_increase": "Auto Increase",
                "proxy_settings": "Proxy Settings",
                "help": "Help",
                
                // Standard Mode
                "standard_video_viewing": "Standard Video Viewing",
                "video_page_url": "Video Page URL:",
                "video_url_help": "Enter the URL of any video page from YouTube, Vimeo, Instagram, TikTok, etc.",
                "use_proxy": "Use Proxy",
                "enable_popup": "Enable Ad-Free Popup Player",
                "scan_video": "Scan Video",
                "page_url": "Page URL:",
                "video_url": "Video URL:",
                "video_duration": "Video Duration:",
                "seconds": "seconds",
                "used_proxy": "Used Proxy:",
                "video_alternatives": "Video Alternatives:",
                "viewing_time": "Viewing Time (seconds):",
                "viewing_time_help": "Recommended: 60-120 seconds for short videos, 300+ for longer content",
                "watch_video": "Watch Video",
                "open_popup": "Open in Popup",
                
                // Direct URL
                "direct_url_viewing": "Direct URL Viewing",
                "direct_url_desc": "If you know the direct video URL or embed URL, you can watch it directly without scanning.",
                "video_url_label": "Video URL:",
                "direct_url_help": "Enter a direct video URL, embed URL, or iframe source",
                
                // Batch Viewing
                "batch_video_viewing": "Batch Video Viewing",
                "batch_desc": "Process multiple videos in sequence. Each video will be watched with a different proxy for better anonymity.",
                "batch_urls_label": "Video URLs (One per line):",
                "batch_urls_help": "Each URL will be processed separately with a different proxy if available",
                "batch_time_label": "Viewing Time Per Video (seconds):",
                "batch_popup_note": "Note: Only the last video's popup will be available",
                "start_batch": "Start Batch Viewing",
                "batch_results": "Batch Results",
                "url_number": "URL #",
                "status": "Status:",
                "success_status": "Success",
                "failed_status": "Failed",
                
                // Auto Increase
                "automatic_view_increase": "Automatic View Increase",
                "auto_desc": "Automatically watch a video multiple times at specified intervals to increase view count.",
                "number_of_views": "Number of Views:",
                "number_of_views_help": "How many times to watch the video (1-100)",
                "time_per_view": "Time Per View (seconds):",
                "time_per_view_help": "How long to watch each time",
                "interval_between_views": "Interval Between Views (minutes):",
                "interval_between_views_help": "Wait time between each view (1-60 minutes)",
                "auto_proxy_help": "A different proxy will be used for each view when possible",
                "start_auto_viewing": "Start Auto Viewing",
                "automatic_view_progress": "Automatic View Progress",
                "url_label": "URL:",
                "progress_label": "Progress:",
                "views": "views",
                "next_view_in": "Next view in:",
                "minutes": "minutes",
                "used_proxies_so_far": "Used different proxies so far",
                "cancel_auto_viewing": "Cancel Auto Viewing",
                
                // Proxy Settings
                "proxy_list": "Proxy List:",
                "proxy_list_help": "Enter proxies in the format shown above, or upload a proxy list file",
                "upload_proxy_file": "Or Upload Proxy List File:",
                "loaded_proxies": "Loaded proxies: HTTPS, Elite",
                "about_proxy_list": "About the Proxy List:",
                "proxy_list_note1": "The included list of free proxies is updated every 10 minutes. Elite proxies offer the highest level of anonymity. HTTPS proxies are recommended for secure sites.",
                "proxy_list_note2": "For best results, use a mix of proxies from different countries to simulate diverse traffic.",
                "save_settings": "Save Settings",
                
                // Help
                "help_information": "Help & Information",
                "supported_platforms": "Supported Platforms",
                "youtube": "YouTube",
                "youtube_desc": "Regular videos and Shorts",
                "vimeo": "Vimeo",
                "vimeo_desc": "All videos",
                "hdfilm": "HDFilm/Movie Sites",
                "hdfilm_desc": "Film pages with embedded players",
                "instagram": "Instagram",
                "instagram_desc": "Posts and Reels",
                "tiktok": "TikTok",
                "tiktok_desc": "Video content",
                "twitter": "Twitter/X",
                "twitter_desc": "Video tweets",
                "facebook": "Facebook",
                "facebook_desc": "Video posts and Reels",
                "dailymotion": "Dailymotion",
                "dailymotion_desc": "All videos",
                "other_sites": "Other sites",
                "other_sites_desc": "Generic iframe/video detection",
                
                "viewing_modes": "Viewing Modes",
                "standard_mode_help": "Standard Mode:",
                "standard_mode_desc": "Scan a video page first, then watch it with control over viewing time",
                "direct_url_mode_help": "Direct URL Mode:",
                "direct_url_mode_desc": "Watch videos directly by their embed URL, bypassing the scanning step",
                "batch_mode_help": "Batch Mode:",
                "batch_mode_desc": "Process multiple videos sequentially, using different proxies for each",
                "auto_increase_mode_help": "Auto Increase Mode:",
                "auto_increase_mode_desc": "Watch a single video repeatedly at intervals to increase view count",
                
                "ad_free_popup_player": "Ad-Free Popup Player",
                "ad_free_popup_player_desc": "The popup player feature allows you to watch videos without the surrounding page elements and ads. It works by:",
                "popup_feature1": "Extracting the video embed URL from the original page",
                "popup_feature2": "Creating a clean, minimal player window",
                "popup_feature3": "Loading only the video content directly",
                "popup_feature4": "Bypassing most pre-roll and overlay ads",
                "popup_feature_note": "This feature is especially useful for film sites with excessive ads and popups.",
                
                "using_proxies_effectively": "Using Proxies Effectively",
                "proxy_rotation_helps": "Proxy rotation helps avoid detection and rate limiting:",
                "proxy_tip1": "For YouTube, use Elite proxies that support HTTPS",
                "proxy_tip2": "For general browsing, anonymous proxies are sufficient",
                "proxy_tip3": "In Batch mode, each video gets a different proxy automatically",
                "proxy_tip4": "In Auto Increase mode, proxies rotate between views",
                
                "finding_video_urls": "Finding Video URLs",
                "find_url_step1": "Open the video page in your browser",
                "find_url_step2": "Copy the page URL and scan it with the \"Scan Video\" button",
                "find_url_step3": "If scanning fails, try these methods:",
                "find_url_step4": "Right-click on the page and select \"Inspect\" or \"Inspect Element\"",
                "find_url_step5": "Go to the \"Network\" tab in the developer tools",
                "find_url_step6": "Refresh the page (F5) and click the video play button",
                "find_url_step7": "Look for requests with \"m3u8\", \"mp4\" extensions or \"iframe\" in the Name column",
                "find_url_step8": "Copy the URL of these requests and use it in the \"Direct URL\" tab",
                
                "note": "Note:",
                "disclaimer": "This tool is for educational purposes only. Using automated tools to artificially increase view counts may violate the terms of service of video platforms. The responsibility for using this tool lies with the user.",
                
                "process_logs": "Process Logs:",
            },
            tr: {
                // Başlık ve Genel
                "title": "Gelişmiş Video İzleme Botu (Proxy Destekli)",
                "key_features": "Temel Özellikler",
                "proxy_integration": "Proxy Entegrasyonu",
                "proxy_integration_desc": "Farklı IP'ler üzerinden trafik yönlendirmek için ücretsiz proxy listesini kullanın",
                "ad_free_popup": "Reklamsız Açılır Oynatıcı",
                "ad_free_popup_desc": "Videoları reklamları atlayarak temiz bir açılır pencerede izleyin",
                "multi_platform": "Çoklu Platform Desteği",
                "multi_platform_desc": "YouTube, Vimeo, Instagram, TikTok, Twitter, Facebook ve daha fazlasıyla çalışır",
                "realistic_viewing": "Gerçekçi İzleme",
                "realistic_viewing_desc": "Ses seviyesi değişiklikleri, duraklatmalar ve ilerleme güncellemeleriyle gerçek kullanıcı davranışını simüle eder",
                "batch_automated": "Toplu ve Otomatik Modlar",
                "batch_automated_desc": "Birden fazla videoyu işleyin veya tekrarlanan görüntülemeleri planlayın",
                
                // Sekmeler
                "standard_mode": "Standart Mod",
                "direct_url": "Doğrudan URL",
                "batch_viewing": "Toplu İzleme",
                "auto_increase": "Otomatik Artırma",
                "proxy_settings": "Proxy Ayarları",
                "help": "Yardım",
                
                // Standart Mod
                "standard_video_viewing": "Standart Video İzleme",
                "video_page_url": "Video Sayfası URL'si:",
                "video_url_help": "YouTube, Vimeo, Instagram, TikTok vb. herhangi bir video sayfasının URL'sini girin",
                "use_proxy": "Proxy Kullan",
                "enable_popup": "Reklamsız Açılır Oynatıcıyı Etkinleştir",
                "scan_video": "Videoyu Tara",
                "page_url": "Sayfa URL'si:",
                "video_url": "Video URL'si:",
                "video_duration": "Video Süresi:",
                "seconds": "saniye",
                "used_proxy": "Kullanılan Proxy:",
                "video_alternatives": "Video Alternatifleri:",
                "viewing_time": "İzleme Süresi (saniye):",
                "viewing_time_help": "Önerilen: Kısa videolar için 60-120 saniye, uzun içerikler için 300+ saniye",
                "watch_video": "Videoyu İzle",
                "open_popup": "Açılır Pencerede Aç",
                
                // Doğrudan URL
                "direct_url_viewing": "Doğrudan URL İzleme",
                "direct_url_desc": "Doğrudan video URL'sini veya gömme URL'sini biliyorsanız, tarama yapmadan doğrudan izleyebilirsiniz.",
                "video_url_label": "Video URL'si:",
                "direct_url_help": "Doğrudan video URL'si, gömme URL'si veya iframe kaynağı girin",
                
                // Toplu İzleme
                "batch_video_viewing": "Toplu Video İzleme",
                "batch_desc": "Videoları sırayla işleyin. Her video daha iyi anonimlik için farklı bir proxy ile izlenecek.",
                "batch_urls_label": "Video URL'leri (Her satıra bir tane):",
                "batch_urls_help": "Her URL, mümkünse farklı bir proxy ile ayrı ayrı işlenecek",
                "batch_time_label": "Video Başına İzleme Süresi (saniye):",
                "batch_popup_note": "Not: Yalnızca son videonun açılır penceresi kullanılabilir olacak",
                "start_batch": "Toplu İzlemeyi Başlat",
                "batch_results": "Toplu İzleme Sonuçları",
                "url_number": "URL #",
                "status": "Durum:",
                "success_status": "Başarılı",
                "failed_status": "Başarısız",
                
                // Otomatik Artırma
                "automatic_view_increase": "Otomatik Görüntülenme Artırma",
                "auto_desc": "Görüntülenme sayısını artırmak için bir videoyu belirli aralıklarla otomatik olarak izleyin.",
                "number_of_views": "Görüntülenme Sayısı:",
                "number_of_views_help": "Videoyu kaç kez izleyeceğiniz (1-100)",
                "time_per_view": "Görüntüleme Başına Süre (saniye):",
                "time_per_view_help": "Her seferinde ne kadar süre izleneceği",
                "interval_between_views": "Görüntülemeler Arası Süre (dakika):",
                "interval_between_views_help": "Her görüntüleme arasındaki bekleme süresi (1-60 dakika)",
                "auto_proxy_help": "Mümkün olduğunda her görüntüleme için farklı bir proxy kullanılacak",
                "start_auto_viewing": "Otomatik İzlemeyi Başlat",
                "automatic_view_progress": "Otomatik İzleme İlerlemesi",
                "url_label": "URL:",
                "progress_label": "İlerleme:",
                "views": "görüntüleme",
                "next_view_in": "Sonraki görüntüleme:",
                "minutes": "dakika",
                "used_proxies_so_far": "Şimdiye kadar farklı proxy kullanıldı",
                "cancel_auto_viewing": "Otomatik İzlemeyi İptal Et",
                
                // Proxy Ayarları
                "proxy_list": "Proxy Listesi:",
                "proxy_list_help": "Proxy'leri yukarıda gösterilen formatta girin veya bir proxy liste dosyası yükleyin",
                "upload_proxy_file": "Veya Proxy Liste Dosyası Yükleyin:",
                "loaded_proxies": "Yüklenen proxy'ler: HTTPS, Elite",
                "about_proxy_list": "Proxy Listesi Hakkında:",
                "proxy_list_note1": "Dahil edilen ücretsiz proxy listesi her 10 dakikada bir güncellenir. Elite proxy'ler en yüksek anonimlik seviyesini sunar. Güvenli siteler için HTTPS proxy'ler önerilir.",
                "proxy_list_note2": "En iyi sonuçlar için, çeşitli trafiği simüle etmek için farklı ülkelerden proxy'lerin bir karışımını kullanın.",
                "save_settings": "Ayarları Kaydet",
                
                // Yardım
                "help_information": "Yardım ve Bilgi",
                "supported_platforms": "Desteklenen Platformlar",
                "youtube": "YouTube",
                "youtube_desc": "Normal videolar ve Shorts",
                "vimeo": "Vimeo",
                "vimeo_desc": "Tüm videolar",
                "hdfilm": "HDFilm/Film Siteleri",
                "hdfilm_desc": "Gömülü oynatıcılı film sayfaları",
                "instagram": "Instagram",
                "instagram_desc": "Gönderiler ve Reels",
                "tiktok": "TikTok",
                "tiktok_desc": "Video içerikleri",
                "twitter": "Twitter/X",
                "twitter_desc": "Video içeren tweetler",
                "facebook": "Facebook",
                "facebook_desc": "Video gönderileri ve Reels",
                "dailymotion": "Dailymotion",
                "dailymotion_desc": "Tüm videolar",
                "other_sites": "Diğer siteler",
                "other_sites_desc": "Genel iframe/video algılama",
                
                "viewing_modes": "İzleme Modları",
                "standard_mode_help": "Standart Mod:",
                "standard_mode_desc": "Önce bir video sayfasını tarayın, ardından izleme süresi kontrolü ile izleyin",
                "direct_url_mode_help": "Doğrudan URL Modu:",
                "direct_url_mode_desc": "Videoları tarama adımını atlayarak doğrudan gömme URL'leri ile izleyin",
                "batch_mode_help": "Toplu Mod:",
                "batch_mode_desc": "Birden fazla videoyu sırayla işleyin, her biri için farklı proxy'ler kullanın",
                "auto_increase_mode_help": "Otomatik Artırma Modu:",
                "auto_increase_mode_desc": "Görüntülenme sayısını artırmak için tek bir videoyu belirli aralıklarla tekrar tekrar izleyin",
                
                "ad_free_popup_player": "Reklamsız Açılır Oynatıcı",
                "ad_free_popup_player_desc": "Açılır oynatıcı özelliği, videoları çevreleyen sayfa öğeleri ve reklamlar olmadan izlemenizi sağlar. Şu şekilde çalışır:",
                "popup_feature1": "Orijinal sayfadan video gömme URL'sini çıkarma",
                "popup_feature2": "Temiz, minimal bir oynatıcı penceresi oluşturma",
                "popup_feature3": "Yalnızca video içeriğini doğrudan yükleme",
                "popup_feature4": "Çoğu reklam ve yer paylaşımlı reklamı atlama",
                "popup_feature_note": "Bu özellik özellikle aşırı reklam ve açılır penceresi olan film siteleri için kullanışlıdır.",
                
                "using_proxies_effectively": "Proxy'leri Etkili Kullanma",
                "proxy_rotation_helps": "Proxy rotasyonu, algılanmayı ve hız sınırlamalarını önlemeye yardımcı olur:",
                "proxy_tip1": "YouTube için HTTPS destekleyen Elite proxy'leri kullanın",
                "proxy_tip2": "Genel tarama için anonim proxy'ler yeterlidir",
                "proxy_tip3": "Toplu modda, her video otomatik olarak farklı bir proxy alır",
                "proxy_tip4": "Otomatik Artırma modunda, görüntülemeler arasında proxy'ler değişir",
                
                "finding_video_urls": "Video URL'lerini Bulma",
                "find_url_step1": "Video sayfasını tarayıcınızda açın",
                "find_url_step2": "Sayfa URL'sini kopyalayın ve \"Videoyu Tara\" düğmesiyle tarayın",
                "find_url_step3": "Tarama başarısız olursa, şu yöntemleri deneyin:",
                "find_url_step4": "Sayfaya sağ tıklayın ve \"İncele\" veya \"Öğeyi İncele\" seçeneğini seçin",
                "find_url_step5": "Geliştirici araçlarındaki \"Ağ\" sekmesine gidin",
                "find_url_step6": "Sayfayı yenileyin (F5) ve video oynat düğmesine tıklayın",
                "find_url_step7": "İsim sütununda \"m3u8\", \"mp4\" uzantıları veya \"iframe\" olan istekleri arayın",
                "find_url_step8": "Bu isteklerin URL'sini kopyalayın ve \"Doğrudan URL\" sekmesinde kullanın",
                
                "note": "Not:",
                "disclaimer": "Bu araç yalnızca eğitim amaçlıdır. Görüntülenme sayısını yapay olarak artırmak için otomatik araçlar kullanmak, video platformlarının hizmet şartlarını ihlal edebilir. Bu aracı kullanma sorumluluğu kullanıcıya aittir.",
                
                "process_logs": "İşlem Günlükleri:",
            }
        };

        // Function to set the language
        function setLanguage(lang) {
            // Update active language button
            document.querySelectorAll('.language-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.getElementById('lang-' + lang).classList.add('active');
            
            // Store the selected language in localStorage
            localStorage.setItem('preferredLanguage', lang);
            
            // Update all text elements with language-specific content
            document.querySelectorAll('[data-lang-key]').forEach(element => {
                const key = element.getAttribute('data-lang-key');
                if (languages[lang][key]) {
                    if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                        element.placeholder = languages[lang][key];
                    } else {
                        element.textContent = languages[lang][key];
                    }
                }
            });
            
            // Update document title
            document.title = languages[lang].title || "Enhanced Video Viewer Bot";
        }
        
        // Tab switching function
        function switchTab(tabId) {
            // Hide all tab contents
            var tabContents = document.getElementsByClassName('tab-content');
            for (var i = 0; i < tabContents.length; i++) {
                tabContents[i].classList.remove('active');
            }
            
            // Make all tab buttons inactive
            var tabs = document.getElementsByClassName('tab');
            for (var i = 0; i < tabs.length; i++) {
                tabs[i].classList.remove('active');
            }
            
            // Show selected tab
            document.getElementById(tabId).classList.add('active');
            
            // Make clicked tab button active
            var activeTab = document.querySelector('.tab[onclick="switchTab(\'' + tabId + '\')"]');
            if (activeTab) {
                activeTab.classList.add('active');
            }
            
            // Update URL hash without page jump
            if (history.pushState) {
                history.pushState(null, null, '#' + tabId);
            } else {
                location.hash = '#' + tabId;
            }
        }
        
        // Detect browser language
        function detectBrowserLanguage() {
            const userLang = navigator.language || navigator.userLanguage;
            return userLang.substring(0, 2).toLowerCase() === 'tr' ? 'tr' : 'en';
        }
        
        // Initialize the page
        document.addEventListener('DOMContentLoaded', function() {
            // Set language based on localStorage or browser preference
            const savedLang = localStorage.getItem('preferredLanguage');
            const browserLang = detectBrowserLanguage();
            const initialLang = savedLang || browserLang;
            setLanguage(initialLang);
            
            // Add language switch event listeners
            document.querySelectorAll('.language-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const lang = this.getAttribute('data-lang');
                    setLanguage(lang);
                });
            });
            
            // Load the correct tab based on URL hash when page loads
            var hash = window.location.hash.substring(1);
            if (hash && document.getElementById(hash)) {
                switchTab(hash);
            }
            
            // Handle popup links
            var popupLinks = document.querySelectorAll('.popup-link');
            popupLinks.forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    var url = this.getAttribute('href');
                    window.open(url, 'video_popup', 'width=800,height=600,resizable=yes');
                });
            });
        });
        
        // Update auto view progress
        <?php if (isset($_SESSION['auto_increase'])): ?>
        setInterval(function() {
            var progressFill = document.querySelector('.progress-fill');
            var nextViewElem = document.querySelector('.auto-progress p:nth-of-type(3)');
            var timeRemainingElem = document.querySelector('.time-remaining');
            
            if (progressFill && nextViewElem && timeRemainingElem) {
                var current = <?php echo $_SESSION['auto_increase']['current']; ?>;
                var total = <?php echo $_SESSION['auto_increase']['count']; ?>;
                var nextViewTime = <?php echo $_SESSION['auto_increase']['start_time'] + ($_SESSION['auto_increase']['current'] * $_SESSION['auto_increase']['interval'] * 60); ?>;
                var now = Math.floor(Date.now() / 1000);
                var waitTime = Math.max(0, nextViewTime - now);
                var waitMinutes = Math.floor(waitTime / 60);
                var waitSeconds = waitTime % 60;
                
                progressFill.style.width = (current / total * 100) + '%';
                
                // Get current language
                const currentLang = localStorage.getItem('preferredLanguage') || 'en';
                const minutesText = languages[currentLang]['minutes'] || 'minutes';
                const secondsText = languages[currentLang]['seconds'] || 'seconds';
                
                timeRemainingElem.textContent = waitMinutes + ' ' + minutesText + ' ' + waitSeconds + ' ' + secondsText;
                
                if (waitTime <= 0) {
                    location.reload();
                }
            }
        }, 1000);
        <?php endif; ?>
    </script>
</body>
</html>
