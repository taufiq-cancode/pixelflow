# PixelFlow

PixelFlow is a Laravel 13 + Inertia 3 + Vue 3 app where signed-in users generate images through
[Pixelforge](https://pixelforge.promptedit.dev/docs). Pixelforge is an asynchronous, token-priced image API.
Users are charged Pixelforge's exact cost plus a 25% markup, recorded per generation in integer nanodollars.

- [PLAN.md](PLAN.md): architecture, data model, state machine, pricing formula and webhook flow.
- [DECISIONS.md](DECISIONS.md): every judgment call (money units, rounding, retries, idempotency) with its reasoning.

## Requirements

- PHP 8.4 (the lock file needs ≥ 8.4.1) with `pdo_mysql`, `mbstring`, `intl`, `openssl`, `curl`, `fileinfo`, `zip`.
  PHP's cURL needs a CA bundle (`curl.cainfo` / `openssl.cafile` in `php.ini`), or every call to Pixelforge fails
  with "cURL error 60".
- Composer 2, Node 22+ and npm
- MySQL 8 / MariaDB 10.4+

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# create the database named in DB_DATABASE (default: pixelflow), then:
php artisan migrate
npm install
npm run build
composer dev   # app server, queue worker and Vite
```

The app needs three long-running processes:

| Process      | Command                     | Why                                                                    |
| ------------ | --------------------------- | ---------------------------------------------------------------------- |
| Web server   | `php artisan serve`         | UI, the live estimate and the webhook endpoint                         |
| Queue worker | `php artisan queue:work`    | Sends generations to Pixelforge, retrying with backoff when it is busy |
| Scheduler    | `php artisan schedule:work` | Runs `pixelflow:reconcile-generations` every minute (lost-webhook net) |

Long-running processes read `.env` once at start-up. **Restart the queue worker and scheduler after changing
`.env`**, and don't start `php artisan serve` with `--no-reload`: that freezes the `.env` values it started with.

## Environment variables

| Variable                         | Purpose                                                         |
| -------------------------------- | --------------------------------------------------------------- |
| `PIXELFORGE_BASE_URL`            | `https://pixelforge.promptedit.dev/v1`                          |
| `PIXELFORGE_API_KEY`             | API key for **this environment only** (one key per environment) |
| `PIXELFORGE_WEBHOOK_SECRET`      | The `whsec_…` signing secret of that same key                   |
| `PIXELFORGE_SIGNATURE_TOLERANCE` | Seconds a webhook timestamp may differ from now (default 300)   |
| `PIXELFLOW_MARKUP_BPS`           | Markup in basis points (default 2500 = 25%)                     |
| `QUEUE_CONNECTION`               | `database` (submissions run on the queue)                       |

Rates and token tables are never configured: they come from `GET /v1/pricing` and from each generation's own `rates`.

## Webhooks

The endpoint is `POST /webhooks/pixelforge`. It sits outside the `web` middleware group (no session or CSRF) and
authenticates each request by its `Pixelforge-Signature` HMAC.

**Use one Pixelforge API key per environment.** The webhook URL, the signing secret and the `/v1/usage` report all
belong to a single key. If two environments share a key, one environment's usage includes the other's generations,
and its numbers can no longer match `/v1/usage`.

### Local development

Pixelforge rejects localhost webhook URLs, so expose the app with a tunnel:

```bash
php artisan serve --port=8010
cloudflared tunnel --url http://127.0.0.1:8010
```

1. In the Pixelforge dashboard, create a key for local development.
2. Set its endpoint URL to `https://<random>.trycloudflare.com/webhooks/pixelforge`.
3. Copy the key and its signing secret into `.env`.
4. Press **Send test event**. The ping should show `HTTP 200` in the dashboard's delivery log.

Quick-tunnel URLs change every time `cloudflared` restarts, and Cloudflare drops idle quick tunnels. Update the
dashboard URL when that happens. Missed events are recovered by the reconciler, or can be resent from the dashboard.

## Tests

```bash
php artisan test         # PHPUnit: unit + feature tests on in-memory SQLite
composer test            # the CI set: Pint, PHPStan (level 7) and the tests
```

Tests can never reach the real API. `phpunit.xml` points Pixelforge at `https://pixelforge.test` with dummy secrets,
and `Http::preventStrayRequests()` fails any unfaked request. What is covered:

- **Money:** the docs' worked, usage and succeeded examples (exact nanodollars), markup rounding edges, Unicode
  character counting, and refusal to round over-precise rates.
- **Webhook signatures:** valid, wrong secret, tampered body, missing, malformed and stale timestamp.
- **Webhook ordering and idempotency:** duplicate deliveries recorded once, two success events charging once,
  completed-before-processing, late events never moving a generation backwards, a failure rolling back so the retry
  succeeds, matching by metadata before the POST returns, and events from another environment ignored.
- **Provider errors:** idempotency key and payload, `Retry-After` and backoff for 429/503/5xx/network errors,
  permanent errors failing at $0, giving up after the last attempt.
- **Lost webhooks:** the reconciler recovering successes and failures, and charging once even if the webhook
  arrives later.
- **Database constraints:** a second cost row, a reused Pixelforge id or a reused idempotency key are rejected by
  the database itself.

### Live testing against Pixelforge

With the tunnel, queue worker and scheduler running, the prompt keywords force each behaviour: `[succeed]`,
`[fail]`, `[slow]`, `[duplicate]`, `[blocked]` and `[overloaded]`. Then compare the day's
`GET /v1/usage?start_date=…&end_date=…` with the sum of `generation_costs` for that `billed_on` date.
