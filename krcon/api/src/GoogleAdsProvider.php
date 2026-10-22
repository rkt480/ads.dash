<?php
declare(strict_types=1);

require_once __DIR__ . '/IntegrationNotConfigured.php';
require_once __DIR__ . '/ServerHttpClient.php';

/** Read-only adapter boundary for Google Ads. Credentials are read only on the server. */
final class GoogleAdsProvider
{
    public function readReport(array $range): array
    {
        $clientId = api_env('GOOGLE_CLIENT_ID');
        $clientSecret = api_env('GOOGLE_CLIENT_SECRET');
        $refreshToken = api_env('GOOGLE_REFRESH_TOKEN');
        $customerId = api_env('GOOGLE_CUSTOMER_ID');
        $apiVersion = api_env('GOOGLE_ADS_API_VERSION');

        if ($clientId === null || $clientSecret === null || $refreshToken === null
            || $customerId === null || $apiVersion === null) {
            throw new IntegrationNotConfigured('google');
        }

        $customerId = preg_replace('/[^0-9]/', '', $customerId);
        $loginCustomerId = api_env('GOOGLE_LOGIN_CUSTOMER_ID');
        if ($loginCustomerId !== null) {
            $loginCustomerId = preg_replace('/[^0-9]/', '', $loginCustomerId);
        }
        if (!is_string($customerId) || !preg_match('/^[0-9]{10}$/D', $customerId)
            || !preg_match('/^v[0-9]+$/D', $apiVersion)
            || ($loginCustomerId !== null && !preg_match('/^[0-9]{10}$/D', $loginCustomerId))) {
            throw new IntegrationNotConfigured('google');
        }

        $client = new ServerHttpClient();
        $tokenResponse = $client->postForm('https://oauth2.googleapis.com/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ]);
        $accessToken = $tokenResponse['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new UpstreamApiFailure('google_oauth_failed');
        }

        $query = $this->buildQuery($range);
        $url = 'https://googleads.googleapis.com/' . $apiVersion . '/customers/'
            . $customerId . '/googleAds:search';
        $headers = ['Authorization: Bearer ' . $accessToken];
        if ($loginCustomerId !== null) {
            $headers[] = 'login-customer-id: ' . $loginCustomerId;
        }
        $response = $client->postJson($url, $headers, ['query' => $query]);
        $rows = $response['results'] ?? [];
        if (!is_array($rows)) {
            throw new UpstreamApiFailure('google_response_invalid');
        }
        $campaigns = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $campaign = $this->normalizeCampaign($row);
                if ($this->hasReportData($campaign)) {
                    $campaigns[] = $campaign;
                }
            }
        }

        return [
            'totals' => $this->totals($campaigns),
            'campaigns' => $campaigns,
        ];
    }

    private function buildQuery(array $range): string
    {
        $since = (string) ($range['since'] ?? '');
        $until = (string) ($range['until'] ?? '');
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $since)
            || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $until)) {
            throw new UpstreamApiFailure('invalid_report_range');
        }

        return "SELECT campaign.id, campaign.name, campaign.status, "
            . "campaign.advertising_channel_type, metrics.cost_micros, metrics.impressions, "
            . "metrics.clicks, metrics.conversions, metrics.ctr "
            . "FROM campaign WHERE segments.date BETWEEN '" . $since . "' AND '" . $until . "' "
            . "AND campaign.status != 'REMOVED' ORDER BY metrics.cost_micros DESC LIMIT 10000";
    }

    private function normalizeCampaign(array $row): array
    {
        $campaign = $row['campaign'] ?? [];
        $metrics = $row['metrics'] ?? [];
        if (!is_array($campaign) || !is_array($metrics)) {
            return [];
        }
        $spend = $this->number($metrics['costMicros'] ?? 0) / 1000000;
        $conversions = $this->number($metrics['conversions'] ?? 0);
        return [
            'id' => (string) ($campaign['id'] ?? ''),
            'name' => (string) ($campaign['name'] ?? 'Campanha sem nome'),
            'platform' => 'Google Ads',
            'objective' => (string) ($campaign['advertisingChannelType'] ?? ''),
            'status' => (string) ($campaign['status'] ?? ''),
            'spend' => $spend,
            'impressions' => (int) $this->number($metrics['impressions'] ?? 0),
            'clicks' => (int) $this->number($metrics['clicks'] ?? 0),
            'conversions' => $conversions,
            'cost_per_conversion' => $conversions > 0 ? $spend / $conversions : null,
            // Google returns CTR as a ratio (0.05); the report UI displays percentages.
            'ctr' => $this->number($metrics['ctr'] ?? 0) * 100,
        ];
    }

    private function totals(array $campaigns): array
    {
        $totals = ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'conversions' => 0.0];
        foreach ($campaigns as $campaign) {
            if ($campaign === []) {
                continue;
            }
            $totals['spend'] += $campaign['spend'];
            $totals['impressions'] += $campaign['impressions'];
            $totals['clicks'] += $campaign['clicks'];
            $totals['conversions'] += $campaign['conversions'];
        }
        return $totals;
    }

    private function hasReportData(array $campaign): bool
    {
        foreach (['spend', 'impressions', 'clicks', 'conversions', 'ctr'] as $metric) {
            if (($campaign[$metric] ?? 0) != 0) {
                return true;
            }
        }
        return false;
    }

    private function number($value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
