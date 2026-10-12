<?php

declare(strict_types=1);

namespace SilverShop\Newsletter\CampaignMonitor\Tests;

use SilverShop\Newsletter\CampaignMonitor\CampaignMonitorProvider;
use SilverShop\Newsletter\Model\NewsletterSubscription;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;

class CampaignMonitorProviderTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testCapabilityFlags(): void
    {
        $provider = new CampaignMonitorProvider();
        $this->assertSame('campaignmonitor', $provider->getKey());
        $this->assertTrue($provider->isRemote());
        $this->assertTrue($provider->supportsWebhooks());
        $this->assertTrue($provider->supportsMergeFields());
        // The core owns double opt-in, so the CM list is single opt-in.
        $this->assertFalse($provider->supportsDoubleOptIn());
    }

    public function testIsConfiguredFromEnv(): void
    {
        Environment::setEnv('SS_CAMPAIGNMONITOR_API_KEY', '');
        Environment::setEnv('SS_CAMPAIGNMONITOR_LIST_ID', '');
        $this->assertFalse((new CampaignMonitorProvider())->isConfigured());

        Environment::setEnv('SS_CAMPAIGNMONITOR_API_KEY', 'key-123');
        Environment::setEnv('SS_CAMPAIGNMONITOR_LIST_ID', 'list-abc');
        $this->assertTrue((new CampaignMonitorProvider())->isConfigured());
    }

    public function testSubscribeSendsExpectedPayload(): void
    {
        $fake = new FakeCampaignMonitorClient();
        $provider = (new CampaignMonitorProvider())->setClient($fake);

        $subscription = NewsletterSubscription::create();
        $subscription->Email = 'buyer@example.com';
        $subscription->FirstName = 'Ada';
        $subscription->Surname = 'Lovelace';
        $subscription->confirm();
        $subscription->write();

        $this->assertTrue($provider->subscribe($subscription));
        $this->assertCount(1, $fake->added);
        $payload = $fake->added[0];
        $this->assertSame('buyer@example.com', $payload['EmailAddress']);
        $this->assertSame('Ada Lovelace', $payload['Name']);
        $this->assertSame('Yes', $payload['ConsentToTrack']);
        $this->assertTrue($payload['Resubscribe']);
    }

    public function testCustomFieldMapping(): void
    {
        CampaignMonitorProvider::config()->set('custom_field_map', ['Locale' => 'locale']);
        $fake = new FakeCampaignMonitorClient();
        $provider = (new CampaignMonitorProvider())->setClient($fake);

        $subscription = NewsletterSubscription::create();
        $subscription->Email = 'loc@example.com';
        $subscription->Locale = 'nl_NL';
        $subscription->confirm();
        $subscription->write();

        $provider->subscribe($subscription);
        $this->assertSame(
            [['Key' => 'locale', 'Value' => 'nl_NL']],
            $fake->added[0]['CustomFields']
        );
    }

    public function testSubscribeReturnsFalseOnApiError(): void
    {
        $fake = new FakeCampaignMonitorClient();
        $fake->throwOnAdd = true;
        $provider = (new CampaignMonitorProvider())->setClient($fake);

        $subscription = NewsletterSubscription::create();
        $subscription->Email = 'err@example.com';
        $subscription->confirm();
        $subscription->write();

        $this->assertFalse($provider->subscribe($subscription));
    }

    public function testUnsubscribe(): void
    {
        $fake = new FakeCampaignMonitorClient();
        $provider = (new CampaignMonitorProvider())->setClient($fake);

        $subscription = NewsletterSubscription::create();
        $subscription->Email = 'leave@example.com';
        $subscription->write();

        $this->assertTrue($provider->unsubscribe($subscription));
        $this->assertSame(['leave@example.com'], $fake->unsubscribed);
    }

    public function testSyncAllPushesSubscribedRecords(): void
    {
        $fake = new FakeCampaignMonitorClient();
        $provider = (new CampaignMonitorProvider())->setClient($fake);

        foreach (['one@example.com', 'two@example.com'] as $email) {
            $subscription = NewsletterSubscription::create();
            $subscription->Email = $email;
            $subscription->confirm();
            $subscription->write();
        }
        // A pending record must NOT be synced.
        $pending = NewsletterSubscription::create();
        $pending->Email = 'pending@example.com';
        $pending->Status = NewsletterSubscription::STATUS_PENDING;
        $pending->write();

        $this->assertSame(2, $provider->syncAll());
        $this->assertCount(2, $fake->added);
    }

    public function testParseWebhookMapsEvents(): void
    {
        $provider = new CampaignMonitorProvider();
        $body = json_encode([
            'ListID' => 'list-abc',
            'Events' => [
                ['Type' => 'Subscribe', 'EmailAddress' => 'sub@example.com'],
                ['Type' => 'Deactivate', 'EmailAddress' => 'off@example.com'],
                ['Type' => 'Unknown', 'EmailAddress' => 'skip@example.com'],
            ],
        ]);
        $request = new HTTPRequest('POST', 'newsletter/webhook/campaignmonitor', [], [], $body);

        $events = $provider->parseWebhook($request);
        $this->assertCount(2, $events);
        $this->assertSame(
            ['email' => 'sub@example.com', 'status' => NewsletterSubscription::STATUS_SUBSCRIBED],
            $events[0]
        );
        $this->assertSame(
            ['email' => 'off@example.com', 'status' => NewsletterSubscription::STATUS_UNSUBSCRIBED],
            $events[1]
        );
    }

    public function testVerifyWebhookWithoutSecretAccepts(): void
    {
        Environment::setEnv('SS_CAMPAIGNMONITOR_WEBHOOK_SECRET', '');
        $request = new HTTPRequest('POST', 'newsletter/webhook/campaignmonitor');
        $this->assertTrue((new CampaignMonitorProvider())->verifyWebhook($request));
    }

    public function testVerifyWebhookWithSecret(): void
    {
        Environment::setEnv('SS_CAMPAIGNMONITOR_WEBHOOK_SECRET', 's3cret');

        $ok = new HTTPRequest('POST', 'newsletter/webhook/campaignmonitor', ['secret' => 's3cret']);
        $this->assertTrue((new CampaignMonitorProvider())->verifyWebhook($ok));

        $bad = new HTTPRequest('POST', 'newsletter/webhook/campaignmonitor', ['secret' => 'wrong']);
        $this->assertFalse((new CampaignMonitorProvider())->verifyWebhook($bad));

        Environment::setEnv('SS_CAMPAIGNMONITOR_WEBHOOK_SECRET', '');
    }
}
