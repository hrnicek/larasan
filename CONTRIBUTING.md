# Contributing

Thank you for looking. Larasan is in development and the schema still moves, so the most useful
contributions right now are bug reports with a reproduction, and pull requests that fix one thing.

Before anything large, open an issue. The repository documents its own decisions, and a change that
contradicts one of them is a conversation about the decision first and a diff second.

## Running it locally

You need PHP 8.4, Node 20+, PostgreSQL, Redis and Meilisearch.

```bash
composer setup
```

That installs both dependency trees, copies `.env`, generates a key, migrates and builds. Create
the database it expects (`pm`), then:

```bash
php artisan db:seed --class=DevelopmentSeeder
composer dev
```

`composer dev` runs the server, the queue worker, Reverb and Vite together. The seeder prints the
credentials it created.

The test suite runs against a **separate** database, `pm_testing`, on PostgreSQL rather than
SQLite — engine differences are caught here rather than in production. Create it once:

```bash
createdb -U pm pm_testing
```

## Before you open a pull request

```bash
composer ci:check
```

That is the whole gate: Pint, ESLint, PHPStan, `vue-tsc` and the suite. CI runs the same thing, so
a green local run is a green pull request. Individually:

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
