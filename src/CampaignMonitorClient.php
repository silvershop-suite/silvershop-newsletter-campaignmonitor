<?php

declare(strict_types=1);

namespace SilverShop\Newsletter\CampaignMonitor;

use GuzzleHttp\Client;
use SilverStripe\Core\Injector\Injectable;

/**
 * Thin Campaign Monitor REST client (Guzzle). Deliberately minimal — just the subscriber add/update + unsubscribe
 * endpoints the provider needs — to avoid the official campaignmonitor/createsend SDK (which carries its own HTTP
 * stack). Mirrors silvershop/search-meilisearch's MeiliClient.
 *
 * Campaign Monitor API v3.3, authenticated with HTTP Basic using the API key as the username (any password).
 * @see https://www.campaignmonitor.com/api/v3-3/
 */
class CampaignMonitorClient
{
    use Injectable;

    private Client $http;

    public function __construct(string $apiKey, private string $listId)
    {
        $this->http = new Client([
            'base_uri' => 'https://api.createsend.com/api/v3.3/',
            'auth' => [$apiKey, 'x'],
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 15,
        ]);
    }

    /**
     * Add or update a subscriber on the list. Campaign Monitor updates an existing subscriber's details when the
     * email already exists (with Resubscribe=true), so this one call covers both subscribe and update.
     *
     * @param array<string, mixed> $body e.g. ['EmailAddress' => …, 'Name' => …, 'Resubscribe' => true]
     * @return array<string, mixed>
     */
    public function addSubscriber(array $body): array
    {
        return $this->json('POST', "subscribers/{$this->listId}.json", $body);
    }

    /**
     * Unsubscribe an email address from the list.
     */
    public function unsubscribe(string $email): void
    {
        $this->http->request('POST', "subscribers/{$this->listId}/unsubscribe.json", [
            'json' => ['EmailAddress' => $email],
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function json(string $method, string $uri, array $body): array
    {
        $response = $this->http->request($method, $uri, ['json' => $body]);

        return json_decode((string) $response->getBody(), true) ?: [];
    }
}
