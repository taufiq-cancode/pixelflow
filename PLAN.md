# PixelFlow — Plan

## Context
Take-home assessment: build **PixelFlow**, a Laravel + Vue + Inertia app where signed-in users generate images through **Pixelforge**, a simulated async image API that charges per token and sometimes fails. Users pay Pixelforge's cost × 1.25. What gets graded is correct money (exact costs, never double-charged, failed = $0) and robustness (rate and concurrency limits, overloads, late failures, and webhooks that arrive twice or out of order).
Approved on 2026-11-17. Every judgment call and the reasoning behind it is in [DECISIONS.md](DECISIONS.md). Each workflow step ends with a report and a review checkpoint.

## Facts taken from the docs (none of this is guessed)
- Base URL `https://pixelforge.promptedit.dev/v1`, with a Bearer API key. The **webhook URL, signing secret and usage report are per key**, so we use one key per environment (local and deployed).
- `GET /v1/pricing` is public and gives rates in **USD per 1M tokens as decimal strings** (`"5.00"`, `"10.00"`, `"40.00"`, `"12.50"`), plus the token tables: `characters_per_text_token`, `text_token_variance` (0.15), `per_reference_image` (765), `output[quality][size]`, and `reasoning[quality]{min,max}`. The docs say to read rates from this endpoint, not hardcode them.
- Each generation object carries its own `rates`, fixed at creation, and `usage` (null until it succeeds). The API never returns a price. Only `succeeded` is billed.
- Statuses: `queued → processing → succeeded | failed`. Failure error codes are `generation_failed` and `internal_error`.
- Errors come as `{error:{type,code,message,param?,errors?}}`. Codes: 401 `invalid_api_key`, 422 `validation_failed` (unknown params are rejected), 400 `content_policy_violation`, 404 `not_found`, 409 `idempotency_key_reused`, 429 `rate_limit_exceeded` (Retry-After), 429 `concurrency_limit_exceeded` (Retry-After: 5, limit of 3 in flight), 429 `daily_limit_exceeded` (500 per UTC day), 503 `overloaded` (Retry-After: 2, nothing created). Limits apply per *account*, across all keys (60 requests/min).
- An `Idempotency-Key` header on POST /generations (up to 255 chars, never expires) means the same key with the same params replays the same generation (202, `Idempotent-Replayed: true`).
- Webhooks: `Pixelforge-Signature: t=<unix>,v1=<hex HMAC-SHA256("<t>.<raw body>", whsec_…)>`. Compare in constant time and reject old timestamps. Any 2xx within 10 s counts as delivered. Anything else is retried after 10s, 30s, 2m, 10m and 15m (6 attempts in total), and redirects are not followed. Delivery is at-least-once and in any order, a webhook can arrive **before the POST returns**, and `data.object` is the full generation snapshot. Event types are `generation.processing`, `generation.succeeded`, `generation.failed` and `ping`.
- `GET /v1/usage?start_date&end_date` (UTC, at most 92 days) bills each generation on the **UTC day it completed**. `cost_usd` is an exact 9-decimal string.
- Test keywords: `[blocked]`, `[overloaded]`, `[fail]`, `[succeed]`, `[slow]`, `[duplicate]`.

## Stack setup
- Install a **PHP 8.4 CLI** with winget next to XAMPP (XAMPP's PHP 8.2 is left alone) and enable `pdo_mysql`, `pdo_sqlite`, `mbstring`, `openssl`, `intl`, `fileinfo`, `curl` and `zip`.
- Scaffold with `composer create-project laravel/vue-starter-kit` (Laravel 13, Inertia 3, Vue 3 + TS, Tailwind, Fortify auth, Pest).
- **MySQL everywhere:** a `pixelflow` database on the local XAMPP MariaDB 10.4, and Laravel MySQL on Cloud. Tests run on in-memory SQLite.
- `QUEUE_CONNECTION=database` and the scheduler enabled. `composer dev` already runs the server, queue and Vite. Install **cloudflared** with winget for quick tunnels.
- Config lives in `config/pixelforge.php`. Env vars: `PIXELFORGE_BASE_URL`, `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET`, `PIXELFORGE_SIGNATURE_TOLERANCE=300`, `PIXELFLOW_MARKUP_BPS=2500`. Only `.env.example` is committed, and secrets are never logged.

## Money & pricing formula
- **Unit: integer nanodollars (1e-9 USD) in BIGINT columns.** A rate of R USD per 1M tokens costs R×1000 nano per token (`"12.50"` → 12500), which is an exact integer. `/v1/usage` also reports exactly 9 decimals, so the comparison is 1:1. Decimal strings are parsed by string splitting, never with floats. More than 3 decimals of rate precision throws an exception instead of rounding silently.
- **Provider cost (exact, no rounding):** `Σ usage[k] × rateNano[k]` over the 4 token types, using the **rates on that generation's own object**.
- **User cost:** `ceil(provider × (10000 + 2500) / 10000)`. Markup is applied **once per generation, on the total**, and rounded up to a whole nanodollar, so our margin is never below 25%. With today's rates every provider cost is a multiple of 1000 nano, so ×1.25 is always exact. The ceiling only matters if rates ever change.
- Both amounts are stored as separate columns. Reports **only sum stored per-generation integers** and never re-round an aggregate, so per-generation rows always add up exactly to the admin totals, and provider totals equal `/v1/usage`.
- **Estimate (before submit):**
  - Text tokens: `est = ceil(mb_strlen/4)`, giving a min of `floor(est×0.85)` and a max of `ceil(est×1.15)`. The variance is converted to integer basis points at the boundary; that is the only float we touch, and it is a ratio, not money.
  - Image, output and reasoning come from the catalog. Image and output are exact; reasoning uses the [min, max] range for the chosen quality.
  - The result is a **range (min–max)** shown with a breakdown. Both ends are stored. The max is the guaranteed upper bound, used for affordability.
  - Check against the docs' worked example: max provider cost = 373,875,000 nano, so the user max is $0.46734375.
- Estimates use `/v1/pricing`, cached for 10 min. The actual cost always uses the generation's own `rates` + `usage`.
- Display: the server sends exact decimal strings (`"0.467343750"`). The UI trims trailing zeros and never rounds, so values are shown exactly.

## Data model
- **users**: starter-kit columns plus `is_admin` (bool).
- **generations**:
  - Identity: `user_id`, `idempotency_key` (uuid, **unique**), `pixelforge_id` (nullable, **unique**), `status` (indexed).
  - Request: `prompt`, `size`, `quality`, `reference_images` (json).
  - Estimate: `estimated_min_cost_nano` and `estimated_max_cost_nano` (user-facing).
  - Result: `provider_rates` (json snapshot), `image_url`, `image_url_expires_at`, `error_code`, `error_message`.
  - Bookkeeping: `submit_attempts`, `started_at`, `completed_at`, `last_synced_at`, timestamps.
- **generation_costs** (one row per billed generation): `generation_id` (**unique**), the 4 token counts, `provider_cost_nano`, `user_cost_nano`, `markup_bps`, and `billed_on` (UTC date of `completed_at`, Pixelforge's billing day).
- **webhook_events**: `event_id` (**unique**), `type`, `pixelforge_generation_id`, `delivery_attempt`, `payload` (json), `outcome` (applied / ignored_stale / unmatched / ping), `processed_at`.
- **credit_purchases** (stretch): `user_id`, `amount_nano`.

## State machine (`GenerationStatus` enum)
- Rank: `queued 0 → processing 1 → succeeded 2 | failed 2`. A transition is allowed **only if the new rank is higher than the current one**, so terminal states are never overwritten and a late `processing` after `succeeded` is ignored. Jumping straight from `queued` to `succeeded` (completed arrives before processing) is allowed.
- We follow the snapshot's `data.object.status`, not the event type.
- The local "accepted by us, not yet by Pixelforge" phase is still `queued`, with `pixelforge_id` null. The UI labels it "Waiting for a free slot". This keeps exactly the provider's 4 statuses. A rejection at submit time goes `queued → failed`.
- One action, **ApplyGenerationSnapshot**, is used by the webhook, the poller and the submit response. Inside a DB transaction it locks the generation row (`lockForUpdate`), links `pixelforge_id`, applies forward transitions only, and on `succeeded` records the cost through PricingService. The unique `generation_id` is a backstop. `failed` stores the error and creates **no cost row ($0)**.
- Edge case: if we gave up locally after ambiguous network errors but Pixelforge did create the generation and it succeeded, the status stays `failed` (the user was told it failed and is not charged). We record the provider cost with user cost 0 so our books still match `/v1/usage`.

## Submit flow & provider errors
1. A Form Request validates the prompt (≤4000 chars), size/quality (from the catalog keys) and up to 4 http(s) URLs.
2. A CreateGeneration action computes the estimate. For the stretch goal it locks the user row and checks affordability. It then inserts the generation (`queued`, new uuid) and dispatches **SubmitGenerationJob** after commit.
3. The job POSTs only the documented params, with `Idempotency-Key: <generation uuid>` and `metadata.pixelflow_generation_id`, so a webhook that beats the response can still be matched.
4. A 202 response (replayed or not) goes through ApplyGenerationSnapshot.
5. Retryable errors (429 rate/concurrency, 503 overloaded, timeouts or connection errors, other 5xx) make the job **release itself** with `delay = max(Retry-After, backoff 2/4/8/16/32/60 s) + jitter`, capped at 8 attempts. Retries are safe because the idempotency key is reused.
6. Permanent errors (400 policy, 422, 409, 429 daily, 401, 404), or running out of retries, mark the generation failed with a readable message ("…you were not charged").
- `PixelforgeClient`: POSTs never retry inside the client; retries happen at job level. GETs (poll, pricing, usage) retry inline up to 3 times and honour Retry-After, capped at 10 s. All errors become a single `PixelforgeException` carrying status, code, message and retryAfter.

## Webhook flow (`POST /webhooks/pixelforge`, excluded from CSRF)
1. **Verify first**, against `$request->getContent()`: parse `t` and `v1`, compute the HMAC with `PIXELFORGE_WEBHOOK_SECRET`, compare with `hash_equals`, and require `|now−t| ≤ 300 s`. A failure returns **400** with nothing stored.
2. A malformed JSON or event shape returns 400.
3. In one **DB transaction**:
   - `insertOrIgnore` the `webhook_events` row. Zero rows inserted means a duplicate: return **200** and change nothing. A concurrent duplicate blocks on the unique index and then sees 0 rows.
   - `ping` is acknowledged.
   - Find the generation by `pixelforge_id`, falling back to `metadata.pixelflow_generation_id`, with `lockForUpdate`.
   - Call ApplyGenerationSnapshot.
   - An unknown generation returns 200 with outcome `unmatched` and is logged, since a retry would not help.
4. Commit and return **200** within milliseconds. Any exception rolls back everything, including the event row, and returns **500**, so Pixelforge's retry schedule redelivers it.

## Polling fallback
- `pixelflow:reconcile-generations` runs `everyMinute()->withoutOverlapping()`. It picks queued or processing generations with a `pixelforge_id` that haven't synced for 2 min or more (oldest first, at most 20 per run to respect 60 requests/min), GETs each one, and applies it through ApplyGenerationSnapshot.
- Unsubmitted rows (`pixelforge_id` null) older than 15 min are re-dispatched. This is safe because the idempotency key is reused.

## Stretch (only after the core is green and deployed)
- **Admin report** at `/admin/usage`, gated by `is_admin`, with `pixelflow:make-admin {email}` to grant access. It shows a UTC date range (at most 92 days), per day and per day×user: generations, user spend, provider cost, profit, and a live side-by-side comparison with `/v1/usage` (integer-exact match ✓/✗ on cost, token counts and generation count).
- `pixelflow:audit-usage --start --end` prints the same comparison for Step 6.
- **Balances:** balance = Σ purchases − Σ `user_cost`, and available = balance − Σ `estimated_max` of in-flight generations. CreateGeneration checks affordability under a user-row lock, and the "Buy credits" button adds a $5, $10 or $25 purchase row.

## Key files (to be created)
`app/Services/Pixelforge/PixelforgeClient.php`, `PixelforgeException.php`, `PricingCatalog.php` (value object built from /v1/pricing, plus cached repository), `app/Services/PricingService.php` (pure), `app/Support/Money.php` (parse/format nano), `app/Enums/GenerationStatus.php`, `app/Actions/{CreateGeneration,ApplyGenerationSnapshot,HandlePixelforgeWebhook}.php`, `app/Services/Pixelforge/WebhookSignature.php`, `app/Jobs/SubmitGenerationJob.php`, `app/Http/Controllers/{GenerationController,EstimateController,PixelforgeWebhookController}.php`, `app/Http/Requests/StoreGenerationRequest.php`, `app/Console/Commands/ReconcileGenerations.php`, `resources/js/pages/generations/{Create,Index}.vue`, `config/pixelforge.php`, `routes/web.php`, `routes/console.php`, `bootstrap/app.php` (CSRF exclusion).

## Workflow (report and stop after each step)
1. **Planning:** write PLAN.md and a DECISIONS.md skeleton, then `git init` and make the first commit.
2. **Backend:** PHP 8.4 and scaffold → migrations/models/enum → Money + PricingService (with unit tests) → client + catalog → actions/job/webhook → reconcile command + schedule. Small commits along the way.
3. **Testing:** a Pest suite plus live runs of every keyword through a cloudflared tunnel against the real API (results recorded in the report).
4. **Frontend:** the Generate page (live server-side estimate via a debounced JSON endpoint, with breakdown, loading and error states), and the History page (status badges, est. vs actual, image with expired fallback, error text, `usePoll` only while any generation is in flight, empty state, pagination). Responsive on mobile and desktop.
   Then the stretch goals (admin report, balances), once Steps 1–4 are green.
5. **Deployment:** GitHub repo → Laravel Cloud (MySQL, queue worker, scheduler, env vars). This uses a separate Pixelforge key whose webhook URL points at Cloud. Then verify real generations.
6. **Final audit:** `pixelflow:audit-usage` compared with `/v1/usage`, a full test run, and a final report with the URL and known limitations.

## Verification
- `php artisan test`. Unit tests:
  - Pricing: the docs' worked example (373,875,000) and usage example (169,080,000); ceil edges (1→2, 3→4, 4→5); rate parse edges ("12.5", "0.001", reject "12.5005"); variance bounds.
  - Signature: valid, wrong secret, tampered body, missing or malformed header, stale timestamp.
  - Transition table.
- Feature tests use `Http::fake`:
  - Webhooks: duplicate event id gives one event row and one cost row; succeeded then processing stays succeeded; webhook before the POST returns (metadata match); failed gives $0 and no cost row.
  - Submit job: 202, 429 with Retry-After, 503, 400 policy, 429 daily, connection error, retries exhausted.
  - Reconcile command recovers a lost webhook.
  - Form Request validation.
- Live run: `cloudflared tunnel --url http://127.0.0.1:8000`, then point the local key's webhook URL at it and submit plain, `[succeed]`, `[fail]`, `[slow]`, `[duplicate]`, `[blocked]` and `[overloaded]` prompts. Then check the DB states, the dashboard delivery log, and that `/v1/usage` equals our recorded provider cost.
