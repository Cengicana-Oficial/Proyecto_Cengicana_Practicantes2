# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A monorepo of PHP + MySQL modules for CENGICAÑA, sharing one central login/permissions system. There is no framework (no Laravel/Symfony), no autoloader-driven routing, no build step, and no automated test suite — modules are plain PHP scripts included directly by the web server, mostly procedural with some small classes.

The confirmed production scope is **`login/` + `cengicursos/`**. `Pruebas/` (visit-request system), `laboratorio/`, and `sistema_de_solicitudes/` are additional modules wired into the same login/menu but are earlier-stage or lower-priority — see `AUDITORIA_PROYECTO_CENGICANA.md` (gitignored, local-only) for the full audit and rationale if it's present in your checkout.

## Running it

There's no build/lint/test command — this is deployed straight as PHP source. Two ways to run it locally:

**Docker (root compose file, current setup):**
```
docker compose -f docker-compose.prod.yml up --build
```
This builds `dockerfiles/modulos-app/Dockerfile` (PHP 8.2 + Apache, `mysqli`/`pdo_mysql`/`pdo_pgsql`/`zip` extensions) and a `mysql:8.0` container, seeded from `deploy/mysql/init/*.sql` (schema) and optionally `deploy/mysql/seed/*.sql` (dummy data — not auto-mounted, add it to the init volume manually if needed). App is served on `127.0.0.1:8085`, MySQL on `127.0.0.1:33063`. Per-module `.env` files are bind-mounted from `deploy/env/*.env` (copy the matching `*.env.example` files to create them — see `deploy/env/`).

**XAMPP (older modules, e.g. `sistema_de_solicitudes/`):** point Apache's docroot at the repo, start MySQL, import the relevant `database/schema.sql` / `deploy/mysql/init/*.sql` via phpMyAdmin/Workbench, and open the module path directly (e.g. `http://localhost/sistema de solicitudes/`). See `sistema_de_solicitudes/README.md` for that module's specific `.env` search order and defaults.

There's also a legacy `cengicursos/docker-compose.yml` (PHP 7.1 / MySQL 5.7 / Nginx) and a `_Respaldo/` backup folder inside `cengicursos/` — these are old/superseded, not the current deployment path.

`composer install` runs per-module (`login/composer.json`, `Pruebas/composer.json`), not at the repo root — each module has its own `vendor/`.

## Architecture

### Central login owns identity, each module owns its own data

`login/` is the single source of truth for `usuarios`, `roles`, `permisos`, `rol_permiso`, `modulos`, and `usuario_modulo`, backed by the `usuarios_menu` database. Every other module connects to *two* databases: its own (e.g. `cengi_cursos` for `cengicursos`) for domain data, and `usuarios_menu` (read-only, for identity/permissions) for auth. This split connection pattern is repeated with slightly different code in each module — see `cengicursos/conexion.php` (`conectar()` vs `conectar_usuarios_menu()`), `Pruebas/config/conexion.php` (`Conexion::conectar()` vs `conectarUsuariosMenu()`), and `laboratorio/config/conexion.php`. When touching connection/env code in one module, check whether the same fix is needed in the sibling modules — they diverged rather than sharing a library.

### Env file resolution is layered and module-specific

Each module reads its own `.env` (via `vlucas/phpdotenv` or a hand-rolled parser) and *also* reads `login/.env` (or `LOGIN_DB_*` / `DB_MENU_*` variables) to reach the shared `usuarios_menu` database. The exact fallback order differs per module (compare `cengicursos/conexion.php`'s `cengicursos_env()` against `Pruebas/config/conexion.php`'s `loginEnv()` against the search paths documented in `sistema_de_solicitudes/README.md`). Don't assume one module's env-loading rules apply to another — check that module's own connection file.

### Session and permission flow

1. `login/login.php` authenticates against `usuarios_menu` and populates `$_SESSION` (`id_usuario`, `rol`, `rol_id`, `es_superadmin`, `ingenio_id`, `modulos`, `user_permissions`).
2. `login/Menu.php` is the post-login landing page: it lists modules the user is linked to (`usuario_modulo`) and routes each module name to a hardcoded path (`menu_modulo_meta()` in `login/Menu.php`) — adding a new module means adding a branch there.
3. Each module re-derives/refreshes its own view of the session on every request. In `cengicursos`, `revisar_permisos.php` is included at the top of nearly every page: it starts/validates the session, reloads the user from `usuarios_menu` via `cengi_cargar_usuario_actual()`, and exposes a large set of `cengi_puede_*()` / `cengi_require_*()` helper functions used as guards (e.g. `cengi_require_aprobar_solicitudes()`). Follow this same pattern (check-then-guard via a `cengi_puede_*`/`cengi_require_*` helper) when adding new protected actions in `cengicursos`, rather than inlining permission checks.
4. `es_superadmin = 1` bypasses all permission checks everywhere — always check for it before assuming a `cengi_tiene_permiso()` / `usuario_puede_permiso()` call is the deciding factor.
5. Permission *names* are centrally defined and seeded in `login/config/permisos_roles.php` (`sembrar_permisos_base()`), namespaced by module prefix/suffix convention: `*_cengi` (cursos), `laboratorio.*`, `solicitudes_internas.*`, unprefixed names for visits/pagos/usuarios. When adding a new permission, add it there so it gets seeded, and extend `clasificar_grupo_permiso()`/`etiqueta_permiso()` in the same file so it displays correctly in the roles UI.

### Row-level scoping by ingenio

`cengicursos` restricts non-admin users to their own `ingenio_id` (sugar mill) via `cengi_scope_sql()` / `cengi_scope_sql_por_nombre_ingenio()` in `cengicursos/conexion.php`, which build a SQL fragment (`AND alias.ingenio_id = N` or `1=0` if the user has no ingenio) to append to queries. Any new query listing cross-ingenio data needs one of these appended, or it will leak data across ingenios for non-admin roles.

### `cengicursos` has a second, legacy auth path

`cengicursos/Login_v6/`, `Lou_login.php`, and `classes/auth/Lou_login.class.php` are an older, separate login mechanism from before the central `login/` module existed, some still using `md5()` password hashing. Don't extend these — new work should go through `login/` + `revisar_permisos.php`. If you're asked to touch auth in `cengicursos`, confirm which path (old or central) the specific page you're editing actually uses before changing it.

### Secrets and generated content

`.env` files, `deploy/env/*.env`, `vendor/`, `node_modules/`, and `laboratorio/.env` are gitignored — never commit real credentials, only update the matching `*.env.example`. `AUDITORIA_PROYECTO_CENGICANA.md` is also gitignored (local audit notes, not shipped). `Pruebas/uploads/` and `laboratorio/outputs/` hold user-submitted/generated files and are excluded from Docker builds via `.dockerignore` but are currently tracked in git under `Pruebas/uploads/` — be careful not to add more real uploaded documents to a commit.
