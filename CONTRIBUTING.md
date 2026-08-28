# Contributing

Thank you for looking. Larasan is in development and the schema still moves, so the most useful
contributions right now are bug reports with a reproduction, and pull requests that fix one thing.

Before anything large, open an issue. The repository documents its own decisions, and a change that
contradicts one of them is a conversation about the decision first and a diff second.

## Running it locally

Two ways, and neither is the "real" one. They differ in one file: `.env.example` reaches
PostgreSQL, Redis, Meilisearch and mail at `127.0.0.1`, and `.env.docker.example` reaches them at
the names `compose.yaml` gives those containers. Copy the one for the way you are working.

The hostnames are not set in `compose.yaml`, and that is deliberate: `php artisan serve` passes
only a fixed list of variables through to a request, so anything set there would reach an artisan
command and never the served application. `EnvExampleTest` fails if the two files stop declaring
the same keys.

### With Docker

Nothing but Docker required.

```bash
cp .env.docker.example .env
docker compose run --rm laravel.test composer setup
docker compose up -d
docker compose exec laravel.test php artisan db:seed --class=DevelopmentSeeder
docker compose exec laravel.test npm run dev
```

| | |
| --- | --- |
| http://localhost:8000 | the application |
| http://localhost:8025 | Mailpit — every message the application sends |
| http://localhost:8000/horizon | the queue |

`./vendor/bin/sail` wraps all of it: `sail up -d`, `sail artisan …`, `sail test`, `sail npm run
dev`. `compose.yaml` runs Horizon and Reverb as their own containers, so queued work and realtime
behave as they do in production. Meilisearch is search only — stop that container and the
application still serves, which is the point of ADR-0016.

### On the host

You need PHP 8.4, Node 20+, PostgreSQL, Redis and Meilisearch.

```bash
cp .env.example .env
composer setup
createdb -U pm pm && createdb -U pm pm_testing
php artisan db:seed --class=DevelopmentSeeder
composer dev
```

`composer dev` runs the server, the queue worker, Reverb and Vite together.

The suite runs against a **separate** database, `pm_testing`, on PostgreSQL rather than SQLite —
engine differences are caught here rather than in production. The Docker image creates it on first
boot; on the host, `createdb` above does.

## Before you open a pull request

```bash
composer ci:check
```

That is the whole gate: Pint, ESLint, PHPStan, `vue-tsc` and the suite. CI runs the same thing, so
a green local run is a green pull request. Under Docker it is
`docker compose exec laravel.test composer ci:check`, or `sail composer ci:check`. Individually:

| | |
| --- | --- |
| `vendor/bin/pint --dirty` | format the PHP you touched |
| `vendor/bin/phpstan analyse --memory-limit=2G` | static analysis, level 8 through Larastan |
| `npm run lint:check` | ESLint |
| `npm run types:check` | `vue-tsc --noEmit` |
| `php artisan test --compact` | the suite |

**Do not run `npm run format`.** It rewrites all of `resources/`, and this repository is not
prettier-clean — one run reformats around 65 files and buries a small change in a 1,400-line diff.
Format only what you edited: `npx prettier --write <files>`.

## What a change should look like

- **Tests are part of the change, not a follow-up.** A bug fix carries a regression test that is
  demonstrated to fail before the fix. A new domain slice carries the five kinds of test named in
  [`docs/conventions/testing.md`](docs/conventions/testing.md) — endpoint, Action, policy in both
  directions, workspace isolation with a valid id from another workspace, and the database
  constraint itself.
- **Logic goes in an Action or a Query, not a controller.** See
  [`docs/conventions/backend.md`](docs/conventions/backend.md).
- **No new dependency without a conversation.** The stack is fixed on purpose, and most additions
  turn out to be something the framework already does.
- **No `dd()`, `dump()`, `ray()` or stray `console.log`.** No commented-out code.
- **Comments carry what the code cannot** — an invariant, a vendor limitation, a security
  rationale. Not narration of the line below.

The rest is in [`docs/conventions/`](docs/conventions/). It is short, and reading it will save you
a review round.

## Commit messages

[Conventional Commits](https://www.conventionalcommits.org/), scoped by domain:

```
feat(tasks): add task creation action
fix(auth): enforce workspace isolation
test(tasks): cover dependency cycles
refactor(tasks): extract task detail query
docs(adr): record the ordering strategy
```

The subject says what changed. The body says why, when why is not obvious, and names the
verification that was run.

## Architectural decisions

`docs/adr/` holds the architectural decision records. If your change reverses one — for example by
putting `project_id` on `tasks`, which [ADR-0003](docs/adr/0003-task-project-membership.md)
forbids — the pull request needs a superseding ADR that argues the case. That is not a hurdle; it
is how the next person finds out why.

## Reporting a vulnerability

Not through an issue. See [SECURITY.md](SECURITY.md).
