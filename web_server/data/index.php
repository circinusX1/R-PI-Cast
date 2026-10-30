<?php
/**
 * IPTV stream browser
 *
 * Syncs https://github.com/iptv-org/iptv/tree/master/streams into ./streams
 * Looks up genre in ./data CSV files (iptv-org/database data layout)
 * Writes chosen stream URL or STOP to ./play/play.txt (world-writable)
 */

declare(strict_types=1);

$PLAY_FILE = __DIR__ . '/play/play.txt';
$STREAMS_DIR = __DIR__ . '/streams';
$DATA_DIR = __DIR__ . '/data';
$SRC_DIR = __DIR__ . '/.iptv-src';
$DATA_SRC_DIR = __DIR__ . '/.iptv-data-src';

header('X-Content-Type-Options: nosniff');

if (isset($_GET['action']) || isset($_POST['action'])) {
    $action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
    handle_action($action, $PLAY_FILE, $STREAMS_DIR, $DATA_DIR, $SRC_DIR, $DATA_SRC_DIR);
    exit;
}

$initialFiles = list_stream_files($STREAMS_DIR);
$currentPlay = is_readable($PLAY_FILE) ? trim((string) @file_get_contents($PLAY_FILE)) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>IPTV Streams</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --bar-h: 56px;
        }
        body {
            background: #0f1419;
            color: #e7ecf1;
            min-height: 100vh;
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
        .btn-play { white-space: nowrap; flex: 0 0 auto; }
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
        @media (max-width: 576px) {
            .brand { font-size: .95rem; }
            .btn-label { display: none; }
        }
    </style>
</head>
<body>
    <nav class="topbar d-flex align-items-center gap-2 px-2 px-md-3 sticky-top">
        <button id="btn-stop" type="button" class="btn btn-danger flex-shrink-0">
            <i class="bi bi-stop-fill"></i> <span class="btn-label">STOP</span>
        </button>
        <input
            id="play-edit"
            type="text"
            class="form-control"
            placeholder="Edit stream URL or text, then Save"
            value="<?php echo h($currentPlay); ?>"
            autocomplete="off"
            spellcheck="false"
        >
        <button id="btn-save-edit" type="button" class="btn btn-success flex-shrink-0">
            <i class="bi bi-floppy"></i> <span class="btn-label">Save</span>
        </button>
        <div class="brand px-2 d-none d-lg-block flex-shrink-0">IPTV Streams</div>
        <button id="btn-sync" type="button" class="btn btn-primary flex-shrink-0 ms-auto">
            <i class="bi bi-arrow-repeat"></i> <span class="btn-label">Sync</span>
        </button>
    </nav>

    <div class="file-bar px-2 px-md-3 py-2">
        <div class="row g-2 align-items-center">
            <div class="col-12 col-md-5">
                <label class="form-label small mb-1 text-secondary" for="file-select">Playlist file</label>
                <select id="file-select" class="form-select">
                    <option value="">Select a file in ./streams</option>
                    <?php foreach ($initialFiles as $f): ?>
                        <option value="<?php echo h($f); ?>"><?php echo h($f); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-7">
                <label class="form-label small mb-1 text-secondary" for="channel-filter">Filter channels</label>
                <input id="channel-filter" type="search" class="form-control" placeholder="Filter by name, genre, or URL" disabled>
            </div>
        </div>
        <div class="status-strip mt-2" id="status-line">
            <?php if ($currentPlay !== ''): ?>
                Last in <code>play/play.txt</code>: <code><?php echo h($currentPlay); ?></code>
            <?php else: ?>
                Ready. Sync playlists, pick a file, press Play to write the stream URL to <code>play/play.txt</code>.
            <?php endif; ?>
        </div>
    </div>

    <div class="px-2 px-md-3 py-2">
        <div id="alert-box" class="alert d-none mb-2" role="alert"></div>
        <div class="table-responsive">
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

    <div class="modal fade" id="sync-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark text-light">
                <div class="modal-body text-center py-4">
                    <div class="spinner-border text-primary mb-3" role="status"></div>
                    <div>Syncing streams from GitHub… this can take a minute.</div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/app.js"></script>
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

function handle_action(
    string $action,
    string $playFile,
    string $streamsDir,
    string $dataDir,
    string $srcDir,
    string $dataSrcDir
): void {
    switch ($action) {
        case 'files':
            json_out(['ok' => true, 'files' => list_stream_files($streamsDir)]);
            return;
        case 'parse':
            $file = basename((string) ($_GET['file'] ?? $_POST['file'] ?? ''));
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
        case 'save':
            $text = (string) ($_POST['text'] ?? '');
            $text = str_replace(["\0"], '', $text);
            $text = trim($text);
            if ($text === '') {
                json_out(['ok' => false, 'error' => 'Nothing to save'], 400);
                return;
            }
            if (strlen($text) > 8192) {
                json_out(['ok' => false, 'error' => 'Text is too long'], 400);
                return;
            }
            if (!write_shared_play_file($playFile, $text)) {
                json_out(['ok' => false, 'error' => 'Cannot write ' . $playFile], 500);
                return;
            }
            json_out(['ok' => true, 'written' => $text, 'file' => 'play/play.txt']);
            return;
        case 'stop':
            if (!write_shared_play_file($playFile, 'STOP')) {
                json_out(['ok' => false, 'error' => 'Cannot write ' . $playFile], 500);
                return;
            }
            json_out(['ok' => true, 'written' => 'STOP', 'file' => 'play/play.txt']);
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
                'files' => list_stream_files($streamsDir),
                'data' => $dataResult,
            ], $ok ? 200 : 500);
            return;
        case 'status':
            $current = is_readable($playFile) ? trim((string) @file_get_contents($playFile)) : '';
            json_out(['ok' => true, 'play' => $current, 'file' => $playFile]);
            return;
        default:
            json_out(['ok' => false, 'error' => 'Unknown action'], 400);
    }
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

    $git = which_bin('git');
    $log = [];

    if ($git) {
        if (is_dir($srcDir . '/.git')) {
            $log[] = 'git pull in existing clone';
            [$code, $out] = run_cmd('cd ' . escapeshellarg($srcDir) . ' && ' . escapeshellarg($git) . ' pull --ff-only');
            $log[] = $out;
            if ($code !== 0) {
                $log[] = 'pull failed, trying fetch + reset';
                run_cmd('cd ' . escapeshellarg($srcDir) . ' && ' . escapeshellarg($git) . ' fetch --depth 1 origin master');
                run_cmd('cd ' . escapeshellarg($srcDir) . ' && ' . escapeshellarg($git) . ' reset --hard origin/master');
            }
        } else {
            if (is_dir($srcDir)) {
                run_cmd('rm -rf ' . escapeshellarg($srcDir));
            }
            $log[] = 'sparse clone iptv-org/iptv (streams only)';
            $cmd = escapeshellarg($git)
                . ' clone --depth 1 --filter=blob:none --sparse'
                . ' https://github.com/iptv-org/iptv.git '
                . escapeshellarg($srcDir);
            [$code, $out] = run_cmd($cmd);
            $log[] = $out;
            if ($code !== 0 || !is_dir($srcDir . '/.git')) {
                $log[] = 'sparse clone failed, trying shallow clone';
                run_cmd('rm -rf ' . escapeshellarg($srcDir));
                [$code, $out] = run_cmd(
                    escapeshellarg($git) . ' clone --depth 1 https://github.com/iptv-org/iptv.git ' . escapeshellarg($srcDir)
                );
                $log[] = $out;
            } else {
                [$c2, $o2] = run_cmd(
                    'cd ' . escapeshellarg($srcDir) . ' && ' . escapeshellarg($git) . ' sparse-checkout set streams'
                );
                $log[] = $o2;
                if ($c2 !== 0) {
                    $code = $c2;
                }
            }
            if ($code !== 0 && !is_dir($srcDir . '/streams')) {
                return ['ok' => false, 'message' => 'git clone failed', 'log' => implode("\n", $log)];
            }
        }

        $from = $srcDir . '/streams';
        if (!is_dir($from)) {
            return ['ok' => false, 'message' => 'Clone succeeded but streams/ is missing', 'log' => implode("\n", $log)];
        }
        $n = copy_tree($from, $streamsDir);
        return [
            'ok' => $n > 0,
            'message' => 'Synced ' . $n . ' playlist files into ./streams via git',
            'log' => implode("\n", $log),
        ];
    }

    $zipUrl = 'https://codeload.github.com/iptv-org/iptv/zip/refs/heads/master';
    $tmpZip = sys_get_temp_dir() . '/iptv-org-iptv.zip';
    $tmpExtract = sys_get_temp_dir() . '/iptv-org-iptv-extract';
    $log[] = 'git not found, downloading zipball';

    $data = @file_get_contents($zipUrl);
    if ($data === false || $data === '') {
        return ['ok' => false, 'message' => 'Could not download GitHub zip (and git is unavailable)', 'log' => implode("\n", $log)];
    }
    if (@file_put_contents($tmpZip, $data) === false) {
        return ['ok' => false, 'message' => 'Could not save zip to temp', 'log' => implode("\n", $log)];
    }

    $unzip = which_bin('unzip');
    if ($unzip) {
        run_cmd('rm -rf ' . escapeshellarg($tmpExtract));
        mkdir($tmpExtract, 0775, true);
        [$code, $out] = run_cmd(escapeshellarg($unzip) . ' -o ' . escapeshellarg($tmpZip) . ' -d ' . escapeshellarg($tmpExtract));
        $log[] = $out;
        if ($code !== 0) {
            return ['ok' => false, 'message' => 'unzip failed', 'log' => implode("\n", $log)];
        }
        $from = find_streams_dir($tmpExtract);
        if ($from === null) {
            return ['ok' => false, 'message' => 'streams/ not found inside zip', 'log' => implode("\n", $log)];
        }
        $n = copy_tree($from, $streamsDir);
        return [
            'ok' => $n > 0,
            'message' => 'Synced ' . $n . ' playlist files into ./streams via zip',
            'log' => implode("\n", $log),
        ];
    }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            return ['ok' => false, 'message' => 'ZipArchive could not open download', 'log' => implode("\n", $log)];
        }
        $n = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat) {
                continue;
            }
            $name = str_replace('\\', '/', $stat['name']);
            if (!preg_match('#/streams/([^/]+\.(m3u|m3u8))$#i', $name, $m)) {
                continue;
            }
            $contents = $zip->getFromIndex($i);
            if ($contents === false) {
                continue;
            }
            if (@file_put_contents($streamsDir . DIRECTORY_SEPARATOR . $m[1], $contents) !== false) {
                $n++;
            }
        }
        $zip->close();
        return [
            'ok' => $n > 0,
            'message' => 'Synced ' . $n . ' playlist files into ./streams via zip',
            'log' => implode("\n", $log),
        ];
    }

    return ['ok' => false, 'message' => 'No git, unzip, or ZipArchive available to extract streams', 'log' => implode("\n", $log)];
}

function find_streams_dir(string $root): ?string
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $info) {
        if ($info->isDir() && $info->getFilename() === 'streams') {
            return $info->getPathname();
        }
    }
    return null;
}

function sync_data(string $dataDir, string $dataSrcDir): array
{
    if (!ensure_dir($dataDir)) {
        return ['ok' => false, 'message' => 'Cannot create ./data'];
    }

    $files = ['channels.csv', 'categories.csv'];
    $git = which_bin('git');
    $copied = 0;

    if ($git) {
        if (is_dir($dataSrcDir . '/.git')) {
            run_cmd('cd ' . escapeshellarg($dataSrcDir) . ' && ' . escapeshellarg($git) . ' pull --ff-only');
        } else {
            if (is_dir($dataSrcDir)) {
                run_cmd('rm -rf ' . escapeshellarg($dataSrcDir));
            }
            $cmd = escapeshellarg($git)
                . ' clone --depth 1 --filter=blob:none --sparse'
                . ' https://github.com/iptv-org/database.git '
                . escapeshellarg($dataSrcDir);
            [$code] = run_cmd($cmd);
            if ($code === 0) {
                run_cmd('cd ' . escapeshellarg($dataSrcDir) . ' && ' . escapeshellarg($git) . ' sparse-checkout set data');
            }
        }
        $from = $dataSrcDir . '/data';
        if (is_dir($from)) {
            $copied = copy_tree($from, $dataDir);
            return ['ok' => $copied > 0, 'message' => 'Copied ' . $copied . ' CSV files into ./data'];
        }
    }

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
