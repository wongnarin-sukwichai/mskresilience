# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

ระบบบริหารข้อมูลความเสี่ยงและความเปราะบางของชุมชน (community risk & vulnerability data management system) for อำเภอเมือง, จังหวัดมหาสารคาม. A Laravel 12 + Inertia.js v2 + Vue 3 SPA, built on the official `laravel/vue-starter-kit`.

- Frontend: Vue 3 (`<script setup lang="ts">`), TypeScript, Tailwind CSS, shadcn-vue style UI components.
- Backend: Laravel 12, Inertia (server-driven routing, no separate REST/API layer for pages), Ziggy for route helpers in JS.
- Map: Leaflet (npm package, not CDN) — see `resources/js/pages/Welcome.vue` for the reference implementation (custom `L.divIcon` markers, per-risk `L.featureGroup` layers, tile-style toggle).
- Font: Anuphan everywhere (loaded via Google Fonts in `resources/views/app.blade.php`, set as `font-sans` in `tailwind.config.js` / `resources/css/app.css`). Icons: FontAwesome Free, installed as an npm package and imported in `resources/css/app.css` (not CDN).
- SPA behavior comes from Inertia itself — there is no `vue-router`. Navigation is server-routed (`routes/*.php` → `Inertia::render('PageName')`) but rendered client-side without full page reloads.

## Commands

```bash
composer install && npm install       # install deps
cp .env.example .env && php artisan key:generate

composer dev                          # run server + queue listener + pail logs + vite, concurrently (recommended for local dev)
php artisan serve                     # backend only
npm run dev                           # vite dev server only
npm run build                         # production build (also required before `npm run build:ssr`)

vendor/bin/pint                       # PHP formatting (run before committing PHP changes)
npm run format                        # Prettier (resources/)
npm run lint                          # ESLint --fix (resources/)

php artisan test                      # run all tests (or: vendor/bin/phpunit)
php artisan test --filter=test_name   # run a single test
php artisan test tests/Feature/DashboardTest.php
```

Tests are plain PHPUnit test classes under `tests/Feature` and `tests/Unit` (not Pest syntax, despite `tests/Pest.php` existing as an unused starter-kit leftover — `pestphp/pest-plugin` is not in `composer.json`). Tests run against in-memory SQLite (`phpunit.xml`); local dev DB is MySQL (`.env`, XAMPP).

CI (`.github/workflows/`) runs `vendor/bin/pint`, `npm run format`, `npm run lint` on push/PR (lint.yml), and `phpunit` + `npm run build` (tests.yml).

## Architecture

**Page routing**: `routes/web.php`, `routes/auth.php`, `routes/settings.php`, `routes/admin.php` define server routes that return `Inertia::render('PageName')`, resolving to `resources/js/pages/PageName.vue` (path relative to `pages/`, e.g. `Inertia::render('settings/Appearance')` → `resources/js/pages/settings/Appearance.vue`). There are no Blade views for actual pages — `resources/views/app.blade.php` is the single Inertia shell (fonts, `@vite`, `@inertia`), and `resources/views/example.blade.php` is a static HTML/JS mockup kept only as a design reference (it predates `Welcome.vue` and is not served by any route).

**`routes/admin.php`**: all `/dashboard`-family routes, under `['auth', 'verified']` plus per-route `role:` gating (dashboard viewing is `role:admin,staff`; account management is `role:admin` only — see "Roles" below). `App\Http\Controllers\Admin\*` and `resources/js/pages/admin/*.vue` are the corresponding namespace/folder — put new back-office pages there, gated the same way, rather than adding another top-level routes file. New admin pages: add the route here, add the nav entry in `AdminLayout.vue`'s `navItems` (role-conditionally if the route isn't open to both admin and staff — see how `จัดการสมาชิก` only shows for `admin`), and use `AdminLayout` as the page's layout.

**Entry point**: `resources/js/app.ts` boots Inertia + Ziggy, resolves pages via `resolvePageComponent` globbing `./pages/**/*.vue`.

**Auth/session props**: `app/Http/Middleware/HandleInertiaRequests.php` shares props (e.g. `auth.user`) available in every page via `$page.props` / `usePage()`.

**Auth policy — self-registration is disabled.** `routes/auth.php` has no `register` routes (removed intentionally); `RegisteredUserController` and `pages/auth/Register.vue` still exist in the codebase but are unrouted/unlinked. Accounts are provisioned by an admin via `php artisan db:seed` (`database/seeders/DatabaseSeeder.php` seeds `test@example.com` / `password` as `member`, plus the real admin account below — replace/remove the test account before any real deployment). Login/logout/password-reset/email-verification all work normally through the standard Laravel session guard. `Welcome.vue`'s login modal (email+password, "ลืมรหัสผ่าน?" switches the same modal to a password-reset-request form) posts to these same routes via Inertia's `useForm` — it is a real auth client, not a mock. The modal's "เข้าระบบด้วยบัญชี @gmail" button is intentionally disabled (`disabled`, no handler) — Google/Socialite login was explicitly descoped, not forgotten; wiring it up needs `composer require laravel/socialite`, real Google OAuth credentials in `.env`, and a decision on whether Google sign-in may auto-create new accounts (it should probably match the same "no self-service account creation" policy as password login, but that's a product decision to confirm, not assume).

**Roles**: `App\Enums\UserRole` (`admin` | `staff` | `member`), stored as a plain `string` column on `users` (`role`, default `member`), cast to the enum on the `User` model. Route-level gating uses the `role:` middleware alias (`App\Http\Middleware\EnsureUserHasRole`, registered in `bootstrap/app.php`) — e.g. `Route::...->middleware(['auth', 'verified', 'role:admin,staff'])`. `User::canAccessAdminPanel()` is the **single source of truth** for "can this user reach `/dashboard`" — both `AuthenticatedSessionController::store()` (post-login redirect) and `AppServiceProvider::boot()` (`RedirectIfAuthenticated::redirectUsing(...)`, for the `guest` middleware's "already logged in" redirect on `/login`) call it, and must keep calling it rather than re-deriving the role check inline. **This split was a real bug once**: Laravel's default `guest`-middleware redirect always targets `route('dashboard')` if it exists, with no regard for role — an already-logged-in member hitting `/login` (e.g. to switch accounts) got bounced straight into a 403 with their new credentials never even processed, and no visible way to log out to recover, since `Welcome.vue`'s header didn't show auth state at all. Both are now fixed (role-aware redirect + `Welcome.vue` shows a user chip/logout when `auth.user` is set) — if you add another role-gated route, ask whether the same two spots need updating. Seeded accounts: `test@example.com` / `password` (member), `wongnarin.s@msu.ac.th` / `w123` (admin, real credentials requested by the project owner — rotate before production).

**User management** (`/users`, admin-only): `App\Http\Controllers\Admin\UserManagementController` + `resources/js/pages/admin/Users.vue`. Since self-registration is disabled, this is the only way new accounts get created — plain CRUD (no pagination yet; revisit if the user list grows large). `guardLastAdmin()` in the controller blocks deleting or demoting the system's sole remaining admin (returns a 403 with a Thai message); the destroy action separately blocks deleting your own account. If you touch this controller, keep both guards — they protect against different lockout scenarios (see the tests in `tests/Feature/Admin/UserManagementTest.php` for why the "delete" guard and "demote" guard can't be collapsed into one check: destroy always requires the actor to already be an admin, so "delete the last admin" and "delete yourself" overlap in practice, but demotion doesn't have that overlap).

**Locale**: `APP_LOCALE=th` (fallback `en`) — `lang/th/auth.php` translates the auth-guard error strings (`auth.failed`, `auth.throttle`) since the whole UI is Thai. Only that one file exists under `lang/th/`; other framework validation/auth strings still fall back to English if triggered. Extend `lang/th/*.php` as more server-side user-facing strings come up rather than leaving them in English.

**Layouts**: `resources/js/layouts/` (`AppLayout`, `AppHeaderLayout`, `AppSidebarLayout`, `AuthLayout` and variants) wrap authenticated/settings pages. Full-screen/standalone pages like `Welcome.vue` intentionally render with no layout wrapper.

**UI components**: `resources/js/components/ui/*` are shadcn-vue primitives (Button, Dialog, Sidebar, etc.) — see `components.json` for the shadcn-vue config (aliases, Tailwind base color `neutral`). Don't hand-roll a component if an equivalent already exists there.

**Map pages** (pattern established in `Welcome.vue`): keep the Leaflet `L.Map` instance and layer/tile-layer objects as plain module-scope variables (`let`), *not* `ref`/`reactive` — Leaflet manages its own internal mutable state and wrapping it in Vue's reactivity is unnecessary and can misbehave. Always `map.remove()` in `onUnmounted` since Inertia navigation mounts/unmounts page components without a full browser reload, and Leaflet throws if you re-`L.map()` the same container without cleanup.

**Body-level page styles**: pages needing full-viewport/no-scroll layouts (like `Welcome.vue`) toggle a class on `document.body` in `onMounted`/`onUnmounted` rather than setting it globally in CSS, since multiple Inertia pages share one `<body>` across navigations.

**Sourcing real geodata** (pattern established in `Welcome.vue`): don't invent coordinates/boundaries and present them as real. Administrative boundary polygons (province/district/municipality) can be pulled live and legitimately via Nominatim (`https://nominatim.openstreetmap.org/search?...&format=geojson&polygon_geojson=1`, © OpenStreetMap contributors, ODbL — attribute it). Official place/community names and counts for this municipality come from `data.mkm.go.th` (เทศบาลเมืองมหาสารคาม's own open-data portal), not Wikipedia (which was found outdated: 30 vs. the current official 32 ชุมชน). Sub-municipal "ชุมชน"-level boundaries are not published anywhere as open geodata — when real names/boundary exist but per-point coordinates don't, scatter placeholder points inside the real boundary and say so in a code comment rather than fabricating precision that doesn't exist.

**Shared community/boundary data**: `resources/js/data/mahasarakham.ts` is the single source of truth for `MAP_CENTER`, `MUNICIPALITY_BOUNDARY`, `riskColors`, `riskNameTH`, and the 32-entry `communities` array (see the "Sourcing real geodata" note above for what's real vs. placeholder in there). Both `Welcome.vue` and the admin `Dashboard.vue` mini-map import from it — **don't re-declare this data in a new page; import it**, so the ~150-point boundary polygon and community list stay in exactly one place.

**Admin panel** (`resources/js/layouts/AdminLayout.vue` + `resources/js/pages/Dashboard.vue`, gated `role:admin,staff`): a bespoke dark-sidebar shell (not the shadcn `AppSidebarLayout` — different visual language, built custom like `Welcome.vue`). The sidebar nav list in `AdminLayout.vue` includes items for pages that don't exist yet (จัดการชุมชน, จุดเสี่ยง, ชั้นข้อมูลแผนที่, ...) — they intentionally render as inert `<div>`s (not `<Link>`/`<a>`), not broken links; only "แดชบอร์ด" is a real `Link`. When building one of those pages, promote its nav entry to `{ label, icon, href: route('...') }` so it becomes a real `Link` (see how "แดชบอร์ด" is wired) rather than adding a parallel nav system. `resources/js/components/admin/{DonutChart,TrendChart,StatCard}.vue` are hand-rolled SVG charts (no charting library dependency) — reuse them for future admin pages instead of adding Chart.js/ApexCharts/etc. **Everything on the dashboard except the stat card "ชุมชนทั้งหมด" (real, from `communities.length`) and the map is mock data** — there's no `risk_points`, `alerts`, or activity-log table yet. When those tables exist, replace the mock arrays in `Dashboard.vue` with real Inertia props from a controller; don't leave the mock data silently stale once a real source exists.
