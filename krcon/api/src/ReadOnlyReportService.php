<?php
declare(strict_types=1);

require_once __DIR__ . '/MetaAdsProvider.php';
require_once __DIR__ . '/GoogleAdsProvider.php';
require_once __DIR__ . '/ReportPeriod.php';

final class ReadOnlyReportService
{
    public function getReport(string $period, ?array $customRange = null, bool $includeMetaAds = false): array
    {
        $range = ReportPeriod::range($period, $customRange);
        $metaResult = $this->readPlatform(
            static function () use ($range, $includeMetaAds): array {
                return (new MetaAdsProvider())->readInsights($range, $includeMetaAds);
            }
        );
        $googleResult = $this->readPlatform(
            static function () use ($range): array {
                return (new GoogleAdsProvider())->readReport($range);
            }
        );

        return [
            'period' => $period,
            'range' => $range,
            'meta' => $metaResult['data'],
            'google' => $googleResult['data'],
            'integrations' => [
                'meta' => $metaResult['status'],
                'google' => $googleResult['status'],
            ],
            'generated_at' => gmdate('c'),
        ];
    }

    private function readPlatform(callable $read): array
    {
        try {
            return ['status' => 'connected', 'data' => $read()];
        } catch (IntegrationNotConfigured $error) {
            return [
                'status' => 'not_configured',
                'data' => ['totals' => [], 'campaigns' => []],
            ];
        } catch (Throwable $error) {
            return [
                'status' => 'unavailable',
                'data' => ['totals' => [], 'campaigns' => []],
            ];
        }
    }
}
