<?php

namespace RaspAP\Plugins\Docker;

class DockerUpdateService
{
    private string $currentVersion;
    private string $repoOwner;
    private string $repoName;
    private int $httpTimeout = 10;
    private string $cacheFile = '/tmp/docker_plugin_update_cache.json';
    private int $cacheTtl = 3600;
    private string $updateScript = '/etc/raspap/docker/docker_plugin_update.sh';
    private int $updateTimeout = 45;

    public function __construct(string $currentVersion, string $pluginUri)
    {
        $this->currentVersion = ltrim($currentVersion, 'v');

        // Extract owner/repo from GitHub URL (e.g., https://github.com/cyrus104/docker-plugin)
        $parts = parse_url($pluginUri);
        $path = trim($parts['path'] ?? '', '/');
        $segments = explode('/', $path);
        $this->repoOwner = $segments[0] ?? '';
        $this->repoName = $segments[1] ?? '';
    }

    /**
     * Check GitHub Tags API for a newer version.
     * Results are cached for 1 hour unless $forceRefresh is true.
     */
    public function checkForUpdate(bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            $cached = $this->readFreshCache();
            if ($cached !== null) {
                return $cached;
            }
        }

        if (empty($this->repoOwner) || empty($this->repoName)) {
            return [
                'status' => 'error',
                'message' => 'Invalid repository configuration in manifest plugin_uri.',
                'current_version' => $this->currentVersion,
            ];
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/tags?per_page=100',
            urlencode($this->repoOwner),
            urlencode($this->repoName)
        );

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: RaspAP-Docker-Plugin\r\n" .
                            "Accept: application/vnd.github.v3+json\r\n",
                'timeout' => $this->httpTimeout,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return [
                'status' => 'error',
                'message' => 'Unable to reach GitHub. Check your internet connection.',
                'current_version' => $this->currentVersion,
            ];
        }

        $httpCode = $this->parseHttpCode($http_response_header ?? []);

        if ($httpCode === 404) {
            return [
                'status' => 'error',
                'message' => 'Repository not found on GitHub.',
                'current_version' => $this->currentVersion,
            ];
        }

        if ($httpCode === 403) {
            return [
                'status' => 'error',
                'message' => 'GitHub API rate limit reached. Try again later.',
                'current_version' => $this->currentVersion,
            ];
        }

        if ($httpCode !== 200) {
            return [
                'status' => 'error',
                'message' => 'GitHub API returned HTTP ' . $httpCode . '.',
                'current_version' => $this->currentVersion,
            ];
        }

        $tags = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($tags)) {
            return [
                'status' => 'error',
                'message' => 'Invalid response from GitHub API.',
                'current_version' => $this->currentVersion,
            ];
        }

        $latestVersion = $this->findLatestSemver($tags);

        if ($latestVersion === null) {
            return [
                'status' => 'error',
                'message' => 'No versioned tags found in the repository.',
                'current_version' => $this->currentVersion,
            ];
        }

        $updateAvailable = version_compare($latestVersion, $this->currentVersion, '>');

        $result = [
            'status' => 'ok',
            'current_version' => $this->currentVersion,
            'latest_version' => $latestVersion,
            'update_available' => $updateAvailable,
        ];

        $this->writeCache($result);
        return $result;
    }

    /**
     * Apply an update by running the helper script with the given tag.
     * Uses proc_open with a timeout to prevent hanging the web server.
     */
    public function applyUpdate(string $tag): array
    {
        // Validate tag format (same regex as the bash script)
        if (!preg_match('/^v\d+\.\d+\.\d+(-[a-zA-Z0-9.]+)?$/', $tag)) {
            return ['success' => false, 'output' => 'Invalid tag format.'];
        }

        if (!$this->canSelfUpdate()) {
            return ['success' => false, 'output' => 'Self-update not available. Helper script not found or plugin is not a git repository.'];
        }

        $cmd = 'sudo ' . escapeshellarg($this->updateScript) . ' ' . escapeshellarg($tag) . ' 2>&1';

        return $this->execWithTimeout($cmd, $this->updateTimeout);
    }

    /**
     * Check if self-update is possible:
     * 1. Helper script exists and is executable
     * 2. Plugin directory has a .git folder (is a git repo)
     */
    public function canSelfUpdate(): bool
    {
        if (!file_exists($this->updateScript) || !is_executable($this->updateScript)) {
            return false;
        }

        // Check if the installed plugin directory is a git repo.
        // The script has the path hardcoded, so we check the same path.
        return is_dir('/var/www/html/plugins/Docker/.git');
    }

    /**
     * Run a shell command with a hard timeout via proc_open.
     * Returns success flag and combined stdout+stderr output.
     */
    private function execWithTimeout(string $cmd, int $timeout): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['success' => false, 'output' => 'Failed to execute command.'];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = microtime(true);

        while (true) {
            if ((microtime(true) - $startTime) > $timeout) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return ['success' => false, 'output' => "Command timed out after {$timeout} seconds.", 'timed_out' => true];
            }

            $status = proc_get_status($process);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            if (!$status['running']) {
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                break;
            }

            usleep(50000); // 50ms between checks
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'success' => $exitCode === 0,
            'output' => trim($stdout . "\n" . $stderr),
            'exit_code' => $exitCode,
            'timed_out' => false,
        ];
    }

    /**
     * Find the highest semver tag from a GitHub tags API response.
     * Only considers tags matching vX.Y.Z pattern.
     */
    private function findLatestSemver(array $tags): ?string
    {
        $versions = [];
        foreach ($tags as $tag) {
            $name = $tag['name'] ?? '';
            if (preg_match('/^v(\d+\.\d+\.\d+)$/', $name, $m)) {
                $versions[] = $m[1];
            }
        }

        if (empty($versions)) {
            return null;
        }

        usort($versions, 'version_compare');
        return end($versions);
    }

    /**
     * Parse HTTP status code from response headers set by file_get_contents.
     */
    private function parseHttpCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    private function readFreshCache(): ?array
    {
        if (!file_exists($this->cacheFile)) {
            return null;
        }
        if ((time() - filemtime($this->cacheFile)) >= $this->cacheTtl) {
            return null;
        }
        $data = json_decode(file_get_contents($this->cacheFile), true);
        return is_array($data) ? $data : null;
    }

    private function writeCache(array $data): void
    {
        @file_put_contents($this->cacheFile, json_encode($data));
    }
}
