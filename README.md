# 🔥 Streakly

A self-hosted, GitHub-contribution-style habit/activity tracker built with
**Laravel 13 + Livewire 4 + Flux**, with **AI superpowers**. Multi-user (each
account has private data), mobile-first, dark/light mode, with a year-long
heatmap whose colour intensity grows with the points you log each day.

![stack](https://img.shields.io/badge/Laravel-13-red) ![php](https://img.shields.io/badge/PHP-8.4-blue) ![AI](https://img.shields.io/badge/AI-Claude%20%7C%20OpenAI-8A2BE2)

## Features

- **Accounts & login** — register / login / password reset / optional 2FA & passkeys (Fortify).
- **Daily logging** — quick-add chips for your activities, plus custom one-off entries.
- **✨ Log with AI** — type *"walked 30 min, read 20 pages, hit the gym"* and AI turns it into logged activities with points.
- **✨ AI insights** — a warm, personalised read on your streaks and momentum.
- **✨ Smart suggestions** — AI proposes new habits to track, tailored to what you already do.
- **Points & goal** — each activity is worth points; a daily goal drives the progress bar.
- **GitHub-style heatmap** — 53-week grid with month + weekday labels and 5 intensity tiers.
- **Stats** — today, current streak, best streak, this-month total, average, active days.
- **Manage activities** — create/edit/archive your own activity types with emoji icons.
- **Backup** — one-click JSON export of all your data.
- **Syncs across devices** — data lives in your database, not the browser.

New users are auto-seeded with a starter set (Walk, Workout, Diet, Reading…).

The AI features are **provider-agnostic** (Anthropic Claude **or** OpenAI) and
fully **opt-in** — leave the API key blank and every AI affordance hides itself.

## Local development

```bash
composer install
npm install
cp .env.example .env        # already created by the installer
php artisan key:generate
php artisan migrate
npm run build               # or: npm run dev  (hot reload)
php artisan serve
```

Visit http://127.0.0.1:8000 and register an account.

Config (in `.env`):

| Variable | Default | Meaning |
|---|---|---|
| `TRACKER_DAILY_GOAL` | `30` | Daily points target (progress bar + top heatmap tier) |
| `TRACKER_ALLOW_REGISTRATION` | `true` | Set `false` after creating your account to close sign-ups |

## ✨ AI configuration

AI is **off by default**. To enable it, pick a provider and add its key to `.env`:

```bash
# Anthropic Claude (default)
AI_PROVIDER=claude
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_MODEL=claude-opus-4-8   # or claude-haiku-4-5 for cheaper/faster

# …or OpenAI
AI_PROVIDER=openai
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4o-mini
```

| Variable | Default | Meaning |
|---|---|---|
| `AI_PROVIDER` | `claude` | Which provider powers AI features: `claude` or `openai` |
| `ANTHROPIC_API_KEY` / `OPENAI_API_KEY` | — | Provider API key. Blank ⇒ AI features hidden |
| `ANTHROPIC_MODEL` | `claude-opus-4-8` | Claude model id (Messages API) |
| `OPENAI_MODEL` | `gpt-4o-mini` | OpenAI chat model id |
| `AI_TIMEOUT` | `30` | Per-request timeout (seconds) |
| `AI_MAX_TOKENS` | `1024` | Max output tokens per AI call |

> 🔒 **Never commit your API key.** Keys live only in `.env`, which is git-ignored.

The integration is a thin, dependency-free service (`app/Services/Ai/AiService.php`)
using Laravel's HTTP client — no provider SDK required. See `tests/Feature/AiServiceTest.php`.

---

## 🚀 Deploying to Hostinger (shared hosting, with SSH + Composer)

Your plan has SSH + Composer, so this is straightforward.

### 1. Create the database (hPanel)
hPanel → **Databases → MySQL Databases**. Create a database + user and note the
**db name, user, password** (host is almost always `localhost`).

### 2. Get the code onto the server
SSH in, then either `git clone` your repo or upload the project. Recommended:
put the app **outside** `public_html` (e.g. `~/activity-tracker`) so source code
isn't web-accessible.

```bash
cd ~
git clone <your-repo> activity-tracker     # or upload via SFTP
cd activity-tracker
composer install --no-dev --optimize-autoloader
```

### 3. Build front-end assets
Shared hosting usually has **no Node.js**, so build locally and upload the result:

```bash
# on your Mac:
npm run build
# then upload the generated  public/build/  folder to the server
```
(If your plan *does* have Node, you can `npm install && npm run build` over SSH.)

### 4. Configure `.env`
```bash
cp .env.example .env
nano .env
```
Set at least:
```
APP_NAME="Streakly"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=your_db_name
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password

TRACKER_DAILY_GOAL=30
TRACKER_ALLOW_REGISTRATION=true

# Optional — enable AI features
AI_PROVIDER=claude
ANTHROPIC_API_KEY=sk-ant-...
```
Then:
```bash
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 5. Point the domain at `public/`
Laravel's web root is the `public/` folder, **not** the project root. Two options:

- **Best:** In hPanel set the domain's **document root** to
  `.../activity-tracker/public` (Hostinger lets you change this per domain).
- **Alternative:** Move the contents of `public/` into `public_html/` and edit
  the two `require` paths in `public_html/index.php` to point back to your app
  folder (`__DIR__.'/../activity-tracker/vendor/autoload.php'` etc.).

### 6. Permissions
```bash
chmod -R 775 storage bootstrap/cache
```

### 7. Lock it down
Once you've registered your own account, set `TRACKER_ALLOW_REGISTRATION=false`
in `.env` and run `php artisan config:cache` again so the public URL can't be
used to create new accounts.

### Updating later
```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
# upload a fresh public/build if assets changed
```

---

## How the heatmap colours work

Daily points are bucketed into 5 tiers relative to your goal (default 30):

| Points | Tier |
|---|---|
| 0 | empty |
| 1 – ~33% of goal | light green |
| ~33 – 66% | green |
| 66 – 99% | dark green |
| ≥ goal | brightest green |

## Tech notes

- Activity types are **archived**, never hard-deleted, so historical logs keep their name/points.
- All data is scoped to the authenticated user; cross-user access is prevented (see `tests/Feature/TrackerTest.php`).
- `log_date` is read with `DATE()` so the heatmap works identically on SQLite (local) and MySQL (prod).
