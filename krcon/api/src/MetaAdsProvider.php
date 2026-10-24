<?php
declare(strict_types=1);

require_once __DIR__ . '/IntegrationNotConfigured.php';
require_once __DIR__ . '/ServerHttpClient.php';

/** Read-only adapter boundary for Meta Ads. Credentials are read only on the server. */
final class MetaAdsProvider
{
    public function readInsights(array $range, bool $includeAds = false): array
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

        $ads = [];
        $adsStatus = 'not_requested';
        if ($includeAds) {
            try {
                $ads = $this->readAdInsights($client, $accountId, $apiVersion, $range, $token, $campaigns);
                $creativePreviews = [];
                if ($ads !== []) {
                    try {
                        $creativePreviews = $this->adCreativePreviews($client, $apiVersion, $token, $ads);
                    } catch (Throwable $error) {
                        // Preview failures must not block the campaign and ad metrics.
                    }
                }
                foreach ($ads as &$ad) {
                    $preview = $creativePreviews[$ad['id']] ?? [];
                    $ad['preview_url'] = $preview['url'] ?? '';
                    $ad['media_type'] = $preview['type'] ?? '';
                }
                unset($ad);
                $adsStatus = 'connected';
            } catch (UpstreamApiFailure $error) {
                $ads = [];
                $adsStatus = 'unavailable';
            }
        }

        return [
            'totals' => $this->totals($campaigns),
            'campaigns' => $campaigns,
            'ads' => $ads,
            'ads_status' => $adsStatus,
        ];
    }

    private function readAdInsights(
        ServerHttpClient $client,
        string $accountId,
        string $apiVersion,
        array $range,
        string $token,
        array $campaigns
    ): array {
        $campaignsById = [];
        foreach ($campaigns as $campaign) {
            $campaignsById[$campaign['id']] = $campaign;
        }

        $baseUrl = 'https://graph.facebook.com/' . $apiVersion . '/act_' . $accountId . '/insights';
        $params = [
            'level' => 'ad',
            'time_range' => json_encode($range, JSON_UNESCAPED_SLASHES),
            'fields' => 'ad_id,ad_name,campaign_id,campaign_name,spend,impressions,reach,clicks,actions,results,cost_per_action_type,ctr,date_start,date_stop',
            'limit' => '500',
        ];
        $ads = [];
        $after = null;

        for ($page = 0; $page < 20; $page++) {
            if ($after !== null) {
                $params['after'] = $after;
            }
            $url = $baseUrl . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $response = $client->getJson($url, ['Authorization: Bearer ' . $token]);
            $rows = $response['data'] ?? [];
            if (!is_array($rows)) {
                throw new UpstreamApiFailure('meta_ad_insights_invalid');
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $campaign = $campaignsById[(string) ($row['campaign_id'] ?? '')] ?? [];
                $ad = $this->normalizeAd($row, $campaign);
                if ($this->hasReportData($ad)) {
                    unset($ad['actions']);
                    $ads[] = $ad;
                }
            }

            $nextAfter = $response['paging']['cursors']['after'] ?? null;
            if (!is_string($nextAfter) || $nextAfter === '' || $nextAfter === $after) {
                break;
            }
            $after = $nextAfter;
        }

        usort($ads, static fn (array $left, array $right): int => $right['spend'] <=> $left['spend']);
        return $ads;
    }

    private function normalizeAd(array $row, array $campaign): array
    {
        $actions = is_array($row['actions'] ?? null) ? $row['actions'] : [];
        $objective = (string) ($campaign['objective'] ?? '');
        $results = $this->normalizeResults($row['results'] ?? $row['objective_results'] ?? []);
        $resultType = (string) ($results[0]['type'] ?? '');
        if ($this->isLeadObjective($objective) || $this->isLeadResultType($resultType)) {
            $leadResult = $this->leadResultFromActions($actions);
            if ($leadResult !== null && ($results === [] || ($results[0]['value'] ?? 0) <= 0)) {
                $results = [$leadResult];
            }
        }

        return [
            'id' => (string) ($row['ad_id'] ?? ''),
            'name' => (string) ($row['ad_name'] ?? 'Anúncio sem nome'),
            'campaign_id' => (string) ($row['campaign_id'] ?? ''),
            'campaign_name' => (string) ($row['campaign_name'] ?? $campaign['name'] ?? 'Campanha sem nome'),
            'spend' => $this->number($row['spend'] ?? 0),
            'reach' => (int) $this->number($row['reach'] ?? 0),
            'impressions' => (int) $this->number($row['impressions'] ?? 0),
            'clicks' => (int) $this->number($row['clicks'] ?? 0),
            'ctr' => $this->number($row['ctr'] ?? 0),
            'actions' => $actions,
            'results' => $results,
            'result' => $results[0] ?? ['type' => '', 'value' => 0.0],
            'preview_url' => '',
            'media_type' => '',
        ];
    }

    private function adCreativePreviews(
        ServerHttpClient $client,
        string $apiVersion,
        string $token,
        array $reportAds
    ): array {
        $wantedIds = [];
        foreach ($reportAds as $ad) {
            if (is_array($ad) && (string) ($ad['id'] ?? '') !== '') {
                $wantedIds[(string) $ad['id']] = true;
            }
        }

        $baseUrl = 'https://graph.facebook.com/' . $apiVersion . '/';
        $adFields = 'id,creative{id,thumbnail_url,image_url,object_type,video_id,object_story_spec}';

        $previews = [];
        $creativeIdsByAd = [];
        foreach (array_chunk(array_keys($wantedIds), 50) as $adIds) {
            try {
                $url = $baseUrl . '?' . http_build_query(
                    ['ids' => implode(',', $adIds), 'fields' => $adFields],
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                );
                $response = $client->getJson($url, ['Authorization: Bearer ' . $token], 6);
                $rows = is_array($response['data'] ?? null) ? $response['data'] : $response;
                foreach ($rows as $key => $row) {
                    if (!is_array($row) || isset($row['error'])) {
                        continue;
                    }
                    $id = (string) ($row['id'] ?? $key);
                    if (!isset($wantedIds[$id])) {
                        continue;
                    }
                    $creative = is_array($row['creative'] ?? null) ? $row['creative'] : [];
                    if ($creative !== []) {
                        $previews[$id] = $this->normalizeCreativePreview($creative);
                        $creativeId = (string) ($creative['id'] ?? '');
                        if ($creativeId !== '' && $previews[$id]['url'] === '') {
                            $creativeIdsByAd[$id] = $creativeId;
                        }
                    }
                }
            } catch (UpstreamApiFailure $error) {
                // Keep report metrics available when the optional media lookup fails.
                break;
            }
        }

        // Resolve missing thumbnails by creative ID in batches instead of one request per ad.
        foreach (array_chunk(array_values(array_unique($creativeIdsByAd)), 50) as $creativeIds) {
            try {
                $url = $baseUrl . '?' . http_build_query(
                    [
                        'ids' => implode(',', $creativeIds),
                        'fields' => 'id,thumbnail_url,image_url,object_type,video_id,object_story_spec,asset_feed_spec',
                    ],
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                );
                $response = $client->getJson($url, ['Authorization: Bearer ' . $token], 6);
                $rows = is_array($response['data'] ?? null) ? $response['data'] : $response;
                $creativesById = [];
                foreach ($rows as $key => $creative) {
                    if (!is_array($creative) || isset($creative['error'])) {
                        continue;
                    }
                    $creativeId = (string) ($creative['id'] ?? $key);
                    $creativesById[$creativeId] = $creative;
                }
                foreach ($creativeIdsByAd as $adId => $creativeId) {
                    $creative = $creativesById[$creativeId] ?? null;
                    if (!is_array($creative)) {
                        continue;
                    }
                    $preview = $this->normalizeCreativePreview($creative);
                    if ($preview['url'] !== '') {
                        $previews[$adId] = $preview;
                    }
                }
            } catch (UpstreamApiFailure $error) {
                // Keep report metrics available when the optional media lookup fails.
                break;
            }
        }

        return $previews;
    }

    private function normalizeCreativePreview(array $creative): array
    {
        $story = is_array($creative['object_story_spec'] ?? null) ? $creative['object_story_spec'] : [];
        $videoData = is_array($story['video_data'] ?? null) ? $story['video_data'] : [];
        $linkData = is_array($story['link_data'] ?? null) ? $story['link_data'] : [];
        $photoData = is_array($story['photo_data'] ?? null) ? $story['photo_data'] : [];
        $assetFeed = is_array($creative['asset_feed_spec'] ?? null) ? $creative['asset_feed_spec'] : [];

        $url = $this->safeMediaUrl($creative['thumbnail_url'] ?? '')
            ?: $this->safeMediaUrl($creative['image_url'] ?? '')
            ?: $this->safeMediaUrl($videoData['image_url'] ?? '')
            ?: $this->safeMediaUrl($linkData['picture'] ?? '')
            ?: $this->safeMediaUrl($photoData['url'] ?? '');

        if ($url === '' && is_array($linkData['child_attachments'] ?? null)) {
            foreach ($linkData['child_attachments'] as $attachment) {
                if (!is_array($attachment)) {
                    continue;
                }
                $url = $this->safeMediaUrl($attachment['picture'] ?? '')
                    ?: $this->safeMediaUrl($attachment['image_url'] ?? '');
                if ($url !== '') {
                    break;
                }
            }
        }

        if ($url === '') {
            foreach (['images', 'videos'] as $assetType) {
                $assets = is_array($assetFeed[$assetType] ?? null) ? $assetFeed[$assetType] : [];
                foreach ($assets as $asset) {
                    if (!is_array($asset)) {
                        continue;
                    }
                    $url = $this->safeMediaUrl($asset['thumbnail_url'] ?? '')
                        ?: $this->safeMediaUrl($asset['image_url'] ?? '')
                        ?: $this->safeMediaUrl($asset['url'] ?? '');
                    if ($url !== '') {
                        break 2;
                    }
                }
            }
        }

        $isVideo = !empty($creative['video_id'])
            || strtoupper((string) ($creative['object_type'] ?? '')) === 'VIDEO'
            || $videoData !== []
            || !empty($assetFeed['videos']);

        return [
            'url' => $url,
            'type' => $isVideo ? 'video' : ($url !== '' ? 'image' : ''),
        ];
    }

    private function safeMediaUrl($value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        $parts = parse_url($value);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return '';
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $isMetaHost = $host === 'facebook.com'
            || substr($host, -strlen('.facebook.com')) === '.facebook.com'
            || substr($host, -strlen('.fbcdn.net')) === '.fbcdn.net'
            || substr($host, -strlen('.fbsbx.com')) === '.fbsbx.com';
        return $isMetaHost ? $value : '';
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
        foreach (['spend', 'reach', 'impressions', 'clicks', 'ctr'] as $metric) {
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
