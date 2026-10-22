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
            'fields' => 'campaign_id,campaign_name,objective,spend,impressions,clicks,actions,results,cost_per_action_type,ctr,date_start,date_stop',
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
                    $campaign = $this->normalizeCampaign($row);
                    if ($this->hasReportData($campaign)) {
                        $campaigns[] = $campaign;
                    }
                }
            }

            $nextAfter = $response['paging']['cursors']['after'] ?? null;
            if (!is_string($nextAfter) || $nextAfter === '' || $nextAfter === $after) {
                break;
            }
            $after = $nextAfter;
        }

        $statuses = $this->campaignStatuses($client, $accountId, $apiVersion, $token);
        foreach ($campaigns as &$campaign) {
            $campaign['status'] = $statuses[$campaign['id']] ?? '';
        }
        unset($campaign);

        return [
            'totals' => $this->totals($campaigns),
            'campaigns' => $campaigns,
        ];
    }

    private function campaignStatuses(
        ServerHttpClient $client,
        string $accountId,
        string $apiVersion,
        string $token
    ): array {
        $baseUrl = 'https://graph.facebook.com/' . $apiVersion . '/act_' . $accountId . '/campaigns';
        $params = ['fields' => 'id,status,effective_status', 'limit' => '500'];
        $statuses = [];
        $after = null;

        try {
            for ($page = 0; $page < 20; $page++) {
                if ($after !== null) {
                    $params['after'] = $after;
                }
                $url = $baseUrl . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
                $response = $client->getJson($url, ['Authorization: Bearer ' . $token]);
                $rows = $response['data'] ?? [];
                if (!is_array($rows)) {
                    break;
                }
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $id = (string) ($row['id'] ?? '');
                    if ($id === '') {
                        continue;
                    }
                    $effectiveStatus = strtoupper((string) ($row['effective_status'] ?? ''));
                    $statuses[$id] = $effectiveStatus !== ''
                        ? $effectiveStatus
                        : strtoupper((string) ($row['status'] ?? ''));
                }

                $nextAfter = $response['paging']['cursors']['after'] ?? null;
                if (!is_string($nextAfter) || $nextAfter === '' || $nextAfter === $after) {
                    break;
                }
                $after = $nextAfter;
            }
        } catch (UpstreamApiFailure $error) {
            // Keep report metrics available if the separate status lookup is unavailable.
            return [];
        }

        return $statuses;
    }

    private function normalizeCampaign(array $row): array
    {
        $actions = is_array($row['actions'] ?? null) ? $row['actions'] : [];
        $objective = (string) ($row['objective'] ?? '');
        $results = $this->normalizeResults($row['results'] ?? $row['objective_results'] ?? []);
        $resultType = (string) ($results[0]['type'] ?? '');
        if ($this->isLeadObjective($objective) || $this->isLeadResultType($resultType)) {
            $leadResult = $this->leadResultFromActions($actions);
            if ($leadResult !== null && ($results === [] || ($results[0]['value'] ?? 0) <= 0)) {
                $results = [$leadResult];
            } elseif ($results === [] && $this->isLeadObjective($objective)) {
                $results = [['type' => 'onsite_conversion.lead_grouped', 'value' => 0.0]];
            }
        }

        return [
            'id' => (string) ($row['campaign_id'] ?? ''),
            'name' => (string) ($row['campaign_name'] ?? 'Campanha sem nome'),
            'platform' => 'Meta Ads',
            'spend' => $this->number($row['spend'] ?? 0),
            'impressions' => (int) $this->number($row['impressions'] ?? 0),
            'clicks' => (int) $this->number($row['clicks'] ?? 0),
            'ctr' => $this->number($row['ctr'] ?? 0),
            'actions' => $actions,
            'results' => $results,
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

        $first = null;
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $valueCandidates = [];
            if (is_numeric($result['value'] ?? null)) {
                $valueCandidates[] = $result['value'];
            }
            if (is_array($result['values'] ?? null)) {
                foreach ($result['values'] as $resultValue) {
                    if (is_array($resultValue) && is_numeric($resultValue['value'] ?? null)) {
                        $valueCandidates[] = $resultValue['value'];
                    } elseif (is_numeric($resultValue)) {
                        $valueCandidates[] = $resultValue;
                    }
                }
            }
            if ($valueCandidates === []) {
                continue;
            }
            $value = $valueCandidates[0];
            foreach ($valueCandidates as $candidateValue) {
                if ($this->number($candidateValue) != 0) {
                    $value = $candidateValue;
                    break;
                }
            }

            $type = '';
            foreach (['indicator', 'title', 'name', 'id', 'description'] as $typeField) {
                $candidate = $result[$typeField] ?? null;
                if (is_string($candidate) && trim($candidate) !== '') {
                    $type = $candidate;
                    break;
                }
            }
            $normalized = [
                'type' => $type,
                'value' => $this->number($value),
            ];
            if ($normalized['value'] != 0) {
                return [$normalized];
            }
            $first ??= $normalized;
        }

        return $first === null ? [] : [$first];
    }

    private function isLeadObjective(string $objective): bool
    {
        return stripos($objective, 'lead') !== false;
    }

    private function isLeadResultType(string $type): bool
    {
        return stripos($type, 'lead') !== false;
    }

    private function leadResultFromActions(array $actions): ?array
    {
        $leadTypes = [
            'onsite_conversion.lead_grouped',
            'onsite_conversion.leadgen_grouped',
            'onsite_conversion.lead',
            'lead',
            'offsite_conversion.fb_pixel_lead',
        ];
        $firstMatch = null;
        foreach ($leadTypes as $leadType) {
            foreach ($actions as $action) {
                if (!is_array($action)) {
                    continue;
                }
                $actionType = strtolower((string) ($action['action_type'] ?? ''));
                if ($actionType !== $leadType
                    && strncmp($actionType, $leadType . '.', strlen($leadType) + 1) !== 0) {
                    continue;
                }
                $result = [
                    'type' => $leadType,
                    'value' => $this->number($action['value'] ?? 0),
                ];
                if ($result['value'] != 0) {
                    return $result;
                }
                $firstMatch ??= $result;
            }
        }
        return $firstMatch;
    }

    private function hasReportData(array $campaign): bool
    {
        foreach (['spend', 'impressions', 'clicks', 'ctr'] as $metric) {
            if (($campaign[$metric] ?? 0) != 0) {
                return true;
            }
        }
        if (($campaign['results'][0]['value'] ?? 0) != 0) {
            return true;
        }
        foreach ($campaign['actions'] ?? [] as $action) {
            if (is_array($action) && $this->number($action['value'] ?? 0) != 0) {
                return true;
            }
        }
        return false;
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
