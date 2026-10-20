<?php
declare(strict_types=1);

ini_set('display_errors', '0');

// The tenant is the path segment immediately before /api/. Never take an
// account/customer ID from a request parameter; the private tenant map is the
// only source of account IDs and credentials.
$apiScriptParts = array_values(array_filter(
    explode('/', trim((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/')),
    static fn(string $part): bool => $part !== ''
));
$apiIndex = array_search('api', $apiScriptParts, true);
$apiTenantSlug = is_int($apiIndex) && $apiIndex > 0 ? $apiScriptParts[$apiIndex - 1] : null;
if (!is_string($apiTenantSlug) || !preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $apiTenantSlug)) {
    $apiTenantSlug = null;
}
$GLOBALS['api_tenant_slug'] = $apiTenantSlug;

// Shared hosting often has no environment-variable editor. Prefer an explicit
// private file path; otherwise use a location outside the whole public_html
// tree on Hostinger, or outside DOCUMENT_ROOT on local Apache/XAMPP.
$apiDocumentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), DIRECTORY_SEPARATOR);
$apiPrivateConfig = [];
$apiPrivateConfigCandidates = [];
$apiExplicitConfigPath = getenv('ADS_PRIVATE_CONFIG_FILE');
if (is_string($apiExplicitConfigPath) && trim($apiExplicitConfigPath) !== '') {
    $apiPrivateConfigCandidates[] = trim($apiExplicitConfigPath);
}
if ($apiDocumentRoot !== '') {
    if (preg_match('~/public_html(?:/|$)~', $apiDocumentRoot) === 1) {
        $apiPrivateConfigCandidates[] = dirname(dirname($apiDocumentRoot)) . DIRECTORY_SEPARATOR . 'ads-dashboard-secrets.php';
    } else {
        $apiPrivateConfigCandidates[] = dirname($apiDocumentRoot) . DIRECTORY_SEPARATOR . 'ads-dashboard-secrets.php';
    }
}

$apiDocumentRootReal = $apiDocumentRoot !== '' ? realpath($apiDocumentRoot) : false;
$apiPublicHtmlReal = false;
if ($apiDocumentRootReal !== false) {
    $apiPublicHtmlMarker = strpos($apiDocumentRootReal, DIRECTORY_SEPARATOR . 'public_html');
    if ($apiPublicHtmlMarker !== false) {
        $apiPublicHtmlPath = substr(
            $apiDocumentRootReal,
            0,
            $apiPublicHtmlMarker + strlen(DIRECTORY_SEPARATOR . 'public_html')
        );
        $apiPublicHtmlReal = realpath($apiPublicHtmlPath);
    }
}
foreach (array_unique($apiPrivateConfigCandidates) as $apiPrivateConfigPath) {
    if (!is_file($apiPrivateConfigPath) || !is_readable($apiPrivateConfigPath)) {
        continue;
    }
    $apiPrivateConfigReal = realpath($apiPrivateConfigPath);
    $apiConfigIsPublic = false;
    foreach ([$apiDocumentRootReal, $apiPublicHtmlReal] as $apiForbiddenRoot) {
        if (!is_string($apiForbiddenRoot)) {
            continue;
        }
        $apiForbiddenPrefix = rtrim($apiForbiddenRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ([$apiPrivateConfigPath, $apiPrivateConfigReal] as $apiConfigCandidate) {
            if (is_string($apiConfigCandidate)
                && ($apiConfigCandidate === $apiForbiddenRoot || strpos($apiConfigCandidate, $apiForbiddenPrefix) === 0)) {
                $apiConfigIsPublic = true;
                break 2;
            }
        }
    }
    if ($apiConfigIsPublic) {
        error_log('Ads dashboard private configuration is inside DOCUMENT_ROOT; refusing to load it');
        continue;
    }
    $loadedConfig = require $apiPrivateConfigPath;
    if (is_array($loadedConfig)) {
        $apiPrivateConfig = $loadedConfig;
        break;
    }
}
$GLOBALS['api_tenants_config'] = is_array($apiPrivateConfig['tenants'] ?? null)
    ? $apiPrivateConfig['tenants'] : [];
$GLOBALS['api_legacy_config'] = $apiPrivateConfig;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

function api_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method);
        api_json(405, ['error' => 'method_not_allowed']);
    }
}

function api_enforce_rate_limit(int $maxRequests = 30, int $windowSeconds = 60): void
{
    // Use the address supplied by the web server. Do not trust arbitrary
    // X-Forwarded-For values; configure a trusted proxy before using one.
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($clientIp) || filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
        error_log('KRCON API rate limit unavailable: client address missing');
        api_json(503, ['error' => 'temporarily_unavailable']);
    }

    $tenantSlug = $GLOBALS['api_tenant_slug'] ?? 'unknown';
    $rateLimitDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'ads-api-rate-limit-' . substr(hash('sha256', __DIR__ . '-' . $tenantSlug), 0, 16);
    if (!is_dir($rateLimitDirectory)
        && !@mkdir($rateLimitDirectory, 0700, true)
        && !is_dir($rateLimitDirectory)) {
        error_log('KRCON API rate limit unavailable: storage directory cannot be created');
        api_json(503, ['error' => 'temporarily_unavailable']);
    }
    @chmod($rateLimitDirectory, 0700);
    $directoryMode = @fileperms($rateLimitDirectory);
    if (!is_writable($rateLimitDirectory) || $directoryMode === false || ($directoryMode & 0077) !== 0) {
        error_log('KRCON API rate limit unavailable: storage directory is not private');
        api_json(503, ['error' => 'temporarily_unavailable']);
    }

    // Hash the client address so temporary counter filenames do not contain IPs.
    $counterPath = $rateLimitDirectory . DIRECTORY_SEPARATOR
        . 'client-' . hash('sha256', $clientIp) . '.json';
    $counter = @fopen($counterPath, 'c+');
    if ($counter === false) {
        error_log('KRCON API rate limit unavailable: counter cannot be opened');
        api_json(503, ['error' => 'temporarily_unavailable']);
    }
    @chmod($counterPath, 0600);
    if (!flock($counter, LOCK_EX)) {
        fclose($counter);
        error_log('KRCON API rate limit unavailable: counter cannot be locked');
        api_json(503, ['error' => 'temporarily_unavailable']);
    }

    $rawCounter = stream_get_contents($counter);
    $timestamps = $rawCounter === '' ? [] : json_decode($rawCounter, true);
    if (!is_array($timestamps)) {
        flock($counter, LOCK_UN);
        fclose($counter);
        error_log('KRCON API rate limit unavailable: counter is invalid');
        api_json(503, ['error' => 'temporarily_unavailable']);
    }

    $now = time();
    $cutoff = $now - $windowSeconds;
    $timestamps = array_values(array_filter(
        $timestamps,
        static fn($timestamp): bool => is_int($timestamp) && $timestamp > $cutoff
    ));
    if (count($timestamps) >= $maxRequests) {
        $retryAfter = max(1, $timestamps[0] + $windowSeconds - $now);
        flock($counter, LOCK_UN);
        fclose($counter);
        header('Retry-After: ' . $retryAfter);
        api_json(429, ['error' => 'rate_limit_exceeded', 'retry_after' => $retryAfter]);
    }

    $timestamps[] = $now;
    rewind($counter);
    $saved = ftruncate($counter, 0)
        && fwrite($counter, json_encode($timestamps, JSON_UNESCAPED_SLASHES)) !== false
        && fflush($counter);
    flock($counter, LOCK_UN);
    fclose($counter);
    if (!$saved) {
        error_log('KRCON API rate limit unavailable: counter cannot be saved');
        api_json(503, ['error' => 'temporarily_unavailable']);
    }

    // Periodically remove counters for addresses that have been inactive for a day.
    if (random_int(1, 100) === 1) {
        foreach (glob($rateLimitDirectory . DIRECTORY_SEPARATOR . 'client-*.json') ?: [] as $path) {
            $modifiedAt = @filemtime($path);
            if ($modifiedAt !== false && $modifiedAt < $now - 86400) {
                @unlink($path);
            }
        }
    }
}

function api_env(string $name): ?string
{
    $tenantSlug = $GLOBALS['api_tenant_slug'] ?? null;
    if (!is_string($tenantSlug)) {
        return null;
    }

    $tenantEnvironmentKey = 'ADS_' . strtoupper(str_replace('-', '_', $tenantSlug)) . '_' . $name;
    $value = getenv($tenantEnvironmentKey);
    if ($value !== false && trim($value) !== '') {
        return trim($value);
    }

    $tenants = $GLOBALS['api_tenants_config'] ?? [];
    $tenantConfig = is_array($tenants) ? ($tenants[$tenantSlug] ?? []) : [];
    $privateValue = is_array($tenantConfig) ? ($tenantConfig[$name] ?? null) : null;
    if (!is_string($privateValue) || trim($privateValue) === '') {
        // Backward compatibility for the first KRCON deployment only. New
        // clients must use their own key under tenants[slug] or ADS_SLUG_KEY.
        if ($tenantSlug !== 'krcon') {
            return null;
        }
        $legacyEnvironmentValue = getenv($name);
        if ($legacyEnvironmentValue !== false && trim($legacyEnvironmentValue) !== '') {
            return trim($legacyEnvironmentValue);
        }
        $legacyConfig = $GLOBALS['api_legacy_config'] ?? [];
        $privateValue = is_array($legacyConfig) ? ($legacyConfig[$name] ?? null) : null;
        if (!is_string($privateValue) || trim($privateValue) === '') {
            return null;
        }
    }

    return trim($privateValue);
}
