# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Small to mid-sized product and delivery teams working inside a shared workspace. The primary
user is a team member who opens the app several times a day to answer one of three questions:
*what is mine today*, *where does this project stand*, and *what changed while I was away*.
Secondary: a workspace owner or admin who invites people, sets project access, and keeps the
structure tidy.

Roles are explicit, not implied: workspace membership carries a role that maps to capabilities
(ADR-0010), and project membership carries an access level (owner / editor / commenter / viewer,
ADR-0006) that is enforced separately.

## Product Purpose

**Larasan** is a multi-tenant project management application: workspaces contain projects,
projects contain tasks, and a task can appear in more than one project without being copied.
Success is a team that can see its work in a list, a board and a personal queue that all agree,
because the server is the single authority for domain state.

## Positioning

The structural decision a neighbouring tool cannot copy without rewriting itself: **a task is
owned by the workspace, not by a project.** Placement lives in `task_project_memberships`
(ADR-0003), so the same task genuinely appears in several projects, in different sections, with
its own ordering in each — one record of the work, many views of it.

Two supporting positions: **sections are user-defined groupings, not a hidden status enum**
(ADR-0004), and **realtime is a collaboration transport, never the source of truth**
(ADR-0008) — a dropped websocket degrades presence, never data.

## Operating Context

Daily use is dense and keyboard-adjacent: scanning task rows, dragging cards between sections,
opening a task in a modal over the list it came from (ADR-0013), and clearing an inbox of
notifications. Surfaces in the app today:

| Surface | Route / page | Job |
| --- | --- | --- |
| App shell | `AppShell`, `AppTopbar`, `AppSidebar` | Navigation, search (⌘K), create menu, workspace switch |
| Project list | `projects/Index`, `projects/Show` | The project's tasks as a table of rows |
| Project board | `projects/Show` (board view) | Sections as columns, tasks as cards |
| Task detail | `tasks/Show` | Full task: description, comments, files, custom fields, dependencies |
| My tasks | `my-tasks/Index` | The personal queue across every project |
| Inbox | `inbox/Index` | Notifications and what changed |
| Search | `search/Index` | Postgres full-text across the workspace (ADR-0012) |
| Settings | `settings/*` | Profile, security (2FA, passkeys), appearance, workspace, members |

## Capabilities and Constraints

Implemented: workspaces and memberships, projects, sections, tasks with subtasks, dependencies,
followers, comments, attachments, tags, custom fields, my-tasks, inbox, search, notifications,
Fortify auth with 2FA and passkeys, PWA manifest, light/dark/system appearance persisted per user.

Constraints that bind future work:

- Stack is fixed and not up for renegotiation: Laravel 13, Inertia v3, Vue 3.5, TypeScript,
  Tailwind CSS 4, reka-ui / shadcn-vue primitives, Vite 8, PostgreSQL, Redis, Reverb.
- No new composer or npm dependency without explicit approval.
- Route URLs come from Wayfinder, never hardcoded strings.
- Permissions are rendered from what the server sends; the UI never decides authorization.
- Workspace isolation is a hard boundary on read and on write; a leaked UUID is still rejected.

Explicitly out of initial scope (`docs/architecture/domains.md`): billing, native apps,
portfolios, goals, workload planning, automation builder, AI features, Gantt editing,
dashboard builders, multi-assignee tasks, offline sync.

## Brand Commitments

- The name is **Larasan** — *laras*, to be in tune, and *-an*, the thing brought into tune —
  written down in exactly one place (`APP_NAME`); the manifest, the shell and the browser tab all
  read it from there.
- The mark is three slabs of unequal length with slanted ends: gamelan keys in tune, and task
  bars on a timeline (`resources/js/components/AppLogoIcon.vue`, `public/favicon.svg`).
- The visual language is settled in `docs/adr/0014-brand-and-visual-language.md`: dark chrome
  in both themes, plum accent at hue 330, Instrument Sans, no new font package.
- Voice: material and unfussy. Short, literal labels ("unassigned", not an empty box). The
  product does not chatter at the user.

## Evidence on Hand

Real, in-repo: seven UI specs in `docs/ui/`, seven architecture documents in
`docs/architecture/`, fourteen accepted ADRs in `docs/adr/`, and the working application itself
at `http://pm.test`. No customers, testimonials, pricing, benchmarks, press or case studies
exist — future work must not invent any.

## Product Principles

1. **The server is authoritative.** Optimistic UI only where rollback is obvious (a card moving
   on a board); never for destructive or security-sensitive operations.
2. **One task, many placements.** Any feature that would force a task to belong to exactly one
   project is working against the product.
3. **Density is a feature.** These screens are read dozens of times a day; scanability outranks
   expression on every operate surface.
4. **Colour decorates, text means.** No application logic reads a colour; anything that carries
   meaning pairs colour with a word or an icon.
5. **Both themes are first-class.** Nothing is designed in one theme and merely checked in the
   other.

## Accessibility & Inclusion

WCAG AA contrast in both themes; every interactive element keyboard-reachable with a visible
focus ring on the `ring` token; icon-only buttons carry accessible names; drag and drop always
has a keyboard-operable alternative.
