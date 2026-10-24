<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/ReadOnlyReportService.php';

api_require_method('GET');
$tenantSlug = $GLOBALS['api_tenant_slug'] ?? null;
if (!is_string($tenantSlug)) {
    api_json(404, ['error' => 'tenant_not_found']);
}
api_enforce_rate_limit(30, 60);

$allowedPeriods = ['7d', '30d', '90d', 'this_month', 'last_month', 'custom'];
$period = isset($_GET['period']) && is_string($_GET['period']) ? $_GET['period'] : '30d';
if (!in_array($period, $allowedPeriods, true)) {
    api_json(400, ['error' => 'invalid_period']);
}

$customRange = null;
$cachePeriod = $period;
$includeMetaAds = isset($_GET['include_meta_ads']) && $_GET['include_meta_ads'] === '1';
if ($period === 'custom') {
    $since = isset($_GET['since']) && is_string($_GET['since']) ? $_GET['since'] : '';
    $until = isset($_GET['until']) && is_string($_GET['until']) ? $_GET['until'] : '';
    if (!ReportPeriod::isValidDateRange($since, $until)) {
        api_json(400, ['error' => 'invalid_date_range']);
    }
    $customRange = ['since' => $since, 'until' => $until];
    $cachePeriod = 'custom-' . substr(hash('sha256', $since . "\0" . $until), 0, 20);
}
if ($includeMetaAds) {
    $cachePeriod .= '-meta-ads';
}

$cacheDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'krcon-report-cache';
$cacheAccountScope = implode("\0", [
    $tenantSlug,
    api_env('META_AD_ACCOUNT_ID') ?? '',
    api_env('GOOGLE_CUSTOMER_ID') ?? '',
    api_env('GOOGLE_LOGIN_CUSTOMER_ID') ?? '',
]);
$cacheScopeHash = substr(hash('sha256', $cacheAccountScope), 0, 20);
$cacheFile = $cacheDirectory . DIRECTORY_SEPARATOR
    . 'report-' . $tenantSlug . '-' . $cacheScopeHash . '-v6-' . $cachePeriod . '.json';
$cacheTtl = 300;
if (is_file($cacheFile) && is_readable($cacheFile)
    && (time() - (int) filemtime($cacheFile)) < $cacheTtl) {
    $cached = json_decode((string) file_get_contents($cacheFile), true);
    if (is_array($cached)) {
        api_json(200, $cached);
    }
}

try {
    $report = (new ReadOnlyReportService())->getReport($period, $customRange, $includeMetaAds);
    $payload = ['data' => $report];
    $hasConnectedPlatform = in_array('connected', $report['integrations'] ?? [], true);
    $hasUnavailablePlatform = in_array('unavailable', $report['integrations'] ?? [], true);
    $metaAdsUnavailable = $includeMetaAds && ($report['meta']['ads_status'] ?? '') === 'unavailable';
    if ($hasConnectedPlatform && !$hasUnavailablePlatform && !$metaAdsUnavailable) {
        if (!is_dir($cacheDirectory)) {
            @mkdir($cacheDirectory, 0700, true);
        }
        if (is_dir($cacheDirectory) && is_writable($cacheDirectory)) {
            @chmod($cacheDirectory, 0700);
            @file_put_contents(
                $cacheFile,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                LOCK_EX
            );
            @chmod($cacheFile, 0600);
        }
    }
    api_json(200, $payload);
} catch (IntegrationNotConfigured $error) {
    api_json(503, ['error' => 'integration_not_configured']);
} catch (Throwable $error) {
    // Do not return upstream messages, request URLs, or credentials to the browser.
    error_log('KRCON report API failed; request_id=' . bin2hex(random_bytes(8)));
    api_json(502, ['error' => 'report_unavailable']);
}
