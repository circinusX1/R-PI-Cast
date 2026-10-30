<?php
/**
 * IPTV stream browser
 *
 * Syncs https://github.com/iptv-org/iptv/tree/master/streams into ./streams
 * Looks up genre in ./data CSV files (iptv-org/database data layout)
 * Writes chosen stream URL or STOP to ./play/play.txt (world-writable)
 * SND toggle creates or deletes ./play/snd.txt
 */

declare(strict_types=1);

$PLAY_FILE = __DIR__ . '/play/play.txt';
$SND_FILE = __DIR__ . '/play/snd.txt';
$HDMI_FILE = __DIR__ . '/play/hdmi.txt';
$STREAMS_DIR = __DIR__ . '/streams';
$DATA_DIR = __DIR__ . '/data';
$SRC_DIR = __DIR__ . '/.iptv-src';
$DATA_SRC_DIR = __DIR__ . '/.iptv-data-src';

header('X-Content-Type-Options: nosniff');

if (isset($_GET['action']) || isset($_POST['action'])) {
    $action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
    handle_action($action, $PLAY_FILE, $SND_FILE, $HDMI_FILE, $STREAMS_DIR, $DATA_DIR, $SRC_DIR, $DATA_SRC_DIR);
    exit;
}

$initialFiles = playlist_choices($STREAMS_DIR);
$countries = load_countries_index($DATA_DIR);
$currentPlay = is_readable($PLAY_FILE) ? trim((string) @file_get_contents($PLAY_FILE)) : '';
$sndOn = is_file($SND_FILE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>IPTV Streams</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Hls.js Library for m3u8 streaming -->
    <script src="https://cdn.jsdelivr.net/npm/hls.js@1"></script>
    <!-- Google Cast SDK -->
    <script src="https://www.gstatic.com/cv/js/sender/v1/cast_sender.js?loadCastFramework=1"></script>
    <style>
        :root {
            --bar-h: 56px;
        }
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            overflow: hidden;
            background: #0f1419;
            color: #e7ecf1;
        }

        /* Flex Container for Split Screen */
        .app-container {
            display: flex;
            flex-direction: column;
            height: 100vh;
            width: 100vw;
        }

        /* Top & Bottom Panes */
        .pane-top {
            flex: 1 1 50%;
            min-height: 150px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .pane-bottom {
            flex: 1 1 50%;
            min-height: 150px;
            background: #05070a;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        /* Resizer Bar */
        .resizer {
            height: 8px;
            background: #2a3544;
            cursor: row-resize;
            flex: 0 0 auto;
            position: relative;
            z-index: 10;
            transition: background 0.15s ease;
        }
        .resizer:hover, .resizer.dragging {
            background: #5b8def;
        }
        .resizer::after {
            content: "";
            position: absolute;
            top: 3px;
            left: 50%;
            transform: translateX(-50%);
            width: 32px;
            height: 2px;
            background: #8899a6;
            border-radius: 2px;
        }

        .pane-content {
            flex: 1;
            overflow: hidden;
            display: flex;
            padding: 0 !important;
        }

        /* Playlist Sidebar & List Box */
        .playlist-sidebar {
            flex: 0 0 22%;
            max-width: 30%;
            background: #121821;
            border-right: 1px solid #2a3544;
            overflow: hidden;
            padding: 8px;
            display: flex;
            flex-direction: column;
        }
        #playlist-list {
            flex: 1;
            overflow-y: auto;
            background: #0f1419;
            color: #e7ecf1;
            border-color: #3a4656;
            font-size: 0.85rem;
            width: 100%;
        }
        #playlist-list option {
            padding: 6px 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .table-main-area {
            flex: 1;
            overflow-y: auto;
            padding: 8px 12px;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            min-height: var(--bar-h);
            background: #161d27;
            border-bottom: 1px solid #2a3544;
        }
        .brand {
            font-weight: 600;
            letter-spacing: .04em;
        }
        .file-bar {
            background: #1b2430;
            border-bottom: 1px solid #2a3544;
        }
        .form-select, .form-control {
            background: #0f1419;
            color: #e7ecf1;
            border-color: #3a4656;
        }
        .form-select:focus, .form-control:focus {
            background: #0f1419;
            color: #e7ecf1;
            border-color: #5b8def;
            box-shadow: 0 0 0 .2rem rgba(91, 141, 239, .2);
        }
        .table {
            --bs-table-bg: transparent;
            --bs-table-color: #e7ecf1;
            --bs-table-border-color: #2a3544;
            --bs-table-hover-bg: #1b2430;
            --bs-table-hover-color: #fff;
        }
        .table thead th {
            position: sticky;
            top: 0;
            background: #161d27;
            z-index: 2;
            white-space: nowrap;
        }
        .stream-url {
            color: #9aa8b7;
            word-break: break-all;
            flex: 1 1 auto;
            min-width: 0;
        }
        .btn-play, .btn-hdmi, .btn-see, .btn-chrome, .cast-toggle-btn { white-space: nowrap; flex: 0 0 auto; }

        /* Cast Active Styling */
        .cast-toggle-btn.active, .cast-toggle-btn:has(input:checked) {
            background-color: #0dcaf0 !important;
            border-color: #0dcaf0 !important;
            color: #000 !important;
        }

        /* SND Toggle Styling */
        .snd-toggle {
            position: relative;
            display: inline-flex;
            align-items: center;
            margin: 0;
            cursor: pointer;
            user-select: none;
        }
        .snd-toggle input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .snd-toggle .snd-face {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
        }
        .snd-toggle .snd-off { display: inline; }
        .snd-toggle .snd-on { display: none; }
        .snd-toggle:has(input:checked) {
            color: #212529;
            background-color: #ffc107;
            border-color: #ffc107;
        }
        .snd-toggle:has(input:checked) .snd-off { display: none; }
        .snd-toggle:has(input:checked) .snd-on { display: inline; }
        .snd-toggle:has(input:focus-visible) {
            box-shadow: 0 0 0 .2rem rgba(255, 193, 7, .35);
        }

        tr.playing { background: #1d3a28 !important; }
        .genre-badge {
            font-size: .75rem;
            background: #2a3d55;
            color: #c9dbf0;
        }
        .status-strip {
            font-size: .85rem;
            color: #9aa8b7;
        }
        .status-strip code {
            color: #d7e4f5;
            background: #0f1419;
            padding: .1rem .35rem;
            border-radius: .25rem;
        }

        /* Player Styling */
        #video-player {
            width: 100%;
            flex: 1 1 auto;
            height: 0;
            min-height: 0;
            max-height: 100%;
            object-fit: contain;
            background: #000;
        }
        .player-bar {
            width: 100%;
            background: #161d27;
            border-top: 1px solid #2a3544;
            font-size: .85rem;
            padding: 4px 12px;
            color: #9aa8b7;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .player-cmd {
            width: 100%;
            flex: 0 0 auto;
            border-radius: 0;
            border-left: 0;
            border-right: 0;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: .75rem;
            color: white;
        }

        @media (max-width: 576px) {
            .brand { font-size: .95rem; }
            .btn-label { display: none; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- TOP PANE -->
        <div class="pane-top" id="pane-top">
            <nav class="topbar d-flex align-items-center gap-2 px-2 px-md-3 sticky-top">
                <button id="btn-stop" type="button" class="btn btn-danger flex-shrink-0">
                    <i class="bi bi-stop-fill"></i> <span class="btn-label">STOP</span>
                </button>

                <!-- SND Toggle Button -->
                <label class="snd-toggle btn btn-outline-warning flex-shrink-0" for="chk-snd" title="Create or delete play/snd.txt">
                    <input id="chk-snd" type="checkbox"<?php echo $sndOn ? ' checked' : ''; ?>>
                    <span class="snd-face">
                        <i class="bi bi-volume-mute snd-off"></i>
                        <i class="bi bi-volume-up-fill snd-on"></i>
                        <span class="btn-label">SND</span>
                    </span>
                </label>

                <!-- Chromecast Discovery in Topbar -->
                <div class="d-flex align-items-center gap-1 ms-2" style="max-width: 320px; flex: 1 1 auto;">
                    <button id="btn-find-cast" type="button" class="btn btn-info text-white btn-sm flex-shrink-0" style="width: 25%;">
                        <i class="bi bi-search"></i> Cast
                    </button>
                    <input id="cast-discovery" type="text" class="form-control form-control-sm" placeholder="Checking..." readonly style="font-size: 0.8rem;">
                </div>

                <div class="brand px-2 d-none d-lg-block flex-shrink-0">IPTV Streams</div>

                <!-- Greyed Out Sync Button -->
                <button id="btn-sync" type="button" class="btn btn-secondary flex-shrink-0 ms-auto" disabled>
                    <i class="bi bi-arrow-repeat"></i> <span class="btn-label">Sync</span>
                </button>
            </nav>

            <div class="file-bar px-2 px-md-3 py-2 flex-shrink-0">
                <div class="status-strip text-center" id="status-line">
                    <?php if ($currentPlay !== ''): ?>
                        Last in <code>play/play.txt</code>: <code><?php echo h($currentPlay); ?></code>
                    <?php else: ?>
                        Ready. Select a playlist file from the list box.
                    <?php endif; ?>
                </div>
            </div>

            <div class="pane-content px-2 px-md-3 py-2">
                <!-- Playlist Sidebar with List Box -->
                <div class="playlist-sidebar" id="playlist-sidebar">
                    <div class="d-flex justify-content-between align-items-center mb-1 px-1">
                        <label for="playlist-list" class="text-secondary small fw-bold">Countries / Playlists</label>
                        <button id="btn-reset-countries" type="button" class="btn btn-link btn-sm text-decoration-none p-0 text-secondary" style="font-size: 0.75rem;" title="Reset list">Reset</button>
                    </div>
                    <input id="search_cntry" type="search" class="form-control form-control-sm mb-1" placeholder="Filter countries..." autocomplete="off">
                    <select id="playlist-list" class="form-select" size="15">
                        <?php foreach ($initialFiles as $i => $f): 
                            $cinfo = format_playlist_label($f, $countries);
                        ?>
                            <option value="<?php echo h($f); ?>" data-file="<?php echo h($f); ?>" data-country-name="<?php echo h(strtolower($cinfo['name'])); ?>"<?php echo $i === 0 ? ' selected' : ''; ?>>
                                <?php echo h($cinfo['display']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Main Channels Area -->
                <div class="table-main-area">
                    <div id="alert-box" class="alert d-none mb-2" role="alert"></div>
                    <!-- Channel filter field placed above the table inside main area -->
                    <div class="mb-2">
                        <input id="channel-filter" type="search" class="form-control form-control-sm" placeholder="Filter channel by name, genre, or URL...">
                    </div>
                    <div class="table-responsive" style="flex: 1; overflow-y: auto;">
                        <table class="table table-hover table-sm align-middle mb-0" id="channels-table">
                            <thead>
                                <tr>
                                    <th style="width:28%">Channel</th>
                                    <th style="width:14%">Genre</th>
                                    <th>Stream</th>
                                </tr>
                            </thead>
                            <tbody id="channels-body">
                                <tr><td colspan="3" class="text-secondary py-4 text-center">Choose a playlist file to load channels.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- RESIZER BAR -->
        <div class="resizer" id="resizer"></div>

        <!-- BOTTOM PANE (EMBEDDED PLAYER) -->
        <div class="pane-bottom" id="pane-bottom">
            <input
                id="player-cmd"
                type="text"
                class="form-control player-cmd"
                readonly
                placeholder="Shows command string"
                value="..."
                spellcheck="false"
                autocomplete="off">

            <video id="video-player" controls preload="none"></video>
            <div class="player-bar">
                <span id="player-title"><i class="bi bi-tv me-1"></i> Local Embedded Player</span>
                <span id="player-status" class="badge bg-secondary">Idle</span>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="app.js"></script>
</body>
</html>
<?php

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function write_shared_play_file(string $playFile, string $contents): bool
{
    $dir = dirname($playFile);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0777, true)) {
            return false;
        }
    }
    @chmod($dir, 0777);
    if (@file_put_contents($playFile, $contents) === false) {
        return false;
    }
    @chmod($playFile, 0666);
    return true;
}

function json_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function load_countries_index(string $dataDir): array
{
    $countries = [];
    $csvPaths = [
        $dataDir . '/countries.csv',
        $dataDir . '/data/countries.csv',
    ];
    $used = '';
    foreach ($csvPaths as $csv) {
        if (is_file($csv) && is_readable($csv)) {
            $used = $csv;
            break;
        }
    }
    if ($used === '') {
        return $countries;
    }

    $fh = fopen($used, 'r');
    if ($fh === false) {
        return $countries;
    }
    $header = fgetcsv($fh);
    if ($header === false) {
        fclose($fh);
        return $countries;
    }
    $map = [];
    foreach ($header as $i => $col) {
        $map[strtolower(trim((string) $col))] = $i;
    }
    $nameIdx = $map['name'] ?? 0;
    $codeIdx = $map['code'] ?? 1;
    $flagIdx = $map['flag'] ?? 3;

    while (($row = fgetcsv($fh)) !== false) {
        $name = isset($row[$nameIdx]) ? trim((string) $row[$nameIdx]) : '';
        $code = isset($row[$codeIdx]) ? strtolower(trim((string) $row[$codeIdx])) : '';
        $flag = isset($row[$flagIdx]) ? trim((string) $row[$flagIdx]) : '';
        if ($code !== '') {
            $countries[$code] = [
                'name' => $name !== '' ? $name : strtoupper($code),
                'flag' => $flag !== '' ? $flag : '🌐',
            ];
        }
    }
    fclose($fh);
    return $countries;
}

function format_playlist_label(string $filename, array $countries): array
{
    if ($filename === 'cool_tv') {
        return ['flag' => '📺', 'name' => 'Cool TV Channels', 'display' => '📺 Cool TV Channels'];
    }
    $code = strtolower(pathinfo($filename, PATHINFO_FILENAME));
    if (isset($countries[$code])) {
        $c = $countries[$code];
        return ['flag' => $c['flag'], 'name' => $c['name'], 'display' => $c['flag'] . ' ' . $c['name']];
    }
    return ['flag' => '🌐', 'name' => strtoupper($code), 'display' => '🌐 ' . strtoupper($code)];
}

function handle_action(
    string $action,
    string $playFile,
    string $sndFile,
    string $hdmiFile,
    string $streamsDir,
    string $dataDir,
    string $srcDir,
    string $dataSrcDir
): void {
    switch ($action) {
        case 'files':
            $files = playlist_choices($streamsDir);
            $countries = load_countries_index($dataDir);
            $out = [];
            foreach ($files as $f) {
                $info = format_playlist_label($f, $countries);
                $out[] = ['file' => $f, 'display' => $info['display'], 'name' => strtolower($info['name'])];
            }
            json_out(['ok' => true, 'files' => $out]);
            return;
        case 'parse':
            $file = basename((string) ($_GET['file'] ?? $_POST['file'] ?? ''));
            if ($file === 'cool_tv') {
                $csv = cool_tv_csv_path($dataDir);
                if ($csv === null) {
                    json_out(['ok' => false, 'error' => 'cool-tv-channels.csv not found in ./data'], 404);
                    return;
                }
                $channels = parse_cool_tv_csv($csv);
                json_out([
                    'ok' => true,
                    'file' => 'cool_tv',
                    'count' => count($channels),
                    'channels' => $channels,
                    'genres_loaded' => 0,
                ]);
                return;
            }
            if ($file === '' || !preg_match('/^[A-Za-z0-9._-]+\.(m3u|m3u8)$/i', $file)) {
                json_out(['ok' => false, 'error' => 'Invalid file name'], 400);
                return;
            }
            $path = $streamsDir . DIRECTORY_SEPARATOR . $file;
            if (!is_file($path) || !is_readable($path)) {
                json_out(['ok' => false, 'error' => 'File not found in ./streams'], 404);
                return;
            }
            $genres = load_genre_index($dataDir);
            $channels = parse_m3u($path, $genres);
            json_out([
                'ok' => true,
                'file' => $file,
                'count' => count($channels),
                'channels' => $channels,
                'genres_loaded' => $genres['count'],
            ]);
            return;
        case 'play':
            $url = trim((string) ($_POST['url'] ?? ''));
            if (!preg_match('#^https?://#i', $url)) {
                json_out(['ok' => false, 'error' => 'URL must start with http:// or https://'], 400);
                return;
            }
            if (!write_shared_play_file($playFile, $url)) {
                json_out(['ok' => false, 'error' => 'Cannot write ' . $playFile], 500);
                return;
            }
            json_out(['ok' => true, 'written' => $url, 'file' => 'play/play.txt']);
            return;
        case 'hdmi':
            $url = trim((string) ($_POST['url'] ?? ''));
            if (!preg_match('#^https?://#i', $url)) {
                json_out(['ok' => false, 'error' => 'URL must start with http:// or https://'], 400);
                return;
            }
            if (!write_shared_play_file($playFile, $url)) {
                json_out(['ok' => false, 'error' => 'Cannot write ' . $playFile], 500);
                return;
            }
            write_shared_play_file($hdmiFile, $url);
            json_out([
                'ok' => true,
                'hdmi' => $url,
                'snd' => is_file($sndFile),
                'player' => 'mpv',
            ]);
            return;
        case 'stop':
            if (!write_shared_play_file($playFile, 'STOP')) {
                json_out(['ok' => false, 'error' => 'Cannot write ' . $playFile], 500);
                return;
            }
            if (is_file($hdmiFile)) @unlink($hdmiFile);
            json_out(['ok' => true, 'written' => 'STOP', 'file' => 'play/play.txt']);
            return;
        case 'snd':
            $on = isset($_POST['on']) ? (string) $_POST['on'] : (string) ($_GET['on'] ?? '');
            $on = in_array(strtolower($on), ['1', 'true', 'on', 'yes'], true);
            if ($on) {
                if (!write_shared_play_file($sndFile, "1\n")) {
                    json_out(['ok' => false, 'error' => 'Cannot write ' . $sndFile], 500);
                    return;
                }
                json_out(['ok' => true, 'on' => true, 'file' => 'play/snd.txt']);
                return;
            }
            if (is_file($sndFile) && !@unlink($sndFile)) {
                json_out(['ok' => false, 'error' => 'Cannot delete ' . $sndFile], 500);
                return;
            }
            json_out(['ok' => true, 'on' => false, 'file' => 'play/snd.txt']);
            return;
        case 'sync':
            @set_time_limit(300);
            $result = sync_streams($streamsDir, $srcDir);
            $dataResult = sync_data($dataDir, $dataSrcDir);
            $ok = !empty($result['ok']);
            json_out([
                'ok' => $ok,
                'message' => $result['message'],
                'log' => $result['log'] ?? '',
                'files' => playlist_choices($streamsDir),
                'data' => $dataResult,
            ], $ok ? 200 : 500);
            return;
        case 'status':
            $current = is_readable($playFile) ? trim((string) @file_get_contents($playFile)) : '';
            json_out([
                'ok' => true,
                'play' => $current,
                'file' => $playFile,
                'snd' => is_file($sndFile),
            ]);
            return;
        default:
            json_out(['ok' => false, 'error' => 'Unknown action'], 400);
    }
}

function playlist_choices(string $streamsDir): array
{
    $files = list_stream_files($streamsDir);
    $files[] = 'cool_tv';
    return $files;
}

function cool_tv_csv_path(string $dataDir): ?string
{
    $candidates = [
        $dataDir . '/cool-tv-channels.csv',
        $dataDir . '/cool_tv_channels.csv',
        $dataDir . '/data/cool-tv-channels.csv',
        $dataDir . '/data/cool_tv_channels.csv',
    ];
    foreach ($candidates as $path) {
        if (is_file($path) && is_readable($path)) {
            return $path;
        }
    }
    return null;
}

function parse_cool_tv_csv(string $path): array
{
    $fh = fopen($path, 'r');
    if ($fh === false) {
        return [];
    }

    $header = fgetcsv($fh);
    if ($header === false) {
        fclose($fh);
        return [];
    }

    $map = [];
    foreach ($header as $i => $col) {
        $map[strtolower(trim((string) $col))] = $i;
    }

    $looksLikeHeader = isset($map['name']) || isset($map['categories']) || isset($map['page']);
    if (!$looksLikeHeader) {
        rewind($fh);
        $map = [];
    }

    $nameIdx = $map['name'] ?? 0;
    $genreIdx = $map['categories'] ?? 1;
    $streamIdxs = [
        $map['page'] ?? 2,
        $map['stream_embed_1'] ?? 3,
        $map['stream_embed_2'] ?? 4,
        $map['stream_embed_3'] ?? 5,
    ];
    $streamIdxs = array_values(array_unique($streamIdxs));

    $out = [];
    while (($row = fgetcsv($fh)) !== false) {
        if ($row === [null] || $row === false) {
            continue;
        }
        $name = isset($row[$nameIdx]) ? trim((string) $row[$nameIdx]) : '';
        if ($name === '') {
            continue;
        }
        $genre = isset($row[$genreIdx]) ? trim((string) $row[$genreIdx]) : '';

        $links = [];
        foreach ($streamIdxs as $idx) {
            $url = isset($row[$idx]) ? trim((string) $row[$idx]) : '';
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            $links[] = $url;
        }

        foreach ($links as $url) {
            $out[] = [
                'name' => $name,
                'tvg_id' => '',
                'channel_id' => '',
                'group' => $genre,
                'url' => $url,
                'genre' => $genre,
            ];
        }
    }
    fclose($fh);

    usort($out, static function (array $a, array $b): int {
        $ga = strtolower(trim((string) ($a['genre'] ?? '')));
        $gb = strtolower(trim((string) ($b['genre'] ?? '')));
        if ($ga === '' && $gb !== '') {
            return 1;
        }
        if ($gb === '' && $ga !== '') {
            return -1;
        }
        $g = strcasecmp($ga, $gb);
        if ($g !== 0) {
            return $g;
        }
        $n = strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        if ($n !== 0) {
            return $n;
        }
        return strcasecmp((string) ($a['url'] ?? ''), (string) ($b['url'] ?? ''));
    });

    return $out;
}

function list_stream_files(string $dir): array
{
    $files = [];
    if (!is_dir($dir) || !is_readable($dir)) {
        return $files;
    }
    $dh = opendir($dir);
    if ($dh === false) {
        return $files;
    }
    while (($name = readdir($dh)) !== false) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'm3u' && $ext !== 'm3u8') {
            continue;
        }
        if (is_file($dir . DIRECTORY_SEPARATOR . $name)) {
            $files[] = $name;
        }
    }
    closedir($dh);
    natcasesort($files);
    return array_values($files);
}

function parse_m3u(string $path, array $genres): array
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return [];
    }
    $out = [];
    $pending = null;
    foreach ($lines as $raw) {
        $line = trim($raw);
        if ($line === '') {
            continue;
        }
        if (strncasecmp($line, '#EXTINF:', 8) === 0) {
            $pending = parse_extinf($line);
            continue;
        }
        if ($line[0] === '#') {
            continue;
        }
        if ($pending !== null && preg_match('#^https?://#i', $line)) {
            $pending['url'] = $line;
            $pending['genre'] = lookup_genre($pending, $genres);
            $out[] = $pending;
            $pending = null;
        }
    }
    usort($out, static function (array $a, array $b): int {
        $ga = strtolower(trim((string) ($a['genre'] ?? '')));
        $gb = strtolower(trim((string) ($b['genre'] ?? '')));
        if ($ga === '' && $gb !== '') {
            return 1;
        }
        if ($gb === '' && $ga !== '') {
            return -1;
        }
        $g = strcasecmp($ga, $gb);
        if ($g !== 0) {
            return $g;
        }
        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });
    return $out;
}

function parse_extinf(string $line): array
{
    $tvgId = '';
    if (preg_match('/tvg-id="([^"]*)"/i', $line, $m)) {
        $tvgId = $m[1];
    }
    $group = '';
    if (preg_match('/group-title="([^"]*)"/i', $line, $m)) {
        $group = $m[1];
    }
    $name = $line;
    $comma = strrpos($line, ',');
    if ($comma !== false) {
        $name = trim(substr($line, $comma + 1));
    }
    $channelId = $tvgId;
    $at = strpos($channelId, '@');
    if ($at !== false) {
        $channelId = substr($channelId, 0, $at);
    }
    return [
        'name' => $name !== '' ? $name : $tvgId,
        'tvg_id' => $tvgId,
        'channel_id' => $channelId,
        'group' => $group,
        'url' => '',
        'genre' => '',
    ];
}

function load_genre_index(string $dataDir): array
{
    $byId = [];
    $byName = [];
    $csvPaths = [
        $dataDir . '/channels.csv',
        $dataDir . '/data/channels.csv',
    ];
    $used = '';
    foreach ($csvPaths as $csv) {
        if (is_file($csv) && is_readable($csv)) {
            $used = $csv;
            break;
        }
    }
    if ($used === '') {
        return ['by_id' => $byId, 'by_name' => $byName, 'count' => 0];
    }

    $fh = fopen($used, 'r');
    if ($fh === false) {
        return ['by_id' => $byId, 'by_name' => $byName, 'count' => 0];
    }
    $header = fgetcsv($fh);
    if ($header === false) {
        fclose($fh);
        return ['by_id' => $byId, 'by_name' => $byName, 'count' => 0];
    }
    $map = [];
    foreach ($header as $i => $col) {
        $map[strtolower(trim((string) $col))] = $i;
    }
    $idIdx = $map['id'] ?? 0;
    $nameIdx = $map['name'] ?? 1;
    $catIdx = $map['categories'] ?? null;
    if ($catIdx === null) {
        foreach ($map as $col => $i) {
            if (strpos($col, 'categor') !== false || $col === 'genre' || $col === 'genres') {
                $catIdx = $i;
                break;
            }
        }
    }
    while (($row = fgetcsv($fh)) !== false) {
        if (!isset($row[$idIdx])) {
            continue;
        }
        $id = trim((string) $row[$idIdx]);
        $name = isset($row[$nameIdx]) ? trim((string) $row[$nameIdx]) : '';
        $cat = ($catIdx !== null && isset($row[$catIdx])) ? trim((string) $row[$catIdx]) : '';
        if ($id !== '' && $cat !== '') {
            $byId[strtolower($id)] = $cat;
        }
        if ($name !== '' && $cat !== '') {
            $byName[strtolower($name)] = $cat;
        }
    }
    fclose($fh);
    return ['by_id' => $byId, 'by_name' => $byName, 'count' => count($byId)];
}

function lookup_genre(array $channel, array $genres): string
{
    $id = strtolower((string) ($channel['channel_id'] ?? ''));
    if ($id !== '' && isset($genres['by_id'][$id])) {
        return $genres['by_id'][$id];
    }
    $name = strtolower(preg_replace('/\s*\([^)]*\)\s*/', '', (string) ($channel['name'] ?? '')) ?? '');
    $name = trim(preg_replace('/\s*\[.*?\]\s*/', '', $name) ?? '');
    if ($name !== '' && isset($genres['by_name'][$name])) {
        return $genres['by_name'][$name];
    }
    if (!empty($channel['group'])) {
        return (string) $channel['group'];
    }
    return '';
}

function which_bin(string $name): ?string
{
    $path = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
    return $path !== '' ? $path : null;
}

function run_cmd(string $cmd): array
{
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

function ensure_dir(string $dir): bool
{
    if (is_dir($dir)) {
        return is_writable($dir);
    }
    return @mkdir($dir, 0775, true);
}

function copy_tree(string $from, string $to): int
{
    if (!is_dir($from)) {
        return 0;
    }
    ensure_dir($to);
    $n = 0;
    $dh = opendir($from);
    if ($dh === false) {
        return 0;
    }
    while (($name = readdir($dh)) !== false) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $src = $from . DIRECTORY_SEPARATOR . $name;
        $dst = $to . DIRECTORY_SEPARATOR . $name;
        if (is_file($src)) {
            if (@copy($src, $dst)) {
                $n++;
            }
        }
    }
    closedir($dh);
    return $n;
}

function sync_streams(string $streamsDir, string $srcDir): array
{
    if (!ensure_dir($streamsDir)) {
        return ['ok' => false, 'message' => 'Cannot create or write ./streams'];
    }
    return ['ok' => true, 'message' => 'Synced streams'];
}

function sync_data(string $dataDir, string $dataSrcDir): array
{
    if (!ensure_dir($dataDir)) {
        return ['ok' => false, 'message' => 'Cannot create ./data'];
    }

    $files = ['channels.csv', 'categories.csv', 'countries.csv'];
    $copied = 0;

    foreach ($files as $name) {
        $url = 'https://raw.githubusercontent.com/iptv-org/database/master/data/' . $name;
        $body = @file_get_contents($url);
        if ($body !== false && $body !== '') {
            if (@file_put_contents($dataDir . DIRECTORY_SEPARATOR . $name, $body) !== false) {
                $copied++;
            }
        }
    }
    return [
        'ok' => $copied > 0,
        'message' => $copied > 0 ? 'Downloaded ' . $copied . ' CSV files into ./data' : 'Could not refresh ./data CSVs',
    ];
}

