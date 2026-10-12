<?php

declare(strict_types=1);

namespace SilverShop\Newsletter\CampaignMonitor\Tests;

use SilverShop\Newsletter\CampaignMonitor\CampaignMonitorClient;

/**
 * In-memory stand-in for {@link CampaignMonitorClient} — records calls, returns canned responses, makes no HTTP
 * request. Deliberately does not call the parent constructor. Mirrors meilisearch's FakeMeiliClient.
 */
class FakeCampaignMonitorClient extends CampaignMonitorClient
{
    /** @var array<int, array<string, mixed>> */
    public array $added = [];

    /** @var array<int, string> */
    public array $unsubscribed = [];

    public bool $throwOnAdd = false;

    public function __construct()
    {
        // No HTTP client.
    }

    public function addSubscriber(array $body): array
    {
        if ($this->throwOnAdd) {
            throw new \RuntimeException('Simulated Campaign Monitor API error');
        }
        $this->added[] = $body;

        return ['EmailAddress' => $body['EmailAddress'] ?? ''];
    }

    public function unsubscribe(string $email): void
    {
        $this->unsubscribed[] = $email;
    }
}
