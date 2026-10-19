# silvershop/newsletter-campaignmonitor

[Campaign Monitor](https://www.campaignmonitor.com/) ESP adapter for
[`silvershop/newsletter`](https://github.com/RVXD/silvershop-newsletter). It implements the core's
`NewsletterProvider` contract with a **thin Guzzle client** (no heavy official SDK) and maps subscriber changes to
the Campaign Monitor API v3.3, plus a webhook back to the local list.

The core owns capture, consent and double opt-in; this adapter just syncs confirmed subscribers to a Campaign
Monitor list and keeps the local status in step via webhooks.

## Requirements

- `silvershop/newsletter` `dev-main` (the core — not on Packagist, see **Installation**)
- `guzzlehttp/guzzle` ^7
- `silverstripe/framework` ^6, `silverstripe/cms` ^6, PHP 8.3 / 8.4

`silvershop/newsletter` hard-requires `symbiote/silverstripe-queuedjobs`, so the queued sync runner comes in
transitively.

## Installation

`silvershop/newsletter` is an RVXD module (not published to Packagist), so it is resolved from a composer
`repositories` entry. The shipped `composer.json` uses the **VCS** form:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/RVXD/silvershop-newsletter.git" }
]
```

Then:

```bash
composer require silvershop/newsletter-campaignmonitor:dev-main
```

(A private newsletter repo needs a GitHub token with `repo` scope, e.g. `composer config -g github-oauth.github.com <token>`.)

### Local development (core not yet pushed)

When you are developing both modules side by side and `silvershop/newsletter` isn't published yet, point the
adapter at your local checkout with a **path** repository instead of (or in addition to) the VCS entry — this is
how the dev site and the standalone clean-room resolve it:

```json
"repositories": [
    { "type": "path", "url": "../silvershop-newsletter" }
]
```

## Configuration

Credentials come from the environment (`.env`):

```
SS_CAMPAIGNMONITOR_API_KEY="your-api-key"
SS_CAMPAIGNMONITOR_LIST_ID="your-list-id"
SS_CAMPAIGNMONITOR_WEBHOOK_SECRET="optional-shared-secret"
```

Then make Campaign Monitor the active provider:

```yaml
SilverShop\Newsletter\Provider\NewsletterProviderRegistry:
  active: campaignmonitor
```

Optionally map subscription merge fields to Campaign Monitor **custom fields** (which must already exist on the
list — Name/first+surname is sent natively):

```yaml
SilverShop\Newsletter\CampaignMonitor\CampaignMonitorProvider:
  custom_field_map:
    Locale: '[locale]'
```

> **Configure the Campaign Monitor list as SINGLE opt-in.** The core already performs double opt-in (it emails the
> subscriber a confirm link and only syncs them once confirmed). If the Campaign Monitor list is *also* set to
> confirmed opt-in, subscribers would receive a second confirmation email. Single opt-in avoids the double-confirm.

## How it maps

| Operation | Campaign Monitor API v3.3 |
|---|---|
| subscribe / update | `POST subscribers/{listid}.json` (add-or-update; `Resubscribe: true`) |
| unsubscribe | `POST subscribers/{listid}/unsubscribe.json` |
| full resync (`sake tasks:NewsletterResync --run`) | add-or-update for every locally-subscribed record |

Authentication is HTTP Basic with the API key as the username.

## Webhook (Campaign Monitor → local)

Point a Campaign Monitor webhook at:

```
https://your-shop.example/newsletter/webhook/campaignmonitor
```

(append `?secret=<SS_CAMPAIGNMONITOR_WEBHOOK_SECRET>` if you set a secret). The core's webhook controller calls
this adapter's `verifyWebhook()` + `parseWebhook()`, which map Campaign Monitor events to the local status:

| Campaign Monitor event | Local status |
|---|---|
| Subscribe | Subscribed |
| Update | Subscribed |
| Deactivate / Unsubscribe | Unsubscribed |
| Bounce | Bounced |

## Development

```bash
composer install
composer run lint    # phpcs (PSR-12)
composer run stan    # phpstan (cambis/silverstan, level 5)
composer run test    # phpunit — uses a FakeCampaignMonitorClient, no live HTTP
```

## License

BSD-3-Clause.

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
