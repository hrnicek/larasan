<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/brand/larasan-banner-dark.png">
    <source media="(prefers-color-scheme: light)" srcset="docs/brand/larasan-banner-light.png">
    <img alt="Larasan" src="docs/brand/larasan-banner-light.png" width="720">
  </picture>
</p>

<p align="center">
  <a href="#license"><img alt="MIT licensed" src="https://img.shields.io/badge/license-MIT-8C2A87"></a>
  <img alt="PHP 8.4" src="https://img.shields.io/badge/php-8.4-8C2A87">
  <img alt="Laravel 13" src="https://img.shields.io/badge/laravel-13-8C2A87">
  <img alt="Status: in development" src="https://img.shields.io/badge/status-in%20development-6b6470">
</p>

---

**Larasan** is a project manager for small product and delivery teams. It answers three
questions, several times a day: *what is mine today*, *where does this project stand*, and
*what changed while I was away*.

The name is Javanese and Indonesian — *laras*, tuning, plus *-an*, the thing brought into tune.
A project manager does not store work. It brings a group of people into agreement about what the
work is and what order it happens in.

> **Status: in development.** There is no tagged release yet and the schema still moves. It runs,
> it is tested, and it is not ready to hold anything you would miss.

## What makes it different

**A task is owned by the workspace, not by a project.** Placement lives in its own table, so the
same task genuinely appears in several projects — in different sections, with its own ordering in
each. One record of the work, many views of it. Neighbouring tools cannot copy that without
rewriting themselves.

Two positions follow from it:

- **Sections are groupings you name, not a status enum in disguise.** A column called *Waiting on
  legal* is a real answer, and no code branches on it.
- **Realtime is a transport, never the source of truth.** A dropped websocket degrades presence.
  It never degrades data.

## What is in it

Workspaces and memberships · projects with per-project access levels · sections · tasks with
subtasks, dependencies, followers, comments, attachments, tags and custom fields · a list view and
a board view that agree · a personal queue across every project · pages, as documents that hang
from a project · an inbox of what changed · search across five kinds of thing behind `⌘K` ·
authentication with two-factor and passkeys · an installable PWA · light, dark and system
appearance, persisted per person.

Deliberately out of scope for now: billing, native apps, portfolios, goals, workload planning, an
automation builder, AI features, Gantt editing, dashboard builders, multi-assignee tasks and
offline sync.

## Stack

| | |
| --- | --- |
| Backend | PHP 8.4, Laravel 13, a modular monolith with a domain layer |
| Frontend | Inertia v3, Vue 3.5, TypeScript, Tailwind CSS 4, reka-ui / shadcn-vue, Vite 8 |
| Data | PostgreSQL, Redis, Meilisearch via Laravel Scout |
| Realtime | Laravel Reverb, queues supervised by Horizon |
| Auth | Laravel Fortify, with TOTP and passkeys |
| Tests | Pest 5, PHPStan level 8 through Larastan, Pint |

Route URLs reach the frontend through Wayfinder rather than as strings, and the server decides
every permission the UI renders.

## Running it locally

With Docker, nothing else is needed:

```bash
git clone https://github.com/jakubhrncir/larasan.git
cd larasan
cp .env.docker.example .env

# Installs both dependency trees, generates a key and migrates. The database, Redis and
# Meilisearch start first and are waited for; the application container is not needed yet.
docker compose run --rm laravel.test composer setup

docker compose up -d
docker compose exec laravel.test php artisan db:seed --class=DevelopmentSeeder
docker compose exec laravel.test npm run dev
```

The application is at http://localhost:8000, and the mail it sends — invitations, password
resets — at http://localhost:8025. The seeder prints the credentials it created.

After `alias sail='./vendor/bin/sail'` those become `sail up -d`, `sail artisan db:seed …` and
`sail npm run dev`. `compose.yaml` also runs Horizon and Reverb, so queued work and realtime
behave as they do in production.

Without Docker you will need PHP 8.4, Node 20+, PostgreSQL, Redis and Meilisearch on the host:

```bash
composer setup
createdb -U pm pm && createdb -U pm pm_testing
php artisan db:seed --class=DevelopmentSeeder
composer dev
```

`composer dev` runs the server, the queue worker, Reverb and Vite together. One `.env` serves
both ways: the container sets the hostnames it needs as environment variables, and Laravel's
dotenv loading leaves a real environment variable alone.

## Tests

```bash
php artisan test --compact
```

The suite runs against PostgreSQL rather than SQLite, so engine differences are caught here rather
than in production. `composer ci:check` runs the whole gate: lint, formatting, types, and the
suite.

## Contributing

Start with [CONTRIBUTING.md](CONTRIBUTING.md). Two things worth knowing before you open anything:

- **The repository documents its own decisions.** [`docs/adr/`](docs/adr/) holds the architectural
  decision records, [`docs/architecture/`](docs/architecture/) the shape of the system,
  [`docs/conventions/`](docs/conventions/) how code is written here, and
  [`docs/ui/design-system.md`](docs/ui/design-system.md) the visual rules. If a change contradicts
  one of those, the ADR is the thing to argue with first.
- **No new dependency without a conversation.** The stack is fixed on purpose, and most additions
  turn out to be something the framework already does.

## Brand

The mark, the wordmark and the assets are in [`docs/brand/`](docs/brand/), with the rules for
using them. The reasoning behind the name and the mark is
[ADR-0018](docs/adr/0018-name-and-mark-larasan.md); the colour, typography and elevation system is
[ADR-0014](docs/adr/0014-brand-and-visual-language.md).

Larasan is an independent project. It is built on Laravel and is not affiliated with, endorsed by,
or sponsored by Laravel.

## License

MIT. See [LICENSE](LICENSE).
