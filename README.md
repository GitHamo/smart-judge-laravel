# SmartJudge for Laravel

Laravel bridge for `potato/smart-judge`: config, container wiring, an answer cache, a pause after a failure, and a log of outages. Supports Laravel 11 and 12.

## Install

```sh
composer require potato/smart-judge-laravel
php artisan vendor:publish --tag=smart-judge-config
```

Package discovery registers the service provider. The second command copies `config/smart-judge.php` into your app.

## Config

| Variable | Default | Purpose |
|---|---|---|
| `SMART_JUDGE_ENABLED` | `true` | `false` gives no judge, so no data leaves the server |
| `SMART_JUDGE_DRIVER` | `typesafe` | The driver that answers |
| `SMART_JUDGE_CACHE_STORE` | app default | Cache store for answers and the pause |
| `SMART_JUDGE_LOG_ENABLED` | `true` | `false` logs nothing |
| `SMART_JUDGE_LOG_CHANNEL` | app default | Log channel of the outage warnings |
| `TYPESAFE_API_KEY` | empty | Bearer token of the TypeSafe driver. Empty gives no judge |
| `TYPESAFE_MODEL` | `jev-1.13.0` | TypeSafe model |
| `TYPESAFE_BASE_URL` | `https://api.typesafe.ai/v1` | TypeSafe API base URL |
| `TYPESAFE_TIMEOUT` | `5` | Request timeout in seconds |

In the config file only:

- `cache.ttl`: seconds an answer is kept, 6 hours by default. `scopes.<scope>.ttl` sets it for one scope.
- `cache.unavailable_ttl`: seconds no scope asks a driver again after it was unavailable, 60 by default.

## Usage

Ask the factory for a judge per scope, the name of the feature that asks. It gives none when SmartJudge is disabled or its driver has no key, so bind your own fallback in that case:

```php
use Potato\SmartJudge\Laravel\JudgeFactory;

$this->app->bind(SubscriptionJudge::class, function (): SubscriptionJudge {
    $judge = $this->app->make(JudgeFactory::class)->make('subscriptions');

    return null === $judge ? new KeywordSubscriptionJudge() : new SmartSubscriptionJudge($judge);
});
```

`SmartSubscriptionJudge` then uses the judge as described in the core README and catches `JudgeUnavailable` to fall back. The judge from the factory:

- caches answers per scope, keyed by the driver and the request, so the same question is not paid for twice;
- after a driver was unavailable, throws `JudgeUnavailable` without asking for `cache.unavailable_ttl` seconds, in every scope, so an outage costs one slow request;
- logs one warning per outage with the driver, the HTTP status, the reason and the scope. Cache hits, skips and answers are not logged.

Clear the cached answers of one scope, or of every scope, e.g. after changing a question. It works on every cache store:

```sh
php artisan smart-judge:clear subscriptions
php artisan smart-judge:clear
```

## Extending

Register another driver by name, then select it with `SMART_JUDGE_DRIVER`. The callback gets the driver's config array, `drivers.<name>`, and returns null when that config is not enough to ask:

```php
$this->app->make(JudgeFactory::class)->extend('acme', fn (array $config): ?AcmeDriver => empty($config['key']) ? null : new AcmeDriver($config['key']));
```

Every boundary is a container binding you can replace:

| Binding | Default |
|---|---|
| `smart-judge.driver` | the driver that config selects |
| `smart-judge.http` | the Guzzle client of the TypeSafe driver |
| `smart-judge.cache` | the store `cache.store` names |
| `smart-judge.logger` | the channel `log.channel` names |

In tests, bind `smart-judge.http` to a Guzzle client with a `MockHandler` to stub the model's answers.

## Tests

```sh
composer install
vendor/bin/phpunit
```
