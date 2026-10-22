<?php
declare(strict_types=1);

require_once __DIR__ . '/IntegrationNotConfigured.php';
require_once __DIR__ . '/ServerHttpClient.php';

/** Read-only adapter boundary for Meta Ads. Credentials are read only on the server. */
final class MetaAdsProvider
{
    public function readInsights(array $range): array
    {
        $token = api_env('META_ACCESS_TOKEN');
        $accountId = api_env('META_AD_ACCOUNT_ID');
        $apiVersion = api_env('META_API_VERSION');

        if ($token === null || $accountId === null || $apiVersion === null) {
            throw new IntegrationNotConfigured('meta');
        }

        $accountId = preg_replace('/^act_/', '', $accountId);
        if (!is_string($accountId) || !preg_match('/^[0-9]+$/D', $accountId)
            || !preg_match('/^v[0-9]+\.[0-9]+$/D', $apiVersion)) {
            throw new IntegrationNotConfigured('meta');
        }

        $client = new ServerHttpClient();
        $baseUrl = 'https://graph.facebook.com/' . $apiVersion . '/act_' . $accountId . '/insights';
        $params = [
            'level' => 'campaign',
            'time_range' => json_encode($range, JSON_UNESCAPED_SLASHES),
            'fields' => 'campaign_id,campaign_name,spend,impressions,clicks,actions,results,cost_per_action_type,ctr,date_start,date_stop',
            'limit' => '500',
        ];
        $campaigns = [];
        $after = null;

        // A bounded cursor loop prevents a malformed response from causing unbounded work.
        for ($page = 0; $page < 20; $page++) {
            if ($after !== null) {
                $params['after'] = $after;
            }
            $url = $baseUrl . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $response = $client->getJson($url, ['Authorization: Bearer ' . $token]);
            $rows = $response['data'] ?? [];
            if (!is_array($rows)) {
                throw new UpstreamApiFailure('meta_response_invalid');
            }
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $campaigns[] = $this->normalizeCampaign($row);
                }
            }

            $nextAfter = $response['paging']['cursors']['after'] ?? null;
            if (!is_string($nextAfter) || $nextAfter === '' || $nextAfter === $after) {
                break;
            }
            $after = $nextAfter;
        }

        return [
            'totals' => $this->totals($campaigns),
            'campaigns' => $campaigns,
        ];
    }

    private function normalizeCampaign(array $row): array
    {
        $actions = is_array($row['actions'] ?? null) ? $row['actions'] : [];
        return [
            'id' => (string) ($row['campaign_id'] ?? ''),
            'name' => (string) ($row['campaign_name'] ?? 'Campanha sem nome'),
            'platform' => 'Meta Ads',
            'spend' => $this->number($row['spend'] ?? 0),
            'impressions' => (int) $this->number($row['impressions'] ?? 0),
            'clicks' => (int) $this->number($row['clicks'] ?? 0),
            'ctr' => $this->number($row['ctr'] ?? 0),
            'actions' => $actions,
            'results' => $this->normalizeResults($row['results'] ?? $row['objective_results'] ?? []),
            'cost_per_action_type' => is_array($row['cost_per_action_type'] ?? null)
                ? $row['cost_per_action_type'] : [],
            'date_start' => (string) ($row['date_start'] ?? ''),
            'date_stop' => (string) ($row['date_stop'] ?? ''),
        ];
    }

    private function normalizeResults($results): array
    {
        if (!is_array($results)) {
            return [];
        }

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $value = $result['value'] ?? null;
            if (!is_numeric($value) && is_array($result['values'] ?? null)) {
                foreach ($result['values'] as $resultValue) {
                    if (is_array($resultValue) && is_numeric($resultValue['value'] ?? null)) {
                        $value = $resultValue['value'];
                        break;
                    }
                }
            }
            if (!is_numeric($value)) {
                continue;
            }

            $type = '';
            foreach (['title', 'indicator', 'name', 'id', 'description'] as $typeField) {
                $candidate = $result[$typeField] ?? null;
                if (is_string($candidate) && trim($candidate) !== '') {
                    $type = $candidate;
                    break;
                }
            }
            return [[
                'type' => $type,
                'value' => $this->number($value),
            ]];
        }

        return [];
    }

    private function totals(array $campaigns): array
    {
        $totals = ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0];
        foreach ($campaigns as $campaign) {
            $totals['spend'] += $campaign['spend'];
            $totals['impressions'] += $campaign['impressions'];
            $totals['clicks'] += $campaign['clicks'];
        }
        return $totals;
    }

    private function number($value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
