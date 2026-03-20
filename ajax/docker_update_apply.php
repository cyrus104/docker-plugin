<?php
require_once '../../../includes/autoload.php';
require_once '../../../includes/CSRF.php';
require_once '../../../includes/session.php';
require_once '../../../includes/config.php';
require_once '../../../includes/authenticate.php';
require_once '../DockerUpdateService.php';

$tag = $_POST['tag'] ?? null;

if (empty($tag)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'output' => 'Missing parameter: tag']);
    exit;
}

// Read current version and plugin_uri from manifest
$manifestFile = __DIR__ . '/../manifest.json';

if (!file_exists($manifestFile)) {
    echo json_encode(['success' => false, 'output' => 'Manifest file not found.']);
    exit;
}

$manifest = json_decode(file_get_contents($manifestFile), true);

if ($manifest === null || json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'output' => 'Invalid manifest.json.']);
    exit;
}

$currentVersion = $manifest['version'] ?? 'unknown';
$pluginUri = $manifest['plugin_uri'] ?? '';

$service = new \RaspAP\Plugins\Docker\DockerUpdateService($currentVersion, $pluginUri);
$result = $service->applyUpdate($tag);

echo json_encode($result);
