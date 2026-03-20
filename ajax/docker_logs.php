<?php
require_once '../../../includes/autoload.php';
require_once '../../../includes/CSRF.php';
require_once '../../../includes/session.php';
require_once '../../../includes/config.php';
require_once '../../../includes/authenticate.php';

$containerId = $_POST['container_id'] ?? null;
$tail = isset($_POST['tail']) ? intval($_POST['tail']) : 100;
$since = $_POST['since'] ?? null;

if (empty($containerId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameter: container_id']);
    exit;
}

// Sanitize container ID: allow alphanumeric, dots, hyphens, underscores, slashes (for container names)
$containerId = preg_replace('/[^a-zA-Z0-9_.\-\/]/', '', $containerId);

if (empty($containerId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid container ID.']);
    exit;
}

// Clamp tail between 1 and 5000 to prevent excessive output
$tail = max(1, min($tail, 5000));

// Build the docker logs command
$cmdParts = ['sudo', '/usr/bin/docker', 'logs', '--timestamps'];

if ($since !== null && $since !== '') {
    // Validate since: allow ISO 8601 timestamps and relative durations (e.g., 5m, 1h)
    $cleanSince = preg_replace('/[^0-9T:Z.+\-smhd]/', '', $since);
    if (!empty($cleanSince)) {
        $cmdParts[] = '--since';
        $cmdParts[] = escapeshellarg($cleanSince);
    } else {
        $cmdParts[] = '--tail';
        $cmdParts[] = escapeshellarg((string) $tail);
    }
} else {
    $cmdParts[] = '--tail';
    $cmdParts[] = escapeshellarg((string) $tail);
}

$cmdParts[] = escapeshellarg($containerId);
$cmd = implode(' ', $cmdParts) . ' 2>&1';

// Execute with hard timeout via proc_open (prevents hanging lighttpd/php)
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open($cmd, $descriptors, $pipes);

if (!is_resource($process)) {
    echo json_encode(['error' => 'Failed to execute docker logs command.']);
    exit;
}

fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$stdout = '';
$stderr = '';
$startTime = microtime(true);
$timeout = 10; // 10-second hard timeout

while (true) {
    if ((microtime(true) - $startTime) > $timeout) {
        proc_terminate($process, 9);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        echo json_encode(['error' => "Log retrieval timed out after {$timeout} seconds."]);
        exit;
    }

    $status = proc_get_status($process);
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);

    if (!$status['running']) {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        break;
    }

    usleep(10000); // 10ms between checks
}

fclose($pipes[1]);
fclose($pipes[2]);
proc_close($process);

$output = $stdout . $stderr;

// Limit output size to prevent memory issues (max 1MB)
$maxSize = 1024 * 1024;
if (strlen($output) > $maxSize) {
    $output = "… (output truncated to last 1MB) …\n" . substr($output, -$maxSize);
}

echo json_encode([
    'success' => true,
    'logs' => $output,
    'timestamp' => date('c'),
]);
