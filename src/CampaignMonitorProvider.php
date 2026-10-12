<?php

declare(strict_types=1);

namespace SilverShop\Newsletter\CampaignMonitor;

use SilverShop\Newsletter\Model\NewsletterSubscription;
use SilverShop\Newsletter\Provider\NewsletterProvider;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;

/**
 * Campaign Monitor implementation of silvershop/newsletter's {@see NewsletterProvider}. A thin mapping layer: it
 * turns a {@see NewsletterSubscription} into a Campaign Monitor list subscriber (add/update + unsubscribe via
 * {@see CampaignMonitorClient}) and maps Campaign Monitor webhooks back onto the local subscription status.
 *
 * Configuration comes from the environment (mirroring silvershop/search-meilisearch):
 *   SS_CAMPAIGNMONITOR_API_KEY       the Campaign Monitor API key (HTTP Basic username)
 *   SS_CAMPAIGNMONITOR_LIST_ID       the target list id
 *   SS_CAMPAIGNMONITOR_WEBHOOK_SECRET  optional shared secret required on the webhook URL (?secret=…)
 *
 * The core already performs double opt-in, so the Campaign Monitor list should be configured as **single**
 * opt-in — do not let Campaign Monitor send its own confirmation email on top of the core's.
 */
class CampaignMonitorProvider implements NewsletterProvider
{
    use Configurable;
    use Injectable;

    /**
     * Map of NewsletterSubscription field => Campaign Monitor custom-field key. The mapped custom fields must
     * already exist on the Campaign Monitor list. Name (first + surname) is sent natively, not as a custom field.
     *
     * @var array<string, string>
     */
    private static array $custom_field_map = [];

    private ?CampaignMonitorClient $client = null;

    public function setClient(CampaignMonitorClient $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getKey(): string
    {
        return 'campaignmonitor';
    }

    public function getName(): string
    {
        return _t(self::class . '.NAME', 'Campaign Monitor');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '' && $this->listId() !== '';
    }

    public function isRemote(): bool
    {
        return true;
    }

    public function supportsDoubleOptIn(): bool
    {
        // The core owns double opt-in; the Campaign Monitor list should be single opt-in.
        return false;
    }

    public function supportsEcommerce(): bool
    {
        return false;
    }

    public function supportsMergeFields(): bool
    {
        return true;
    }

    public function supportsTags(): bool
    {
        return false;
    }

    public function supportsSegments(): bool
    {
        return true;
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function subscribe(NewsletterSubscription $subscription): bool
    {
        return $this->upsert($subscription);
    }

    public function update(NewsletterSubscription $subscription): bool
    {
        return $this->upsert($subscription);
    }

    public function unsubscribe(NewsletterSubscription $subscription): bool
    {
        try {
            $this->client()->unsubscribe((string) $subscription->Email);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function syncAll(): int
    {
        $synced = 0;
        $subscribers = NewsletterSubscription::get()->filter([
            'Status' => NewsletterSubscription::STATUS_SUBSCRIBED,
            'Consent' => true,
        ]);
        foreach ($subscribers as $subscription) {
            if ($this->upsert($subscription)) {
                $synced++;
            }
        }

        return $synced;
    }

    public function verifyWebhook(HTTPRequest $request): bool
    {
        $secret = (string) Environment::getEnv('SS_CAMPAIGNMONITOR_WEBHOOK_SECRET');
        if ($secret === '') {
            // No shared secret configured — accept (the webhook only ever changes status for known emails).
            return true;
        }

        $provided = (string) ($request->getVar('secret') ?: $request->getHeader('X-Webhook-Secret'));

        return hash_equals($secret, $provided);
    }

    public function parseWebhook(HTTPRequest $request): array
    {
        $payload = json_decode((string) $request->getBody(), true);
        if (!is_array($payload)) {
            return [];
        }

        // Campaign Monitor posts { "ListID": …, "Events": [ { "Type": …, "EmailAddress": … }, … ] }.
        $rawEvents = $payload['Events'] ?? (array_is_list($payload) ? $payload : []);
        $events = [];
        foreach ($rawEvents as $event) {
            if (!is_array($event)) {
                continue;
            }
            $email = (string) ($event['EmailAddress'] ?? '');
            $status = $this->mapEventType((string) ($event['Type'] ?? ''));
            if ($email !== '' && $status !== '') {
                $events[] = ['email' => $email, 'status' => $status];
            }
        }

        return $events;
    }

    /**
     * Map a Campaign Monitor webhook event type to a local subscription status.
     */
    private function mapEventType(string $type): string
    {
        return match (strtolower($type)) {
            'subscribe' => NewsletterSubscription::STATUS_SUBSCRIBED,
            'update' => NewsletterSubscription::STATUS_SUBSCRIBED,
            'deactivate', 'unsubscribe' => NewsletterSubscription::STATUS_UNSUBSCRIBED,
            'bounce' => NewsletterSubscription::STATUS_BOUNCED,
            default => '',
        };
    }

    private function upsert(NewsletterSubscription $subscription): bool
    {
        try {
            $this->client()->addSubscriber($this->payload($subscription));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Build the Campaign Monitor add-subscriber payload from a subscription.
     *
     * @return array<string, mixed>
     */
    public function payload(NewsletterSubscription $subscription): array
    {
        $merge = $subscription->getMergeFields();
        $name = trim(($merge['FirstName'] ?? '') . ' ' . ($merge['Surname'] ?? ''));

        $customFields = [];
        foreach ((array) static::config()->get('custom_field_map') as $field => $key) {
            if (!empty($merge[$field])) {
                $customFields[] = ['Key' => $key, 'Value' => $merge[$field]];
            }
        }

        return [
            'EmailAddress' => (string) $subscription->Email,
            'Name' => $name,
            'ConsentToTrack' => 'Yes',
            'Resubscribe' => true,
            'CustomFields' => $customFields,
        ];
    }

    private function apiKey(): string
    {
        return (string) Environment::getEnv('SS_CAMPAIGNMONITOR_API_KEY');
    }

    private function listId(): string
    {
        return (string) Environment::getEnv('SS_CAMPAIGNMONITOR_LIST_ID');
    }

    private function client(): CampaignMonitorClient
    {
        return $this->client ??= CampaignMonitorClient::create($this->apiKey(), $this->listId());
    }
}
