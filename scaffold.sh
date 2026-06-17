#!/usr/bin/env bash
#
# scaffold.sh — Bootstrap the Corporate CMS Laravel 12 app from SPEC.md.
#
# WHAT IT DOES (idempotent — safe to re-run):
#   1. Verifies tooling (php 8.3+, composer, pnpm).
#   2. Installs Laravel 12 into this repo (preserving existing docs).
#   3. Installs the mandated stack: Inertia, Spatie Data, Scout/Meilisearch,
#      Pest + arch plugin, Larastan (level 9), Pint, React 19 + TS + Tailwind v4.
#   4. Lays down the DDD Lite directory skeleton (SPEC §5.4) with .gitkeep.
#   5. Writes the Tailwind v4 CSS-first theme (SPEC §1.5).
#
# WHAT IT DOES NOT DO:
#   Generate domain PHP (models/actions/DTOs/enums). That work needs a running
#   toolchain to verify (artisan, Pest, Larastan) — see BUILD-PLAN.md for the
#   ordered, dependency-aware sequence to do it in a supervised session.
#
# USAGE:  ./scaffold.sh        (from the repo root, with PHP/Composer/pnpm present)
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

info() { printf '\033[36m▶ %s\033[0m\n' "$*"; }
ok()   { printf '\033[32m✓ %s\033[0m\n' "$*"; }
die()  { printf '\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

# ── 1. Tooling ────────────────────────────────────────────────────────────────
info "Checking tooling..."
command -v php      >/dev/null || die "php not found (need PHP 8.3+, target 8.5)"
command -v composer >/dev/null || die "composer not found"
command -v pnpm     >/dev/null || die "pnpm not found (corepack enable pnpm)"
php -r 'exit(version_compare(PHP_VERSION,"8.3.0",">=")?0:1);' \
  || die "PHP $(php -r 'echo PHP_VERSION;') too old — need 8.3+ (target 8.5)"
ok "php $(php -r 'echo PHP_VERSION;'), composer $(composer --version --no-ansi | head -1), pnpm $(pnpm --version)"

# ── 2. Laravel 12 (preserve existing docs) ───────────────────────────────────
if [[ ! -f artisan ]]; then
  info "Installing Laravel 12 into a staging dir, then merging (keeps SPEC.md etc.)..."
  rm -rf .laravel-stage
  composer create-project "laravel/laravel:^12.0" .laravel-stage --no-interaction
  # Copy everything that doesn't already exist in the repo root (no-clobber).
  cp -rn .laravel-stage/. ./
  rm -rf .laravel-stage
  ok "Laravel 12 installed"
else
  ok "Laravel already present (skipping create-project)"
fi

# ── 3. Backend packages ───────────────────────────────────────────────────────
info "Installing backend packages..."
composer require --no-interaction \
  inertiajs/inertia-laravel \
  spatie/laravel-data \
  laravel/scout \
  meilisearch/meilisearch-php http-interop/http-factory-guzzle \
  tightenco/ziggy

info "Installing dev packages (Pest, Larastan, Pint)..."
composer require --no-interaction --dev \
  pestphp/pest pestphp/pest-plugin-laravel pestphp/pest-plugin-arch \
  larastan/larastan laravel/pint
ok "Composer packages installed"

# ── 4. Frontend packages (React 19 + TS + Tailwind v4) ───────────────────────
info "Installing frontend packages with pnpm..."
pnpm add -D \
  @inertiajs/react react@^19 react-dom@^19 \
  @vitejs/plugin-react typescript @types/react @types/react-dom \
  tailwindcss @tailwindcss/vite \
  vitest @testing-library/react @testing-library/jest-dom jsdom
ok "pnpm packages installed"

# ── 5. DDD Lite skeleton (SPEC §5.4) ─────────────────────────────────────────
info "Creating DDD Lite directory skeleton..."
DOMAIN_DIRS=(
  Identity/Models Identity/Enums Identity/Data Identity/Actions Identity/Policies Identity/Exceptions Identity/Events
  Organization/Models Organization/Data Organization/Actions Organization/Enums Organization/Rules Organization/Exceptions
  Content/Models Content/Data Content/Actions Content/Enums Content/Events Content/Exceptions Content/Services
  Jobs/Models Jobs/Data Jobs/Actions Jobs/Enums
  Engagement/Models Engagement/Data Engagement/Actions
  Membership/Models Membership/Data Membership/Actions Membership/Enums Membership/ValueObjects
  Analytics/Models Analytics/Data Analytics/Actions Analytics/Services
  Shared/Models Shared/ValueObjects Shared/Traits
)
for d in "${DOMAIN_DIRS[@]}"; do
  mkdir -p "app/Domain/$d"
  touch "app/Domain/$d/.gitkeep"
done

INFRA_DIRS=( Search Storage Notifications )
for d in "${INFRA_DIRS[@]}"; do mkdir -p "app/Infrastructure/$d"; touch "app/Infrastructure/$d/.gitkeep"; done

mkdir -p app/Http/Controllers/{Auth,Admin,Public} app/Http/Middleware
FRONT_DIRS=( Components Pages Layouts Types Utils Contexts Hooks tests )
for d in "${FRONT_DIRS[@]}"; do mkdir -p "resources/js/$d"; touch "resources/js/$d/.gitkeep"; done
mkdir -p resources/locales resources/images
ok "Skeleton created (app/Domain/*, app/Infrastructure/*, resources/js/*)"

# ── 6. Tailwind v4 theme (SPEC §1.5 — CSS-first, NO tailwind.config.js) ───────
info "Writing resources/css/app.css (Tailwind v4 theme)..."
mkdir -p resources/css
cat > resources/css/app.css <<'CSS'
@import "tailwindcss";

@source "../js/**/*.tsx";

@theme {
  /* Colors */
  --color-primary: #DD00FF;
  --color-primary-light: #E647FF;
  --color-primary-dark: #9211CF;
  --color-primary-50: #FDF0FF;
  --color-danger: #A03CC7;
  --color-danger-dark: #9211CF;

  /* Typography */
  --font-sans: "Inter", system-ui, sans-serif;
  --font-mono: "JetBrains Mono", monospace;

  /* Custom spacing */
  --spacing-18: 4.5rem;

  /* Animations */
  --animate-fade-in: fade-in 0.3s ease-in;
}

@keyframes fade-in {
  from { opacity: 0; transform: translateY(-4px); }
  to { opacity: 1; transform: translateY(0); }
}

@layer utilities {
  .text-gradient-primary {
    @apply bg-gradient-to-r from-primary to-primary-dark bg-clip-text text-transparent;
  }
}
CSS
ok "Tailwind theme written"

cat <<'NEXT'

────────────────────────────────────────────────────────────────────────────
 Scaffold complete. Next steps (see BUILD-PLAN.md):
   1. cp .env.example .env && php artisan key:generate
   2. docker compose -f docker-compose.dev.yml up -d
   3. php artisan migrate           (once migrations exist — Phase 1)
   4. Wire Inertia + React (php artisan inertia:middleware, app.tsx, vite.config.ts)
   5. Build domains in dependency order — BUILD-PLAN.md Phase 1.
 Verify continuously:  vendor/bin/pest  •  vendor/bin/phpstan  •  vendor/bin/pint
────────────────────────────────────────────────────────────────────────────
NEXT
