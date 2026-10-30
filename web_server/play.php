<?php
/**
 * play.php - Fetches an HTML/v2 page, resolves inner iframes or stream links, and plays them.
 */
declare(strict_types=1);

$url = filter_input(INPUT_GET, 'url', FILTER_VALIDATE_URL);
if (!$url) {
    http_response_code(400);
    echo "Invalid or missing URL.";
    exit;
}

function resolve_stream(string $targetUrl, int $depth = 0): string {
    if ($depth > 3) {
        return $targetUrl; // Prevent excessive recursion
    }

    // Check if URL itself is already a direct media stream
    if (preg_match('/\.(m3u8|mpd|mp4)(\?|$)/i', $targetUrl)) {
        return $targetUrl;
    }

    $parsed = parse_url($targetUrl);
    $referer = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n" .
                        "Referer: {$referer}\r\n"
        ]
    ]);

    $html = @file_get_contents($targetUrl, false, $context);
    if ($html === false || $html === '') {
        return $targetUrl;
    }

    // 1. Search for direct .m3u8 or .mpd stream URLs in HTML or JS code
    if (preg_match('/https?:\/\/[^"\']+\.m3u8(\?[^"\']*)?/i', $html, $matches)) {
        return $matches[0];
    }
    if (preg_match('/https?:\/\/[^"\']+\.mpd(\?[^"\']*)?/i', $html, $matches)) {
        return $matches[0];
    }

    // 2. Search for iframe src embeds (common in channel-v2 layouts)
    if (preg_match('/<iframe[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
        $iframeSrc = html_entity_decode($matches[1]);
        
        // Handle protocol-relative URLs (e.g. //example.com/stream)
        if (strpos($iframeSrc, '//') === 0) {
            $iframeSrc = 'https:' . $iframeSrc;
        } 
        // Handle relative URLs
        elseif (strpos($iframeSrc, 'http') !== 0) {
            $iframeSrc = rtrim($referer, '/') . '/' . ltrim($iframeSrc, '/');
        }

        // Recursively resolve the iframe content
        return resolve_stream($iframeSrc, $depth + 1);
    }

    return $targetUrl;
}

$streamUrl = resolve_stream($url);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Stream Player</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/hls.js@1"></script>
    <style>
        html, body { height: 100%; margin: 0; background: #000; color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; overflow: hidden; }
        video { width: 100%; height: 100%; max-height: 100vh; object-fit: contain; }
        .info-bar { position: absolute; bottom: 10px; font-size: 0.8rem; color: #aaa; background: rgba(0,0,0,0.7); padding: 4px 10px; border-radius: 4px; z-index: 10; max-width: 90%; word-break: break-all; }
    </style>
</head>
<body>
    <?php if (preg_match('/\.(html|php)(\?|$)/i', $streamUrl) && $streamUrl === $url): ?>
        <!-- Fallback to iframe if no direct stream could be scraped -->
        <iframe src="<?php echo htmlspecialchars($streamUrl, ENT_QUOTES); ?>" style="width:100%; height:100%; border:none;" allowfullscreen></iframe>
    <?php else: ?>
        <video id="player" controls autoplay></video>
    <?php endif; ?>

    <div class="info-bar">Resolved Stream: <?php echo htmlspecialchars($streamUrl, ENT_QUOTES); ?></div>

    <script>
        const video = document.getElementById('player');
        const streamUrl = <?php echo json_encode($streamUrl); ?>;

        if (video) {
            if (streamUrl.includes('.m3u8')) {
                if (Hls.isSupported()) {
                    const hls = new Hls();
                    hls.loadSource(streamUrl);
                    hls.attachMedia(video);
                    hls.on(Hls.Events.MANIFEST_PARSED, () => {
                        video.play().catch(err => console.log("Play prevented:", err));
                    });
                } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                    video.src = streamUrl;
                    video.addEventListener('loadedmetadata', () => { video.play(); });
                }
            } else {
                video.src = streamUrl;
                video.play().catch(err => console.log("Play prevented:", err));
            }
        }
    </script>
</body>
</html>

