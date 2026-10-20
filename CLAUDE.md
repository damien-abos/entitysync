# CLAUDE.md

Context for Claude Code working on this repository.

## Project

GLPI 11 plugin `entitysync`: synchronizes the GLPI entity tree below a target entity
from a CSV file. An import model defines levels (key columns, name column, regex
replacement rules, comment columns, placeholder name for empty values) and an
AND / OR / NOT filter on rows. An upload produces a preview (additions, links,
updates, deletions) where the user selects what to apply. Full behaviour:
`docs/en/user-guide.md`, `docs/en/installation.md`; architecture and GLPI notes:
`docs/developer.md` (read it before non-trivial changes).

## Commands

```sh
make check          # lint (PHP, JS, shell) + unit tests + translation checks: run before finishing
make test           # engine unit tests only (php tests/run.php [filter])
make e2e            # end-to-end scenario against the running GLPI (dev container: works as is)
make locales        # after adding or changing a user-visible string
make package        # dist/entitysync-<version>.tar.gz
make glpi-install   # (re)install GLPI and the plugin; needed after install/schema changes
```

Dev container: GLPI in `/opt/glpi`, served on http://localhost:8080 (glpi / glpi),
GLPI console `php /opt/glpi/bin/console`, logs in `/opt/glpi/files/_log`
(`php-errors.log` must stay clean). The repository is linked as
`/opt/glpi/plugins/entitysync`, so PHP/Twig/JS changes apply immediately.

There is no Composer dependency: the unit tests use the small runner in
`tests/run.php`. Do not add PHPUnit or other packages without asking.

## Layout

- `src/Engine/` — pure PHP core (CSV, filter, tree, planner, applier). **Must not use
  GLPI classes or functions.** All GLPI access goes through `src/GlpiGateway.php`
  (implements `Engine\Sync\GatewayInterface`).
- `src/Model.php` (model item, form, tabs, JS labels), `src/ImportController.php`
  (upload → preview → apply), `src/Link.php`, `src/Run.php`, `src/ProfileRights.php`.
- `setup.php` (version, hooks), `hook.php` (install/uninstall, schema).
- `front/` controllers, `templates/` Twig (`@entitysync/...`), `public/` JS/CSS.
- `tests/Engine/` unit tests, `tests/e2e/` end-to-end scenario and fixtures.
- `tools/` install, locales extraction, packaging. `docs/` documentation.

## Invariants (do not break)

- Identity of an entity = its key path (JSON list of level keys, `Engine\Tree\Path`);
  links per model in `glpi_plugin_entitysync_links`.
- Only entities linked to the model can be updated or deleted. Never propose deleting
  an entity of a row in error (protected paths).
- Deletions are never selected by default; a deletion is blocked while the entity has
  items (any `entities_id` table) or unmanaged children.
- Nothing is written to GLPI before the user applies a selection; the plan is
  recomputed at apply time (never trust stored plans or client-side ids blindly).
- Writes go through `Entity::add/update/delete` (not raw SQL) so GLPI rules, history
  and caches apply. Entity rights and recursive access to the target root are enforced.
- Selection dependencies are the same on the client (`public/entitysync.js`) and on the
  server (`Plan::expandSelection()`).

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

## GLPI 11 pitfalls

- `Html::redirect()` / `Html::back()` throw; code after them does not run.
- POST requests need a fresh single-use CSRF token (`csrf_token()` in Twig).
- Static assets live in `public/` and are served at `/plugins/entitysync/<file>`.
- `forcetab` does not work for this plugin's namespaced class by itself:
  `front/model.form.php` sets the active tab explicitly.
- Methods declared `bool` must cast `Session::haveRight()` (it returns an int).
- `glpi_entities` has `UNIQUE (entities_id, name)`, case and accent insensitive.
- GLPI queues user messages in the session; `GlpiGateway::guard()` captures them.

## Release

Bump `PLUGIN_ENTITYSYNC_VERSION` in `setup.php`, add `## X.Y.Z` to `CHANGELOG.md`,
`make check` and `make e2e`, then tag `vX.Y.Z`: the CI builds and publishes the
release. Never commit `dist/` or `locales/*.mo`.
