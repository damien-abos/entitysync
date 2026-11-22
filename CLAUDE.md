# CLAUDE.md

Context for Claude Code working on this repository.

## Project

GLPI 11 / 12 plugin `entitysync`: synchronizes the GLPI entity tree below a target entity
from a CSV file. An import model defines levels (key columns, name column, regex
replacement rules, comment columns, placeholder name for empty values) and an
AND / OR / NOT filter on rows. An upload creates an import job with a paginated
preview (additions, links, updates, deletions) where the user selects what to apply;
the job is then applied by batches by the GLPI automatic action `entitysyncApply`. Full behaviour:
`docs/en/user-guide.md`, `docs/en/installation.md`; architecture and GLPI notes:
`docs/developer.md` (read it before non-trivial changes).

## Commands

```sh
make check          # lint (PHP, JS, shell) + unit tests + translation checks: run before finishing
make test           # engine unit tests only (php tests/run.php [filter])
make e2e            # end-to-end scenario against the GLPI stack (after make glpi-up)
make locales        # after adding or changing a user-visible string
make package        # dist/entitysync-<version>.tar.gz
make glpi-up        # build/start GLPI + MariaDB (docker/compose.yml), install the plugin
make glpi-reset     # delete the stack's data (needed to change GLPI_VERSION)
```

GLPI and MariaDB run in Docker (`docker/compose.yml`, image in `docker/glpi/`, driven by
`tools/glpi.sh`), in the dev container (docker-in-docker) and in the CI. GLPI on
http://localhost:8080 (glpi / glpi), installed in `/var/www/glpi` in its container; run
commands there with `tools/glpi.sh exec ...` (console: `glpi-console`). Logs in
`docker/log/` (`php-errors.log` must stay clean). GLPI's cron runs every minute; to apply
a queued job at once: `tools/glpi.sh exec php /var/www/glpi/front/cron.php --force entitysyncApply`.
After install/schema changes: `tools/glpi.sh exec glpi-console plugin:install entitysync -u glpi --force`.
The repository is mounted as `/var/www/glpi/plugins/entitysync`, so PHP/Twig/JS changes
apply immediately.

There is no Composer dependency: the unit tests use the small runner in
`tests/run.php`. Do not add PHPUnit or other packages without asking.

## Layout

- `src/Engine/` — pure PHP core (CSV, filter, tree, planner, applier). **Must not use
  GLPI classes or functions.** All GLPI access goes through `src/GlpiGateway.php`
  (implements `Engine\Sync\GatewayInterface`).
- `src/Model.php` (model item, form, tabs, JS labels), `src/ImportController.php`
  (upload → preview → queue → status, AJAX handlers), `src/Job.php` (job + automatic
  action), `src/JobRunner.php` (batches), `src/JobStorage.php`, `src/PlanFactory.php`,
  `src/Link.php`, `src/Run.php`, `src/ProfileRights.php`.
- `ajax/` JSON endpoints (preview pages, selection, job status).
- `setup.php` (version, hooks), `hook.php` (install/uninstall, schema).
- `front/` controllers, `templates/` Twig (`@entitysync/...`), `public/` JS/CSS.
- `tests/Engine/` unit tests, `tests/e2e/` end-to-end scenario and fixtures.
- `docker/` GLPI + MariaDB stack (dev and CI). `tools/` GLPI stack script, locales
  extraction, packaging. `docs/` documentation.

## Invariants (do not break)

- Identity of an entity = its key path (JSON list of level keys, `Engine\Tree\Path`);
  links per model in `glpi_plugin_entitysync_links`.
- Only entities linked to the model can be updated or deleted. Never propose deleting
  an entity of a row in error (protected paths).
- Deletions are never selected by default; a deletion is blocked while the entity has
  items (any `entities_id` table) or unmanaged children.
- Nothing is written to GLPI before the user queues a selection; the preview snapshot
  is for display only, the runner recomputes the plan at each execution and matches
  the selected ids (stable: hash of type + key path) against it.
- Never post one form field per operation (PHP `max_input_vars`): the selection lives
  in the job and changes through `ajax/selection.php` (JSON body).
- The background task has no user session: rights are checked when queuing and stored
  in the job (`forbidden`); progress is saved after each batch so a run can resume.
- Writes go through `Entity::add/update/delete` (not raw SQL) so GLPI rules, history
  and caches apply. Entity rights and recursive access to the target root are enforced.
- Selection dependencies are computed on the server only (`Engine\Sync\Selection`,
  `Plan::expandSelection()`); the JavaScript displays what the server answers.

## Conventions

- Code, comments, commits and developer docs in **English**. User-facing docs exist
  in English **and** French (`docs/en/`, `docs/fr/`): update both together, and the
  screenshots in `docs/images/` if the UI changes visibly.
- Every user-visible string is translatable (`__('...', 'entitysync')`, `_n()`, or
  `Msg::t()` in the engine), then `make locales` and translate the new entries in
  `locales/fr_FR.po` (CI fails on missing French translations).
- `declare(strict_types=1)`, typed code, PSR-12, `.editorconfig`.
- Behaviour changes: add or adjust unit tests (`tests/Engine/`), extend the e2e
  scenario when the HTTP workflow changes, add a `CHANGELOG.md` entry.
- Schema changes: migration in `plugin_entitysync_install()` with GLPI's `Migration`
  API + version bump in `setup.php`.

## GLPI 11 / 12 pitfalls

- One code base for GLPI 11.0 and 12.0; the differences live in `src/Compat/` and the
  CI tests both (`GLPI_VERSION=12.0.x make glpi-up` after `make glpi-reset` locally).
- GLPI 12 types the base class properties (`$rightname`, `$dohistory`): never redeclare
  them directly, use (or add) a trait of `src/Compat/`, declared per GLPI version.
- GLPI 12 has no CSRF tokens (header checks) and deprecates `csrf_token()`: in forms,
  `{{ include('@entitysync/csrf_field.html.twig') }}`, never `csrf_token()` directly.

- `Html::redirect()` / `Html::back()` throw; code after them does not run.
- GLPI 11: POST requests need a fresh single-use CSRF token (the partial above).
- Static assets live in `public/` and are served at `/plugins/entitysync/<file>`.
- `forcetab` does not work for this plugin's namespaced class by itself:
  `front/model.form.php` sets the active tab explicitly.
- Methods declared `bool` must cast `Session::haveRight()` (an int on GLPI 11).
- `glpi_entities` has `UNIQUE (entities_id, name)`, case and accent insensitive.
- Automatic actions in GLPI (internal) mode only run from the login page:
  the task is registered in CLI mode and needs the system cron.
- `PLUGIN_ENTITYSYNC_RUN_TIME_BUDGET` shortens each execution of the task
  (`ENTITYSYNC_RUN_TIME_BUDGET=2 make glpi-up`); the CI e2e uses it to test resumption.
- GLPI queues user messages in the session; `GlpiGateway::guard()` captures them.

## Release

Bump `PLUGIN_ENTITYSYNC_VERSION` in `setup.php`, add `## X.Y.Z` to `CHANGELOG.md`,
`make check` and `make e2e`, then tag `vX.Y.Z`: the CI builds and publishes the
release. Never commit `dist/` or `locales/*.mo`.
