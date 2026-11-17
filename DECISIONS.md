# Decisions

Every judgment call in PixelFlow, with the reasoning behind it. Each entry gives the decision, why it was made, and the main alternative that was rejected. This file is updated as the build goes on. If code and this file ever disagree, that's a bug.

Source of truth for provider behaviour: https://pixelforge.promptedit.dev/docs (read 2026-11-17).

---

## 1. Stack

| Decision                                                                               | Why                                                                                                                                                                     |
| -------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Laravel 13 + Inertia 3 + Vue 3 (TS), using the official Vue starter kit (Fortify auth) | The brief asks for Laravel + Vue + Inertia. The starter kit gives a reviewed auth flow, so no time goes into auth plumbing.                                             |
| PHP 8.4 CLI installed next to XAMPP's PHP 8.2                                          | The current starter kit needs PHP ≥ 8.3. The only kit that runs on 8.2 is the Feb 2025 tag. XAMPP is left untouched.                                                    |
| MySQL locally (XAMPP MariaDB 10.4) and on Laravel Cloud                                | Row locks (`SELECT … FOR UPDATE`) behave the same in development and production. SQLite ignores `lockForUpdate`.                                                        |
| Tests on in-memory SQLite                                                              | Fast and isolated. The unique constraints that protect money work the same on SQLite. Locking behaviour is exercised in live tests against MySQL.                       |
| cloudflared quick tunnel for local webhooks                                            | No account needed. Pixelforge rejects localhost or private webhook URLs, so a tunnel is required.                                                                       |
| One Pixelforge API key per environment (local and deployed)                            | The webhook URL, signing secret and `/v1/usage` report are all **per key**. The deployed app's numbers can only match `/v1/usage` exactly if nothing else uses its key. |

## 2. Money unit: integer nanodollars

- **Decision:** every amount is a signed 64-bit integer count of **nanodollars** (1 nano = $0.000000001), stored in `BIGINT` columns. Credits are US dollars, so a balance uses the same unit.
- **Why:**
    - Rates are given in USD per 1M tokens with 2 decimals (`"12.50"`). One token at R $/1M costs `R × 1000` nanodollars, so `"12.50"` is 12,500 nano per token, an exact integer.
    - `/v1/usage` reports `cost_usd` with exactly 9 decimals, so our numbers compare with Pixelforge's 1:1, as integers.
    - The largest generation the docs describe costs 373,875,000 nano. PHP_INT_MAX is about 9.2 × 10¹⁸, so overflow isn't a risk.
- **Rejected:** cents, which are too coarse (a low-quality image costs about $0.012). Micro-dollars, which can't represent 12.5 µ$ per reasoning token. Floats, because the docs warn they drift. Decimal columns + bcmath would work, but integers are simpler to reason about and to sum.

## 3. Parsing provider decimals without floats

- **Decision:** `Money` parses decimal strings (`"5.00"`, `"0.169080000"`) by splitting on `.` and padding the fraction. No `(float)` cast and no `floatval`.
    - A rate with more than 3 decimals (finer than 1 nano per token) **throws** instead of rounding silently.
    - Malformed or negative strings also throw.
- **Why:** an unexpected format should stop the money path loudly. It should never be "approximately" right.
- **One deliberate float:** `/v1/pricing` returns `text_token_variance` as a JSON number (`0.15`). It is converted once, at the boundary, to integer basis points (`round(0.15 × 10000) = 1500`). It's a ratio used only for the _estimate_, never part of a charged amount.

## 4. Provider cost formula

- **Decision:** `provider_cost_nano = Σ usage[k] × rateNanoPerToken[k]` over `input_text_tokens`, `input_image_tokens`, `output_tokens` and `reasoning_tokens`. It uses the **`rates` on that generation's own object**, not the current price list.
- **Why:** the docs say each generation is billed at the rates in force when it was created, and those rates are fixed on the generation. Using them means a price change between submit and completion can't make our number drift from Pixelforge's.
- **No rounding:** this is exactly how Pixelforge bills ("amounts are never rounded").
- **Checks:** the docs' usage example (41 / 0 / 3300 / 2950 tokens) gives 169,080,000 nano = `"0.169080000"`, and the worked example gives 373,875,000 nano = `$0.373875`.

## 5. Markup: order and rounding

- **Decision:** `user_cost_nano = ceil(provider_cost_nano × (10000 + markup_bps) / 10000)`, with `markup_bps = 2500` (25%).
    - The calculation uses integer math only: `intdiv(p × 12500 + 9999, 10000)`.
    - The markup is applied **once per generation, to the total provider cost**. It is not applied per token type, and not per day.
- **Why the total:** each rounding step can add error. One rounding per generation, on the total, is the fewest possible.
- **Why ceil:**
    - Our margin is then never below 25%. The difference is at most 1 nanodollar per generation.
    - With today's rates every provider cost is a multiple of 1,000 nano, so ×1.25 is always exact and the ceiling never actually changes a number. It only matters if Pixelforge changes rates to odd values.
- **Stored:** both `provider_cost_nano` and `user_cost_nano` are kept as separate columns, along with the `markup_bps` used. Changing the markup later doesn't rewrite history.

## 6. Why totals always reconcile

- **Decision:** reports **only sum stored per-generation integers**. No aggregate is ever recomputed from tokens or re-rounded.
- **Why:**
    - Admin totals are exactly the sum of the per-generation rows, by construction.
    - Provider cost per generation is exact and unrounded, so the per-day sum equals `/v1/usage` `cost_usd`, as long as every billed generation has been recorded.
    - Profit = user − provider, summed from the same rows, so it adds up too.

## 7. Billing day

- **Decision:** each cost row stores `billed_on` = the UTC date of the generation's `completed_at`.
- **Why:** the docs say "a generation is billed on the UTC day it completed, not the day it was created". Grouping by `created_at` would break the per-day match with `/v1/usage` for generations that finish just after midnight UTC.

## 8. Failed generations cost $0

- **Decision:** a failed generation gets **no cost row**. Its actual cost is shown as "$0 — not charged".
- **Why:** Pixelforge never bills failures, and a missing charge can't be summed by mistake. There is one exception, in §16.

## 9. Estimate method

- **Decision:** before submit, show a **range** (min–max) with a token breakdown. Both ends are stored on the generation.

    | Token type | Estimated count                                                                                                                                                       | Exact?                                          |
    | ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------- |
    | Text       | `est = ceil(characters / chars_per_token)`, with characters counted as Unicode (`mb_strlen`). min = `floor(est × (1 − variance))`, max = `ceil(est × (1 + variance))` | No: the docs give the real count as within ±15% |
    | Image      | `count × per_reference_image`                                                                                                                                         | Yes                                             |
    | Output     | `output[quality][size]`                                                                                                                                               | Yes                                             |
    | Reasoning  | `[min, max]` for the quality                                                                                                                                          | No: known only after completion                 |

- **Why a range:** the docs guarantee the real count falls in these bounds, so `max` is a true upper bound. Showing it honestly ("between $X and $Y") beats one number that is usually wrong.
- **What `max` is used for:** affordability (balances) uses `max`, so a user can never be charged more than they had available.
- **Rejected:** a single midpoint estimate. It's usually closer to the actual cost, but it's no use as a spending guarantee.

## 10. Where rates come from

- **Decision:** the token tables and current rates come from `GET /v1/pricing`, cached for 10 minutes. Allowed sizes and qualities come from the keys of that table, so nothing about pricing is hardcoded.
- **Why:** the docs say to read rates from the endpoint. The cache keeps the live estimate from calling Pixelforge on every keystroke.
- **Known limitation:** if rates change inside the cache window, the _estimate_ can be slightly off. The _charged_ amount is unaffected (§4).

## 11. Showing money

- **Decision:** the server formats integers into exact decimal strings (`"0.467343750"`). The UI only trims trailing zeros ($0.46734375) and never rounds. It uses string operations only, never `parseFloat`.
- **Why:** what the user sees is exactly what they're charged. This matches Pixelforge's own exact-decimal style.

## 12. State machine

- **Decision:** a `GenerationStatus` enum with ranks `queued 0 → processing 1 → succeeded 2 | failed 2`.
    - A transition is applied **only if the new rank is higher than the current one**.
    - We follow the snapshot's `data.object.status`, not the event type. The docs say "go by the generation's status".
- **Consequences:**
    - Terminal states (`succeeded`, `failed`) can never be overwritten.
    - A late or retried `processing` after `succeeded` is ignored.
    - `queued → succeeded` is allowed directly, which handles completion arriving before processing.
    - `succeeded ↔ failed` is impossible (same rank).
- **Local submission phase:** after we accept the request but before Pixelforge does, the row is `queued` with `pixelforge_id = null`. The UI says "Waiting for a free slot". This keeps exactly the provider's four statuses. If submission is rejected, the row goes `queued → failed`.
- **One code path:** the webhook, the poller and the submit response all call `ApplyGenerationSnapshot`. Ordering bugs can't come from three slightly different implementations.

## 13. Idempotency (never charge twice)

There are four layers, each enough on its own for the case it covers:

1. **Submit:** every generation gets a UUID at creation, sent as `Idempotency-Key`. A retried POST returns the same Pixelforge generation (`Idempotent-Replayed: true`) and is not billed again.
2. **Webhook events:** `webhook_events.event_id` has a **unique index**. The insert is `insertOrIgnore`, and 0 rows inserted means the event was already handled, so we return 200 and do nothing.
    - Two deliveries of the same event at the same moment: the second one waits on the unique index until the first commits, then inserts 0 rows.
3. **Cost recording:** `generation_costs.generation_id` has a **unique index**, so there is one cost row per generation. Even two _different_ events that both say `succeeded` can't create two charges.
4. **Row lock:** every state change runs in a transaction holding `lockForUpdate` on the generation row, so concurrent webhook + poll + submit-response updates are serialized.

## 14. Webhook handling

- **Verify first, on the raw body:** `$request->getContent()`, HMAC-SHA256 of `"<t>.<body>"` with the key's `whsec_…` secret, compared with `hash_equals` (constant time). Timestamps more than **300 s** from now are rejected, because the docs say to reject old timestamps and each retry is signed fresh.
- **Inline transaction, not a queued job:** the work is a few indexed writes and finishes in milliseconds, well under Pixelforge's 10 s timeout. Doing it inline means a 2xx is only returned once the event is safely committed.
    - With a queue, we'd acknowledge first and could lose the event if the job then failed.
    - Nothing slow happens here: images are not downloaded.
- **Status codes**, following "any 2xx = delivered, anything else is retried":

    | Situation                                           | Response                                                                                                      |
    | --------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
    | Invalid or missing signature, or malformed payload  | **400**, nothing stored                                                                                       |
    | Processed, duplicate, `ping`, or unknown generation | **200** (a retry wouldn't help)                                                                               |
    | Unexpected exception                                | **500**, the transaction rolls back including the event row, so Pixelforge's retries (10s … 15m) redeliver it |

- **Webhook before the POST returns:** we send `metadata.pixelflow_generation_id`. When `pixelforge_id` isn't linked yet, the handler matches on that.
- CSRF is excluded for this one route only. Pixelforge has no CSRF token.

## 15. Retry policy for provider errors

| Error                                                                                                                       | Retry? | Behaviour                                                                                          |
| --------------------------------------------------------------------------------------------------------------------------- | ------ | -------------------------------------------------------------------------------------------------- |
| 429 `rate_limit_exceeded`, 429 `concurrency_limit_exceeded`, 503 `overloaded`                                               | Yes    | The docs say nothing was created. The job releases itself with `delay = max(Retry-After, backoff)` |
| Timeouts, connection errors, other 5xx                                                                                      | Yes    | Ambiguous, but safe to retry because the idempotency key is reused                                 |
| 400 `content_policy_violation`, 422 `validation_failed`, 409 `idempotency_key_reused`, 429 `daily_limit_exceeded`, 401, 404 | No     | Retrying can't succeed. The generation is marked failed with a readable message and $0 charged     |

- **Backoff:** 2, 4, 8, 16, 32, 60 s (capped) plus jitter, with at most **8 attempts**. When attempts run out, the generation is marked failed: "Pixelforge is busy right now. You were not charged — please try again."
- **Where retries live:** POST retries happen in the **queued job**, not inside the HTTP request. A concurrency-limit wait can last as long as a 90 s `[slow]` generation, and that shouldn't block a web request. GETs (poll, pricing, usage) retry inline up to 3 times, honouring Retry-After capped at 10 s.
- Every retry counts against the 60 requests/min **account-wide** limit, shared by local and deployed keys. That's why the backoff grows and the poller is capped at 20 requests per run.

## 16. Ambiguous submit failure that later succeeds

- **Scenario:** every attempt times out, we mark the generation failed and tell the user they weren't charged, but Pixelforge _had_ accepted one of the attempts. Its webhooks then arrive, matched by our metadata.
- **Decision:** the status stays `failed` (terminal, and that's what the user was told). If the snapshot says `succeeded`, we still write a cost row with the real `provider_cost_nano` and `user_cost_nano = 0`.
- **Why:** Pixelforge billed us, so our provider totals must include it to match `/v1/usage`. The user isn't charged for something we said failed, and we absorb the loss, which the admin report shows as negative profit.

## 17. Polling fallback

- **Decision:** `pixelflow:reconcile-generations` runs every minute without overlapping.
    - It GETs queued or processing generations not synced in the last 2 minutes, oldest first, at most 20 per run.
    - It re-dispatches unsubmitted rows (`pixelforge_id` null) older than 15 minutes. This is safe because of the idempotency key.
- **Why 2 minutes:** a normal generation takes 5–30 s, or 60–90 s with `[slow]`, and webhooks retry after 10 s and 30 s. A generation silent for 2 minutes has probably lost its webhook.

## 18. Images

- **Decision:** we keep Pixelforge's signed `image_url` and don't download the file. After 24 h the UI shows an "image expired" placeholder.
- **Why:** the brief says it's fine for images to stop loading after the URLs expire. Storing files would add an object store for no gain in correctness.

## 19. Balances (stretch)

- **Decision:**
    - balance = Σ `credit_purchases.amount_nano` − Σ `generation_costs.user_cost_nano`, computed with a query rather than kept in a cached column that could drift.
    - available = balance − Σ `estimated_max_cost_nano` of the user's queued or processing generations.
    - Generation is blocked when available < the new estimate's max. The check runs inside a transaction holding `lockForUpdate` on the user row, so two simultaneous submits can't both spend the same credit.
- **Why:** the actual cost is always ≤ the max estimate (§9), so balances can't go negative. There's no separate hold/release ledger: the in-flight estimates _are_ the hold.

## 20. Admin report (stretch)

- **Decision:** a per-day and per-day×user table of generations, credit spend, provider cost and profit, all summed from `generation_costs` by `billed_on`. It is shown side by side with a live `GET /v1/usage` call, with an exact integer match on cost, token counts and generation count.
- **Why:** it reconciles against Pixelforge's source of truth rather than just restating our own numbers. `pixelflow:audit-usage` prints the same comparison from the CLI.
