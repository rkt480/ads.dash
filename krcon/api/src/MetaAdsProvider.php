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
                        $creativePreviews = $this->adCreativePreviews(
                            $client,
                            $accountId,
                            $apiVersion,
                            $token,
                            $ads
                        );
                    } catch (Throwable $error) {
                        // Preview failures must not block the campaign and ad metrics.
                    }
                }
                foreach ($ads as &$ad) {
                    $preview = $creativePreviews[$ad['id']] ?? [];
                    $ad['preview_url'] = $preview['url'] ?? '';
                    $ad['media_type'] = $preview['type'] ?? '';
                    $ad['video_url'] = $preview['video_url'] ?? '';
                    $ad['carousel_cards'] = $preview['carousel_cards'] ?? [];
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

    /** Refresh only the media fields for ad rows already present in a recent report cache. */
    public function enrichCreativePreviews(array $ads): array
    {
        if ($ads === []) {
            return $ads;
        }

        $token = api_env('META_ACCESS_TOKEN');
        $accountId = api_env('META_AD_ACCOUNT_ID');
        $apiVersion = api_env('META_API_VERSION');
        if ($token === null || $accountId === null || $apiVersion === null) {
            return $ads;
        }

        $accountId = preg_replace('/^act_/', '', $accountId);
        if (!is_string($accountId) || !preg_match('/^[0-9]+$/D', $accountId)
            || !preg_match('/^v[0-9]+\.[0-9]+$/D', $apiVersion)) {
            return $ads;
        }

        try {
            $previews = $this->adCreativePreviews(
                new ServerHttpClient(),
                $accountId,
                $apiVersion,
                $token,
                $ads
            );
        } catch (Throwable $error) {
            return $ads;
        }

        foreach ($ads as &$ad) {
            if (!is_array($ad)) {
                continue;
            }
            $preview = $previews[(string) ($ad['id'] ?? '')] ?? [];
            if (($preview['url'] ?? '') !== '') {
                $ad['preview_url'] = $preview['url'];
            }
            if (($preview['type'] ?? '') !== '') {
                $ad['media_type'] = $preview['type'];
            }
            if (($preview['video_url'] ?? '') !== '') {
                $ad['video_url'] = $preview['video_url'];
            }
            if (!empty($preview['carousel_cards'])) {
                $ad['carousel_cards'] = $preview['carousel_cards'];
            }
        }
        unset($ad);

        return $ads;
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
            'video_url' => '',
            'carousel_cards' => [],
        ];
    }

    private function adCreativePreviews(
        ServerHttpClient $client,
        string $accountId,
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
        if ($wantedIds === []) {
            return [];
        }

        $baseUrl = 'https://graph.facebook.com/' . $apiVersion . '/';
        $previews = [];
        $resolvedAdIds = [];

        // Fetch each ad node in one Graph batch. Multi-ID reads can silently omit
        // expanded creative fields for some accounts, even when Insights returns them.
        $adFields = 'id,creative{id,thumbnail_url,image_url,image_hash,object_type,video_id,effective_object_story_id,object_story_id,effective_instagram_media_id,object_story_spec}';
        try {
            $rows = $this->graphBatchGet($client, $baseUrl, $token, array_keys($wantedIds), $adFields);
            foreach ($rows as $adId => $row) {
                $creative = is_array($row['creative'] ?? null) ? $row['creative'] : [];
                if ($creative === []) {
                    continue;
                }
                $previews[$adId] = $this->normalizeCreativePreview($creative);
                $resolvedAdIds[$adId] = true;
            }
        } catch (UpstreamApiFailure $error) {
            // Fall back to a single multi-ID read below.
        }

        // Request only the ad IDs present in the report, in bounded batches.
        $missingAdIds = array_values(array_diff(array_keys($wantedIds), array_keys($resolvedAdIds)));
        foreach (array_chunk($missingAdIds, 50) as $adIds) {
            try {
                $url = $baseUrl . '?' . http_build_query(
                    ['ids' => implode(',', $adIds), 'fields' => $adFields],
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                );
                $response = $client->getJson($url, ['Authorization: Bearer ' . $token], 8);
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
                    }
                }
            } catch (UpstreamApiFailure $error) {
                // Continue with any ad previews already resolved by the batch read.
            }
        }

        $imageHashesByAd = [];
        $imageHashes = [];
        foreach ($previews as $adId => $preview) {
            if (empty($preview['image_hashes'])
                || (($preview['url'] ?? '') !== '' && empty($preview['carousel_cards']))) {
                continue;
            }
            $imageHashesByAd[$adId] = $preview['image_hashes'];
            foreach ($preview['image_hashes'] as $hash) {
                $imageHashes[$hash] = true;
            }
        }
        foreach (array_chunk(array_keys($imageHashes), 50) as $hashes) {
            try {
                $url = 'https://graph.facebook.com/' . $apiVersion . '/act_' . $accountId . '/adimages?'
                    . http_build_query(
                        [
                            'hashes' => json_encode($hashes, JSON_UNESCAPED_SLASHES),
                            'fields' => 'hash,url',
                        ],
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    );
                $response = $client->getJson($url, ['Authorization: Bearer ' . $token], 8);
                $imageUrlsByHash = [];
                foreach ($response['data'] ?? [] as $image) {
                    if (!is_array($image)) {
                        continue;
                    }
                    $hash = (string) ($image['hash'] ?? '');
                    $imageUrl = $this->safeMediaUrl($image['url'] ?? '');
                    if ($hash !== '' && $imageUrl !== '') {
                        $imageUrlsByHash[$hash] = $imageUrl;
                    }
                }
                foreach ($imageHashesByAd as $adId => $adHashes) {
                    foreach ($previews[$adId]['carousel_cards'] ?? [] as $cardIndex => $card) {
                        $cardHash = (string) ($card['hash'] ?? '');
                        if (isset($imageUrlsByHash[$cardHash])) {
                            $previews[$adId]['carousel_cards'][$cardIndex]['url'] = $imageUrlsByHash[$cardHash];
                        }
                    }
                    if (count($previews[$adId]['carousel_cards'] ?? []) > 1
                        && ($previews[$adId]['carousel_cards'][0]['url'] ?? '') !== '') {
                        $previews[$adId]['url'] = $previews[$adId]['carousel_cards'][0]['url'];
                    }
                    if (!empty($previews[$adId]['url'])) {
                        continue;
                    }
                    foreach ($adHashes as $hash) {
                        if (isset($imageUrlsByHash[$hash])) {
                            $previews[$adId]['url'] = $imageUrlsByHash[$hash];
                            $previews[$adId]['type'] = $previews[$adId]['type'] ?: 'image';
                            break;
                        }
                    }
                }
            } catch (UpstreamApiFailure $error) {
                // Keep any thumbnails already resolved when image-hash lookup fails.
                break;
            }
        }

        $storyIdsByAd = [];
        $storyIds = [];
        foreach ($previews as $adId => $preview) {
            $storyId = (string) ($preview['story_id'] ?? '');
            if ($storyId === '') {
                continue;
            }
            $storyIdsByAd[$adId] = $storyId;
            $storyIds[$storyId] = true;
        }
        if ($storyIds !== []) {
            try {
                $storyRows = $this->graphBatchGet(
                    $client,
                    $baseUrl,
                    $token,
                    array_keys($storyIds),
                    'full_picture,attachments{media,subattachments}'
                );
                foreach ($storyIdsByAd as $adId => $storyId) {
                    $story = $storyRows[$storyId] ?? [];
                    $storyCards = $this->storyCarouselCards($story);
                    if (count($storyCards) > count($previews[$adId]['carousel_cards'] ?? [])) {
                        $previews[$adId]['carousel_cards'] = $storyCards;
                        $previews[$adId]['url'] = $storyCards[0]['url'];
                    }
                    $url = $this->storyPreviewUrl($story);
                    if (($previews[$adId]['url'] ?? '') === '' && $url !== '') {
                        $previews[$adId]['url'] = $url;
                        $previews[$adId]['type'] = $previews[$adId]['type'] ?: 'image';
                    }
                }
            } catch (UpstreamApiFailure $error) {
                // Existing-post ads may not expose post media to this token.
            }
        }

        $instagramIdsByAd = [];
        $instagramMediaIds = [];
        foreach ($previews as $adId => $preview) {
            $instagramMediaId = (string) ($preview['instagram_media_id'] ?? '');
            if ($instagramMediaId === '') {
                continue;
            }
            $instagramIdsByAd[$adId] = $instagramMediaId;
            $instagramMediaIds[$instagramMediaId] = true;
        }
        if ($instagramMediaIds !== []) {
            try {
                $mediaRows = $this->graphBatchGet(
                    $client,
                    $baseUrl,
                    $token,
                    array_keys($instagramMediaIds),
                    'media_type,media_url,thumbnail_url'
                );
                foreach ($instagramIdsByAd as $adId => $mediaId) {
                    $media = $mediaRows[$mediaId] ?? [];
                    $isVideo = strtoupper((string) ($media['media_type'] ?? '')) === 'VIDEO';
                    $mediaUrl = $this->safeMediaUrl($media['media_url'] ?? '');
                    $thumbnailUrl = $this->safeMediaUrl($media['thumbnail_url'] ?? '');
                    if ($isVideo) {
                        $previews[$adId]['type'] = 'video';
                        if ($mediaUrl !== '') {
                            $previews[$adId]['video_url'] = $mediaUrl;
                        }
                    }
                    if (($previews[$adId]['url'] ?? '') === '') {
                        $url = $thumbnailUrl ?: ($isVideo ? '' : $mediaUrl);
                        if ($url !== '') {
                            $previews[$adId]['url'] = $url;
                            $previews[$adId]['type'] = $isVideo ? 'video' : 'image';
                        }
                    }
                }
            } catch (UpstreamApiFailure $error) {
                // Some Meta tokens cannot read Instagram media nodes.
            }
        }

        $videoIdsByAd = [];
        foreach ($previews as $adId => $preview) {
            if (!empty($preview['video_id'])) {
                $videoIdsByAd[$adId] = (string) $preview['video_id'];
                $previews[$adId]['type'] = 'video';
            }
        }
        $videoIdsWithoutSource = [];
        foreach (array_chunk(array_values(array_unique($videoIdsByAd)), 50) as $videoIds) {
            try {
                $videos = $this->graphBatchGet($client, $baseUrl, $token, $videoIds, 'source,picture,thumbnails');
                foreach ($videoIdsByAd as $adId => $videoId) {
                    if (!in_array($videoId, $videoIds, true)) {
                        continue;
                    }
                    $video = $videos[$videoId] ?? [];
                    $sourceUrl = $this->safeMediaUrl($video['source'] ?? '');
                    if ($sourceUrl !== '') {
                        $previews[$adId]['video_url'] = $sourceUrl;
                    }
                    $imageUrl = $this->videoPreviewUrl($video);
                    if ($imageUrl !== '') {
                        $previews[$adId]['url'] = $imageUrl;
                        $previews[$adId]['type'] = 'video';
                    }
                }
            } catch (UpstreamApiFailure $error) {
                // Retry source lookup with a multi-ID read below.
            }
            foreach ($videoIdsByAd as $adId => $videoId) {
                if (!in_array($videoId, $videoIds, true)) {
                    continue;
                }
                if (($previews[$adId]['video_url'] ?? '') === '') {
                    $videoIdsWithoutSource[$videoId] = true;
                }
            }
        }
        foreach (array_chunk(array_keys($videoIdsWithoutSource), 50) as $videoIds) {
            foreach ([
                'id,source,picture,thumbnails',
                'id,picture,thumbnails',
            ] as $fields) {
                try {
                    $url = $baseUrl . '?' . http_build_query(
                        ['ids' => implode(',', $videoIds), 'fields' => $fields],
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    );
                    $response = $client->getJson($url, ['Authorization: Bearer ' . $token], 8);
                    $rows = is_array($response['data'] ?? null) ? $response['data'] : $response;
                    $videoImages = [];
                    foreach ($rows as $key => $video) {
                        if (!is_array($video) || isset($video['error'])) {
                            continue;
                        }
                        $videoId = (string) ($video['id'] ?? $key);
                        $sourceUrl = $this->safeMediaUrl($video['source'] ?? '');
                        $imageUrl = $this->videoPreviewUrl($video);
                        if ($imageUrl !== '' || $sourceUrl !== '') {
                            $videoImages[$videoId] = [
                                'image_url' => $imageUrl,
                                'video_url' => $sourceUrl,
                            ];
                        }
                    }
                    foreach ($videoIdsByAd as $adId => $videoId) {
                        if (isset($videoImages[$videoId])) {
                            if ($videoImages[$videoId]['image_url'] !== '') {
                                $previews[$adId]['url'] = $videoImages[$videoId]['image_url'];
                            }
                            if ($videoImages[$videoId]['video_url'] !== '') {
                                $previews[$adId]['video_url'] = $videoImages[$videoId]['video_url'];
                            }
                            $previews[$adId]['type'] = 'video';
                        }
                    }
                    if ($videoImages !== []) {
                        break;
                    }
                } catch (UpstreamApiFailure $error) {
                    // Retry without the optional video thumbnail collection field.
                }
            }
        }

        return $previews;
    }

    /** @return array<string, array> */
    private function graphBatchGet(
        ServerHttpClient $client,
        string $baseUrl,
        string $token,
        array $ids,
        string $fields
    ): array {
        $requests = [];
        $requestedIds = [];
        foreach (array_values(array_unique($ids)) as $id) {
            $id = (string) $id;
            if ($id === '') {
                continue;
            }
            $requests[] = [
                'method' => 'GET',
                'relative_url' => rawurlencode($id) . '?' . http_build_query(
                    ['fields' => $fields],
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                ),
            ];
            $requestedIds[] = $id;
        }
        if ($requests === []) {
            return [];
        }

        $response = $client->postForm(
            $baseUrl,
            ['batch' => json_encode($requests, JSON_UNESCAPED_SLASHES)],
            ['Authorization: Bearer ' . $token],
            8
        );
        $rows = [];
        foreach ($response as $index => $item) {
            if (!is_array($item) || (int) ($item['code'] ?? 0) < 200 || (int) ($item['code'] ?? 0) >= 300) {
                continue;
            }
            $body = json_decode((string) ($item['body'] ?? ''), true);
            if (!is_array($body) || isset($body['error'])) {
                continue;
            }
            $id = (string) ($body['id'] ?? $requestedIds[$index] ?? '');
            if ($id !== '') {
                $rows[$id] = $body;
            }
        }
        return $rows;
    }

    private function storyPreviewUrl(array $story): string
    {
        $url = $this->safeMediaUrl($story['full_picture'] ?? '');
        $attachments = is_array($story['attachments']['data'] ?? null)
            ? $story['attachments']['data'] : [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $media = is_array($attachment['media'] ?? null) ? $attachment['media'] : [];
            $image = is_array($media['image'] ?? null) ? $media['image'] : [];
            $url = $url
                ?: $this->safeMediaUrl($image['src'] ?? '')
                ?: $this->safeMediaUrl($media['thumbnail']['src'] ?? '');
            if ($url !== '') {
                return $url;
            }
            $subattachments = is_array($attachment['subattachments']['data'] ?? null)
                ? $attachment['subattachments']['data'] : [];
            foreach ($subattachments as $subattachment) {
                if (!is_array($subattachment)) {
                    continue;
                }
                $subMedia = is_array($subattachment['media'] ?? null) ? $subattachment['media'] : [];
                $subImage = is_array($subMedia['image'] ?? null) ? $subMedia['image'] : [];
                $url = $this->safeMediaUrl($subImage['src'] ?? '')
                    ?: $this->safeMediaUrl($subMedia['thumbnail']['src'] ?? '');
                if ($url !== '') {
                    return $url;
                }
            }
        }
        return $url;
    }

    private function storyCarouselCards(array $story): array
    {
        $attachments = is_array($story['attachments']['data'] ?? null)
            ? $story['attachments']['data'] : [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $subattachments = is_array($attachment['subattachments']['data'] ?? null)
                ? $attachment['subattachments']['data'] : [];
            $cards = [];
            foreach ($subattachments as $subattachment) {
                if (!is_array($subattachment)) {
                    continue;
                }
                $media = is_array($subattachment['media'] ?? null) ? $subattachment['media'] : [];
                $image = is_array($media['image'] ?? null) ? $media['image'] : [];
                $url = $this->safeMediaUrl($image['src'] ?? '')
                    ?: $this->safeMediaUrl($media['thumbnail']['src'] ?? '');
                if ($url !== '') {
                    $cards[] = ['url' => $url];
                }
            }
            if (count($cards) > 1) {
                return $cards;
            }
        }
        return [];
    }

    private function videoPreviewUrl(array $video): string
    {
        $url = '';
        $bestArea = -1;
        $thumbnails = is_array($video['thumbnails'] ?? null)
            ? ($video['thumbnails']['data'] ?? $video['thumbnails']) : [];
        if (is_array($thumbnails)) {
            foreach ($thumbnails as $thumbnail) {
                if (!is_array($thumbnail)) {
                    continue;
                }
                $thumbnailUrl = $this->safeMediaUrl($thumbnail['uri'] ?? '');
                if ($thumbnailUrl === '') {
                    continue;
                }
                $area = max(1, (int) ($thumbnail['width'] ?? 0))
                    * max(1, (int) ($thumbnail['height'] ?? 0));
                if ($area > $bestArea) {
                    $url = $thumbnailUrl;
                    $bestArea = $area;
                }
            }
        }
        return $url ?: $this->safeMediaUrl($video['picture'] ?? '');
    }

    private function normalizeCreativePreview(array $creative): array
    {
        $story = is_array($creative['object_story_spec'] ?? null) ? $creative['object_story_spec'] : [];
        $videoData = is_array($story['video_data'] ?? null) ? $story['video_data'] : [];
        $linkData = is_array($story['link_data'] ?? null) ? $story['link_data'] : [];
        $photoData = is_array($story['photo_data'] ?? null) ? $story['photo_data'] : [];
        $assetFeed = is_array($creative['asset_feed_spec'] ?? null) ? $creative['asset_feed_spec'] : [];
        $imageHashes = [];
        foreach ([
            $creative['image_hash'] ?? '',
            $videoData['image_hash'] ?? '',
            $linkData['image_hash'] ?? '',
            $photoData['image_hash'] ?? '',
        ] as $imageHash) {
            if (is_string($imageHash) && $imageHash !== '') {
                $imageHashes[$imageHash] = true;
            }
        }

        $url = $this->safeMediaUrl($creative['image_url'] ?? '')
            ?: $this->safeMediaUrl($videoData['image_url'] ?? '')
            ?: $this->safeMediaUrl($linkData['picture'] ?? '')
            ?: $this->safeMediaUrl($photoData['url'] ?? '')
            ?: $this->safeMediaUrl($creative['thumbnail_url'] ?? '');

        $carouselCards = [];
        if (is_array($linkData['child_attachments'] ?? null)) {
            foreach ($linkData['child_attachments'] as $attachment) {
                if (!is_array($attachment)) {
                    continue;
                }
                $attachmentHash = (string) ($attachment['image_hash'] ?? '');
                if ($attachmentHash !== '') {
                    $imageHashes[$attachmentHash] = true;
                }
                $cardUrl = $this->safeMediaUrl($attachment['image_url'] ?? '')
                    ?: $this->safeMediaUrl($attachment['picture'] ?? '');
                if ($cardUrl !== '' || $attachmentHash !== '') {
                    $carouselCards[] = ['url' => $cardUrl, 'hash' => $attachmentHash];
                    if ($url === '') {
                        $url = $cardUrl;
                    }
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
                    $assetHash = (string) ($asset['hash'] ?? '');
                    if ($assetType === 'images' && $assetHash !== '') {
                        $imageHashes[$assetHash] = true;
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
        $videoId = (string) ($creative['video_id'] ?? $videoData['video_id'] ?? '');
        if ($videoId === '' && is_array($assetFeed['videos'] ?? null)) {
            foreach ($assetFeed['videos'] as $video) {
                if (is_array($video) && !empty($video['video_id'])) {
                    $videoId = (string) $video['video_id'];
                    break;
                }
            }
        }
        $storyId = (string) ($creative['effective_object_story_id'] ?? $creative['object_story_id'] ?? '');

        return [
            'url' => $url,
            'type' => $isVideo ? 'video' : ($url !== '' ? 'image' : ''),
            'video_url' => '',
            'carousel_cards' => $carouselCards,
            'video_id' => $videoId,
            'story_id' => $storyId,
            'instagram_media_id' => (string) ($creative['effective_instagram_media_id'] ?? ''),
            'image_hashes' => array_keys($imageHashes),
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
            || substr($host, -strlen('.fbsbx.com')) === '.fbsbx.com'
            || $host === 'instagram.com'
            || substr($host, -strlen('.instagram.com')) === '.instagram.com'
            || $host === 'cdninstagram.com'
            || substr($host, -strlen('.cdninstagram.com')) === '.cdninstagram.com';
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
