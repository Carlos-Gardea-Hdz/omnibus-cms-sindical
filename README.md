# Corporate CMS — Plataforma Empresarial

Laravel 12 rebuild of a corporate content-management platform (OMNIBUS Proyecto A,
Biblia de Laravel chapters 3.1–3.3). Whitelabeled rebrand of the legacy union/CMS
system; the GitHub repo slug remains `omnibus-cms-sindical` for history.

| | |
|---|---|
| **Status** | 🏗️ Early build — Laravel 12 scaffold + landing page (the CMS domains are specified, not yet implemented) |
| **Production** | `cms.carlosgardea.com` (not live) |
| **Staging** | `cms.carlosgardea.cloud` (landing-only shell on SQLite) |
| **Stack** | PHP 8.5 · Laravel 12 · PostgreSQL 18 · Valkey · Meilisearch · React 19 + TypeScript + Inertia 2 · Tailwind v4 · Pest · Docker + Traefik |

## Documentation

- **[`SPEC.md`](./SPEC.md)** — authoritative product specification (Single Source of Truth).
- **[`AGENTS.md`](./AGENTS.md)** — canonical rulebook for AI agents and contributors,
  plus the honest current state of the code.
- **[`NEXT_STEPS.md`](./NEXT_STEPS.md)** — verified gate results, known gaps, and the
  gap-to-done checklist toward merge-readiness.
- **[`CLAUDE.md`](./CLAUDE.md)** — pointer for Claude Code.

## Local development

```bash
cp .env.example .env
composer install
pnpm install
./vendor/bin/sail up -d          # PostgreSQL 18 + Valkey/Redis + Meilisearch + Mailpit
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
pnpm dev
```

> Uses **pnpm**, never npm. Note: the composer `setup`/`dev` scripts still reference
> `npm`/`npx` and are pending a pnpm fix (tracked in `NEXT_STEPS.md`).

## Quality gates

```bash
composer test     # Pest
composer format   # Laravel Pint
pnpm exec tsc --noEmit
pnpm build
```

## License

MIT — see [`LICENSE`](./LICENSE).
