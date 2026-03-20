<?php
require_once '../../../includes/autoload.php';
require_once '../../../includes/CSRF.php';
require_once '../../../includes/session.php';
require_once '../../../includes/config.php';
require_once '../../../includes/authenticate.php';
require_once '../DockerUpdateService.php';

// Read version and plugin_uri from manifest (single source of truth)
$manifestFile = __DIR__ . '/../manifest.json';

if (!file_exists($manifestFile)) {
    echo json_encode(['status' => 'error', 'message' => 'Manifest file not found.']);
    exit;
}

$manifest = json_decode(file_get_contents($manifestFile), true);

if ($manifest === null || json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid manifest.json.']);
    exit;
}

$currentVersion = $manifest['version'] ?? 'unknown';
$pluginUri = $manifest['plugin_uri'] ?? '';

if (empty($pluginUri)) {
    echo json_encode(['status' => 'error', 'message' => 'No plugin_uri in manifest.json.']);
    exit;
}

$forceRefresh = ($_POST['force'] ?? '0') === '1';

$service = new \RaspAP\Plugins\Docker\DockerUpdateService($currentVersion, $pluginUri);
$result = $service->checkForUpdate($forceRefresh);

// Add self-update capability flag so the UI knows whether to show the Apply button
$result['can_self_update'] = $service->canSelfUpdate();

echo json_encode($result);
