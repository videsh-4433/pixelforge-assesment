# Pixelforge Studio

A Laravel + Vue + Inertia app where users generate images through the Pixelforge API. It shows the price before they submit, charges the exact cost afterwards, and stays correct when Pixelforge rate-limits, overloads, fails, or sends webhooks twice or out of order.

**Stack:** Laravel 13, Vue 3, Inertia 2, Tailwind, Postgres 17, Pest. Everything runs in Docker, so you don't need PHP or Node installed locally.

## Features

- **Generate:** write a prompt, then choose size, quality and up to 4 reference image URLs. A live price estimate (expected price plus a min–max range and token breakdown) is shown before you submit.
- **History:** live status for each generation (submitting → queued → processing → succeeded / failed), with the estimated and actual charge and the image. If Pixelforge is busy, the card says why it's waiting.
- **Accurate billing:** users pay Pixelforge's exact cost plus 25%. Each generation is charged once at most, and failed generations are free.
- **Webhooks:** signatures are verified, and duplicate or out-of-order events are handled. If a webhook is lost, the app falls back to polling.
- **Credit balances** (stretch goal): a "Buy credits" button adds fake credits, the worst-case price is held while a generation runs, and you can't generate without enough credits.
- **Admin report** (stretch goal): credit spend, provider cost and profit per day and per user, reconciled against Pixelforge's `GET /v1/usage`.

## Running locally

Requires Docker with Compose v2.

```bash
git clone <this repo> pixelforge && cd pixelforge
cp .env.example .env                 # then fill in PF_API_KEY (and PF_WEBHOOK_SECRET, see below)

docker compose build
docker compose up -d db
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate
docker compose up -d                 # app, queue worker, scheduler, vite (runs npm install), postgres
```

- The app runs at http://localhost:8088, and Vite's dev server at http://localhost:5175. If those ports are taken, change `APP_PORT` / `VITE_PORT` in `.env`.
- After registering, run `docker compose exec app php artisan app:make-admin you@example.com` to unlock the admin report.
- Credits are fake: use **Credits → Buy credits** to add some before generating.

### Environment variables

| Variable | Purpose |
|---|---|
| `PF_API_KEY` | Pixelforge API key. Use one key per environment, because `/v1/usage` is reported per key. |
| `PF_WEBHOOK_SECRET` | That key's webhook signing secret (`whsec_...`). Without it, every webhook is rejected. |
| `PF_MARKUP_PERCENT` | Markup on Pixelforge's cost. Defaults to `25`. |
| `PF_MAX_IN_FLIGHT_PER_USER` | Generations one user can have running at once. Defaults to `3`. |
| `PF_MAX_GENERATIONS_PER_USER_PER_DAY` | Per-user daily cap, reset at midnight UTC. Defaults to `50`. |
| `PF_MAX_BALANCE_DOLLARS` | Cap on the free demo credits a user can hold. Defaults to `50`. |
| `ADMIN_EMAILS` | Comma-separated emails that can see the admin report. The alternative is `php artisan app:make-admin`. |
| `APP_PORT`, `VITE_PORT` | Host ports used by Docker Compose. |

### Webhooks in local dev

Pixelforge only sends webhooks to public URLs, so start the optional Cloudflare tunnel:

```bash
docker compose --profile tunnel up -d tunnel
docker compose logs tunnel | grep trycloudflare      # wait a few seconds for the URL
```

Then, in the Pixelforge dashboard:

1. Set the key's webhook URL to `https://<tunnel-url>/api/webhooks/pixelforge`.
2. Copy its signing secret into `PF_WEBHOOK_SECRET`, then run `docker compose restart app queue scheduler`.
3. Click **Send test event**. It should show a `204` response.

The free tunnel URL changes whenever the tunnel container restarts. Without a tunnel the app still works: the scheduler polls in-flight generations every minute.

### Tests

The tests run against a separate `pixelforge_test` Postgres database and never call the real API, because every HTTP request is faked.

```bash
docker compose exec db psql -U pixelforge -c "create database pixelforge_test"   # once
docker compose exec app php artisan test
```

### Useful commands

```bash
docker compose logs -f queue                          # submissions, retries, backoff
docker compose exec app php artisan generations:sync  # run the polling fallback manually
docker compose exec app php artisan tinker
```

## Deployment

The app needs three processes besides the web server:

- a Postgres database
- a **queue worker** (`php artisan queue:work`), which submits generations to Pixelforge
- the **scheduler** (`php artisan schedule:run` every minute, or `schedule:work`), which polls for lost webhooks

### Render (free tier)

The repo includes a [render.yaml](render.yaml) Blueprint and a production image ([docker/production/Dockerfile](docker/production/Dockerfile)). Render's free plan only gives you one container, so the container runs the web server (FrankenPHP), the queue worker and the scheduler together.

1. Push to GitHub. In Render, choose **New → Blueprint** and select the repo.
2. When prompted, fill in:
   - `APP_KEY`: generate it with `docker compose exec app php artisan key:generate --show`
   - `APP_URL`: `https://<service>.onrender.com`
   - `PF_API_KEY` and `PF_WEBHOOK_SECRET`: from a **production** Pixelforge key
3. Set that key's webhook URL to `https://<service>.onrender.com/api/webhooks/pixelforge`.
4. Register, then add your email to the `ADMIN_EMAILS` environment variable (comma-separated) to unlock the admin report. The free plan has no Shell tab.

Free-tier caveats:

- **The service sleeps after about 15 minutes idle.** A webhook that arrives while it's asleep wakes it up but may hit Pixelforge's 10-second timeout. Pixelforge retries after 10s and 30s, and the retry succeeds. Duplicate deliveries are safe, and the scheduler polls anything missed once the service is awake.
- **To keep it awake**, ping `/up` every 10 minutes with a free uptime monitor. That uses about 720 of the 750 free instance hours per month.
- **Free Postgres is limited to one per account and expires after a while.** For a longer-lived database, point `DB_URL` at a free external Postgres such as Neon.

### Laravel Cloud



1. Attach a Postgres database.
2. Enable a queue worker and the scheduler.
3. Set `PF_API_KEY`, `PF_WEBHOOK_SECRET` and `APP_URL`, plus **`APP_ENV=production` and `APP_DEBUG=false`**. With debug on, an error page can reveal environment variables, including the API key.
4. Use the deploy commands `php artisan migrate --force` and `npm ci && npm run build`.
5. Create a **separate Pixelforge API key** for production, and set its webhook URL to `https://<your-domain>/api/webhooks/pixelforge`.

## How it works

```
User submits ─▶ GenerationService            check affordability, hold worst-case price, create `pending` row
              └▶ SubmitGeneration (queue)    POST /v1/generations  (Idempotency-Key = our uuid)
                                             429/503/timeouts → retry w/ backoff, honouring Retry-After
                                             400/422/daily limit → fail immediately, free
Pixelforge ─▶ POST /api/webhooks/pixelforge  verify signature → dedupe by event id → GenerationSync
Scheduler  ─▶ generations:sync (every min)   poll anything in flight we haven't heard about → GenerationSync

GenerationSync::apply()  ← the only place a generation changes state or is charged
```

### Key files

| Concern | File |
|---|---|
| Money representation | [app/Support/Money.php](app/Support/Money.php) |
| Cost from token usage | [app/Pricing/TokenRates.php](app/Pricing/TokenRates.php) |
| Markup | [app/Pricing/Markup.php](app/Pricing/Markup.php) |
| Up-front estimate | [app/Pricing/CostEstimator.php](app/Pricing/CostEstimator.php) |
| State machine + charging | [app/Services/GenerationSync.php](app/Services/GenerationSync.php), [app/Enums/GenerationStatus.php](app/Enums/GenerationStatus.php) |
| Ledger / balances | [app/Services/CreditLedger.php](app/Services/CreditLedger.php) |
| Submission + retries | [app/Jobs/SubmitGeneration.php](app/Jobs/SubmitGeneration.php) |
| Webhooks | [app/Http/Controllers/PixelforgeWebhookController.php](app/Http/Controllers/PixelforgeWebhookController.php), [app/Pixelforge/WebhookSignature.php](app/Pixelforge/WebhookSignature.php) |
| Polling fallback | [app/Console/Commands/SyncGenerations.php](app/Console/Commands/SyncGenerations.php) |
| Admin report | [app/Reports/SpendReport.php](app/Reports/SpendReport.php) |

## Decisions

### Money is stored as integer nanodollars

Pixelforge prices are exact decimals with 9 decimal places (`"0.024940000"`), so every amount is stored as a `bigint` count of 1e-9 USD.

- Every provider cost is represented exactly, and adding them up never drifts. Floats would drift.
- Strings are parsed digit by digit, never through a float. Anything with more than 9 decimals is rejected rather than silently rounded.
- Pixelforge's rates are per million tokens, so `cost = tokens × rate / 1,000,000`. With the published rates this always comes out to a whole number of nanodollars (for example, $12.50 per 1M tokens is 12,500 nanodollars per token).
- If a rate ever produced a fraction of a nanodollar, it is rounded **up**, so we never undercharge.

### Pricing: cost + 25%, rounded once

- `charge = ceil(cost × 125 / 100)` nanodollars, applied once to the total cost, not to each token type.
- With the current rates this is always exact, and the rounding never triggers.
- The markup is configurable with `PF_MARKUP_PERCENT`.
- We bill using the **`rates` on the generation object**, which Pixelforge fixes when the generation is created. We don't use today's price list, so a price change during a generation can't cause a mismatch.

### The estimate is a range

Text tokens are only approximately 1 per 4 characters (±15%), and reasoning tokens vary by quality, so the price shown before submitting is a range:

- **Expected:** `ceil(chars/4)` text tokens, the exact image and output tokens, and the midpoint of the reasoning range. This is the headline number.
- **Min / max:** the ±15% text bounds and the ends of the reasoning range. Our tests confirm the max matches the docs' worked example exactly ($0.373875).
- The estimate is calculated on the server (`GET /generations/estimate`), so pricing logic exists in one place. The browser never does money maths.
- Characters are counted as Unicode code points on both the client and the server.

### Charging exactly once

Every update, whether it comes from the POST response, a webhook or polling, goes through `GenerationSync::apply()`. That method:

1. Locks the generation row (`SELECT … FOR UPDATE`).
2. Only moves the status **forward** (`pending → queued → processing → succeeded|failed`). Final states never change. A late `processing` event after `succeeded` is ignored.
3. Charges only on the single transition into `succeeded`, in the same transaction.
4. Is backed by a unique index on `credit_transactions(generation_id, type)`, so a second charge is impossible even if the code above had a bug.

Failed generations never create a charge.

### Webhooks

- The signature is HMAC-SHA256 over `"{t}.{raw body}"`, compared in constant time. Timestamps more than 300 seconds old are rejected to block replays. If no secret is configured, every webhook is rejected.
- Each event id is stored in `webhook_events` (unique). Recording the event and applying it happen in **one transaction**. If applying fails, the event row rolls back and we return 500, so Pixelforge's retry is processed instead of being dropped as a "duplicate".
- Our generation UUID is sent in `metadata`, and webhooks are matched on it first. This matters because a webhook can arrive **before** the POST response, while we don't yet know Pixelforge's `gen_` id.
- The route lives in the stateless `api` group (no session or CSRF) and responds quickly. The work is a few indexed DB writes.

### Submission and robustness

- Submission happens on the queue, so the request returns straight away. The card shows "Submitting" and then the reason for any wait, such as "Waiting for a free slot — retrying in 7s".
- The **Idempotency-Key is our generation UUID** and is reused on every retry. A timeout followed by a retry can't create or bill a second generation.
- Retryable errors are `rate_limit_exceeded`, `concurrency_limit_exceeded`, `overloaded`, 5xx responses and connection errors. They back off exponentially (capped at 60s, with jitter), never sooner than `Retry-After`. We give up after 15 minutes, and the user isn't charged.
- Errors that won't succeed on retry fail immediately with a clear "you were not charged" message: content policy rejections, validation errors, the daily limit and auth errors.
- Edge case: if we give up after timeouts but Pixelforge *did* create the generation, a later webhook reopens our record. The user gets the image, and our books match Pixelforge's.
- **Polling fallback:** every minute, `generations:sync` fetches any in-flight generation we haven't heard about for 45 seconds. It is capped per run to protect the 60 requests per minute limit. It also re-queues `pending` generations whose job disappeared, which is safe because of the idempotency key.

### Balances

- Each balance change is a row in `credit_transactions`, and `users.balance_nano` is the cached total. Both are updated in the same transaction.
- When a generation is submitted, its **worst-case** price is held against the balance. *Available* = balance − holds for in-flight generations.
- Submissions lock the user row, so two tabs can't spend the same credits.
- When a generation finishes, the hold disappears and the actual cost (which is ≤ the hold) is charged. A failure just releases the hold.
- "Buy credits" adds $1, $5, $10 or $25 as a fake purchase.

### Admin report

- `/admin/report` is available to users with `is_admin`. Grant it with `php artisan app:make-admin <email>`.
- It shows credit spend, provider cost and profit for each day and each user.
- Days are the **UTC day the generation completed**, because that's how `/v1/usage` bills. Each day is compared with `/v1/usage` and marked ✓ or ≠. Days that only Pixelforge has usage for are still shown, so a missing record can't hide.
- Use one API key per environment, so the deployed key's usage covers only the deployed app's generations.

### Abuse limits and security

- Pixelforge's limits apply to the whole **account**, which all users share: 3 generations in flight, 60 requests per minute and 500 generations per day. Demo credits are free, so without per-user caps one user could block everyone else and run up unlimited provider costs. The app enforces:
  - at most **3 generations in flight per user** and **50 per user per day**, both configurable and checked under the same row lock as the balance;
  - a **$50 cap on the credit balance**, checked under a row lock so simultaneous purchases can't get past it;
  - throttles on submitting (20/min), buying credits (10/min) and the estimate endpoint (120/min);
  - a per-run limit on how many generations the polling fallback fetches or re-queues.
- Each generation's price is bounded, and the worst case is held before submission, so a generation can never cost more than the user could afford.
- Users only ever see their own generations and transactions. The frontend receives only `id`, `name`, `email` and `is_admin` for the logged-in user. `balance_nano` and `is_admin` can't be set through form input.
- Image URLs are only stored if they're `https://`, because they're rendered as `src`/`href`.
- Secrets live only in `.env`, which is gitignored. They're never logged or sent to the browser.

### Things deliberately left out

- **Storing images:** Pixelforge URLs expire after 24 hours, and the brief says that's acceptable. Expired images show a clear placeholder. To keep them, download the image to S3 when the generation succeeds.
- **Real-time push:** the UI polls every 2 seconds, but only while something is in flight. WebSockets (Reverb) would be the upgrade.
- **Refunds and admin credit adjustments:** these would be new ledger entry types.

## Testing against the real API

Prompt keywords: `[succeed]`, `[fail]`, `[slow]`, `[duplicate]`, `[blocked]`, `[overloaded]`. In this app:

- `[blocked]` fails immediately and is free.
- `[overloaded]` shows a "retrying" notice and backs off.
- `[fail]` ends as *Failed · free*.
- `[duplicate]` is charged once.
- `[slow]` stays in *Processing* for 60–90 seconds.
