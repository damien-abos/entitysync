# Entity Sync — Developer guide

This guide explains how the plugin is built, how to work on it and how to release
it. User-facing behaviour is described in the [user guide](en/user-guide.md) and the
[installation and configuration guide](en/installation.md).

- [Development environment](#development-environment)
- [Repository layout](#repository-layout)
- [Architecture](#architecture)
- [The engine](#the-engine)
- [GLPI integration](#glpi-integration)
- [Front end](#front-end)
- [Data model](#data-model)
- [Translations](#translations)
- [Tests](#tests)
- [Continuous integration and releases](#continuous-integration-and-releases)
- [GLPI 11 notes](#glpi-11-notes)
- [Conventions](#conventions)

## Development environment

### Dev container (recommended)

The repository ships a [dev container](https://containers.dev/) with PHP 8.3, the
extensions GLPI needs, gettext, shellcheck, Node.js, Claude Code and a MariaDB 10.11
service.

1. Open the repository in VS Code (or any editor supporting dev containers) and run
   *Dev Containers: Reopen in Container*.
2. On first creation, `.devcontainer/post-create.sh` runs `make glpi-install`: it
   downloads GLPI (latest 11.0.x, or `GLPI_VERSION` from the host environment),
   installs its database, links the repository as `plugins/entitysync`, and installs
   and activates the plugin.
3. GLPI is served on <http://localhost:8080> (login `glpi` / `glpi`) by
   `.devcontainer/serve.sh`, PHP's built-in server, which is the container's main
   process. Its log is `/opt/glpi/files/_log/php-server.log`.

GLPI runs in the `development` environment: strict Twig variables, debug logs in
`/opt/glpi/files/_log`. Because the plugin directory is a symbolic link to the
repository, PHP, Twig and JavaScript changes apply on the next request (clear the
browser cache for JavaScript). Changes to `setup.php` or `hook.php` that alter the
installation need a reinstall: `make glpi-install` after bumping the version, or
`php /opt/glpi/bin/console plugin:install entitysync -u glpi --force`.

Xdebug is installed and starts on demand (trigger mode, port 9003): use a browser
extension or the `XDEBUG_TRIGGER` cookie.

GLPI and database data live in named volumes (`glpi`, `db-data`), and Claude Code's
settings in `claude-config`, so they survive container rebuilds. To start from
scratch, remove these volumes (`docker volume ls` lists them, prefixed with the
compose project name) and rebuild the container.

### Without the dev container

You need PHP 8.2+ with GLPI's extensions, the gettext tools, and a MariaDB or MySQL
database. Then:

```sh
export GLPI_DIR=$HOME/glpi DB_HOST=127.0.0.1 DB_NAME=glpi DB_USER=glpi DB_PASSWORD=glpi
make glpi-install
php -S 127.0.0.1:8080 -t "$GLPI_DIR/public" "$GLPI_DIR/public/index.php"
```

`tools/install-glpi.sh` documents all its variables; it is idempotent.

### Make targets

| Target | Purpose |
|---|---|
| `make check` | Lint (PHP, JavaScript, shell), unit tests, translation checks |
| `make test` | Engine unit tests |
| `make e2e` | End-to-end scenario against the running GLPI |
| `make locales` | Extract strings, update the `.po` files, compile `.mo` |
| `make package` | Build `dist/entitysync-<version>.tar.gz` |
| `make glpi-install` | Install or refresh GLPI and the plugin |

## Repository layout

```text
setup.php, hook.php       Plugin declaration (version, requirements, hooks), install/uninstall
front/                    Legacy controllers routed by GLPI (/plugins/entitysync/front/*.php)
src/
  Engine/                 Pure PHP core, no GLPI dependency
    Csv/                  CSV reading (encoding, delimiter detection, header validation)
    Filter/               Boolean filter evaluation and validation
    Config/               Model and level configuration objects
    Tree/                 CSV rows -> desired tree (nodes, issues, protected paths)
    Sync/                 Desired tree + GLPI state -> plan of operations -> application
    Util/                 Text helpers, message translation hook
  Model.php               Import model (CommonDBTM): form, tabs, rights, configuration
  Link.php, Run.php       Managed entities and import history (CommonDBTM)
  ProfileRights.php       Rights tab on profiles
  GlpiGateway.php         Engine gateway: reads GLPI state, writes entities
  ImportController.php    Upload -> preview -> apply workflow
templates/                Twig views (@entitysync namespace)
public/                   JavaScript editors and preview logic, CSS (served at /plugins/entitysync/)
locales/                  entitysync.pot, fr_FR.po, en_GB.po (.mo built, not committed)
tests/
  run.php, TestCase.php   Dependency-free unit test runner
  Engine/                 Engine unit tests
  e2e/                    End-to-end scenario and its fixtures
tools/                    install-glpi.sh, extract-locales.php, build-package.sh
docs/                     User, installation and developer documentation
.devcontainer/            Development container
.github/workflows/ci.yml  CI pipeline
```

## Architecture

```text
             CSV file                                   GLPI database
                │                                             │
        ┌───────▼────────┐                         ┌──────────▼──────────┐
        │   CsvReader    │                         │     GlpiGateway     │
        └───────┬────────┘                         │  loadState()        │
                │ CsvData (header, rows by line)   │  inspectUsage()     │
        ┌───────▼────────┐                         └──────────┬──────────┘
        │  TreeBuilder   │◄── ModelConfig                     │ ExistingState
        │ (FilterEvaluator, LevelConfig)                      │ (entities, links)
        └───────┬────────┘                                    │
                │ BuildResult (nodes, issues, protected paths)│
        ┌───────▼─────────────────────────────────────────────▼┐
        │                        Planner                        │
        └───────┬───────────────────────────────────────────────┘
                │ Plan (operations with dependencies and blocks)
       preview.html.twig ──► user selection ──► Applier ──► GatewayInterface (GlpiGateway)
```

The engine (`src/Engine`) only depends on PHP and `mbstring`/`intl`. Everything that
touches GLPI goes through `GlpiGateway`, which implements
`Engine\Sync\GatewayInterface` for writes and provides the state snapshot and the
usage inspector for reads. This keeps the matching rules testable without GLPI.

## The engine

### Key paths

The identity of a desired entity is its **key path**: the list of level keys from
level 1 to the entity.

- A level key is the value of its key column, or the values of several key columns
  joined with `LevelConfig::KEY_PART_SEPARATOR` (U+001F).
- An empty key in the middle of a path is replaced by `Path::PLACEHOLDER_KEY`
  (`"\0empty"`), which cannot come from a CSV cell, so a real value equal to the
  placeholder name never collides with it.
- Trailing empty keys shorten the path.
- The canonical form (`Path::id()`) is the JSON encoding of the list; the link table
  stores it with its SHA-1 (`Path::hash()`) for the unique index.

### TreeBuilder

For each row kept by the filter, every prefix of its key path becomes a `Node`
(created once, first row wins). It also produces `Issue`s (warnings and errors, with
line numbers) and a set of **protected paths**: the paths of rows in error, which the
planner must never propose for deletion. Sibling names are compared with
`Text::normalizeName()` (case, accents and spaces), which mirrors the
`utf8mb4_unicode_ci` collation of GLPI's `UNIQUE (entities_id, name)` index.

### Planner

`Planner::plan()` compares the nodes, ordered by level, with the GLPI state:

- linked path → `update` if name, comment (when managed) or parent differ;
- unlinked path, same-name entity under the expected parent, not linked elsewhere
  (or linked by this model to a path that disappeared) → `link`;
- otherwise → `add`;
- linked path absent from the nodes and not protected → `delete`.

Each `Operation` has an id (hash of type and path), the changes to apply, the ids of
the operations it **requires** (the creation or link of its parent; for a deletion,
the deletion of its managed children) and **block reasons** (items still attached,
unmanaged children, name collision, entity managed by another model, missing
rights). Blocks propagate to dependent operations.

### Applier

`Applier::apply()` expands the selection with the required operations (the same rule
as the browser), orders it (deletions deepest first, then by level: updates, links,
additions, so that a name freed by a rename or a deletion can be reused), then
applies each operation through the gateway. A failure is recorded and the operations
that require it are skipped; the others go on. The result feeds the import history.

### Messages

Engine messages go through `Util\Msg::t()`, a `sprintf` wrapper. `setup.php` plugs
GLPI's `__()` with the `entitysync` domain into it, so the engine stays independent
while its messages are translated. `tools/extract-locales.php` extracts `Msg::t()`
strings too.

## GLPI integration

| File | Role |
|---|---|
| `setup.php` | Version and requirements, class registration, menu (`admin`), CSS/JS hooks, `item_purge` hook on `Entity` |
| `hook.php` | Install (tables, rights through `Migration::addRight()`), uninstall, purge hook that removes the links of an entity deleted outside the plugin |
| `src/Model.php` | Model item: form (`templates/model_form.html.twig`), tabs (*Import*, *Managed entities*, *Import runs*, history), input validation (JSON of the editors decoded and validated with the engine), `APPLY` right (128), search options |
| `src/ImportController.php` | `upload()` stores the file in `GLPI_TMP_DIR` under a random token kept in the session; `preview()` reads it and renders the plan; `apply()` recomputes the plan against the current state, applies the selection and records a `Run`; `cancel()` removes the file |
| `src/GlpiGateway.php` | Loads the root subtree and the links; inspects every table with an `entities_id` column to block deletions; writes through `Entity::add/update/delete` so that GLPI rules, history, caches and hooks apply; captures the messages GLPI queues for the user and returns them as operation errors |
| `src/ProfileRights.php` | Rights matrix tab on profiles |

The plan is never stored: it is recomputed at apply time, and the submitted
operation ids are matched against the new plan. Ids that no longer exist are
reported to the user.

## Front end

`public/entitysync.js` is plain JavaScript (no build step), loaded on every page by
the `add_javascript` hook and initialized by inline calls in the templates:

- `EntitySync.initModelForm()` renders the **levels editor** and the **filter
  editor** from the JSON stored in hidden inputs (`levels_json`, `filter_json`) and
  writes it back on every change. Column inputs share a `<datalist>` built from the
  header of the last imported file (`known_columns`).
- `EntitySync.initPreview()` manages the selection in the preview: dependency
  propagation (`data-es-requires`), section toggles, search, counter and
  confirmation of deletions.

Labels come from `Model::jsLabels()` (translated on the server) and are passed as
JSON. To add a label: add it there, use it in the JS, run `make locales`.

## Data model

| Table | Columns | Notes |
|---|---|---|
| `glpi_plugin_entitysync_models` | `name`, `comment`, `entities_id_target`, `csv_delimiter`, `csv_enclosure`, `csv_encoding`, `levels` (JSON), `filter` (JSON), `known_columns` (JSON), dates | One row per model |
| `glpi_plugin_entitysync_links` | `plugin_entitysync_models_id`, `entities_id`, `path` (JSON key path), `path_hash` (SHA-1), dates | `UNIQUE (models_id, path_hash)`; defines the managed entities |
| `glpi_plugin_entitysync_runs` | `plugin_entitysync_models_id`, `users_id`, `date`, `filename`, counters (`nb_*`), `details` (JSON list of operation results) | Import history |

The JSON formats of `levels` and `filter` are documented in the docblocks of
`Engine\Config\LevelConfig` and `Engine\Filter\FilterEvaluator`.

**Schema changes.** `plugin_entitysync_install()` currently creates missing tables
only. When a release changes the schema, add the migration there with GLPI's
`Migration` API (`addField`, `changeField`, `addKey`...), guarded by checks on the
current schema, and bump `PLUGIN_ENTITYSYNC_VERSION` so GLPI asks for the update.

## Translations

Source strings are written in English. PHP and Twig use `__('...', 'entitysync')`,
`__s()` (escaped) and `_n()`; engine classes use `Msg::t()`.

```sh
make locales        # extract into locales/entitysync.pot, merge into the .po files, compile .mo
```

Then translate the new entries of `locales/fr_FR.po` (keep the French typography:
`’`, `« »`, non-breaking spaces are not required). `make locales-check`, run by the
CI, fails when the template is outdated or when `fr_FR.po` has untranslated or fuzzy
entries. `.mo` files are build outputs: they are not committed.

## Tests

### Unit tests

`php tests/run.php [filter]` runs the engine tests with a minimal runner (no
Composer dependency): every public `test*` method of the `tests/Engine/*Test.php`
classes. `tests/TestCase.php` provides assertions and helpers to build a
configuration, a CSV, a GLPI state (`state()`) and a plan. `ApplierTest` uses an
in-memory gateway to check full cycles (apply, re-import, deletions).

Add a test for every change in the matching rules. Typical shape:

```php
public function testSomething(): void
{
    $state = $this->state([0 => ['Root', null], 10 => ['ACME', 0]], [Path::id(['ACME']) => 10]);
    $plan = $this->plan("Org;Site;Info\nACME;Paris;\n", $this->config($this->levels()), $state);
    $this->assertSame(['ACME > Paris'], $this->summary($plan)['add']);
}
```

### End-to-end scenario

`tests/e2e/run.php` drives a running GLPI over HTTP (login, CSRF tokens, forms,
multipart upload, AJAX tabs) and checks the database. It creates its own target root
entity and model, then runs: initial import, idempotent re-import, adoption of an
existing entity, sibling name collision, deletions blocked by an item, server-side
dependency expansion, and full cleanup. It fails on any new line in
`php-errors.log` when `GLPI_LOG_DIR` is set (the dev container and the CI set it).
When it fails, what it created is left in place for inspection: an
`E2E entitysync <date>` entity and an `E2E model` import model.

Its variables are documented at the top of the file. In the dev container,
`make e2e` works as is. Fixtures are in `tests/e2e/fixtures/`.

## Continuous integration and releases

`.github/workflows/ci.yml`:

| Job | Content |
|---|---|
| Lint and translations | `make lint`, `make locales-check` |
| Unit tests | `make test` on PHP 8.2 to 8.5 |
| E2E | GLPI 11.0.0 with PHP 8.2, and the latest GLPI 11.0.x with PHP 8.4, on MariaDB 10.11, GLPI in `development` mode; GLPI logs uploaded on failure |
| Package | `tools/build-package.sh`, archive and checksum uploaded as the `entitysync-package` artifact |
| GitHub release | On `v*` tags only: rebuilds the archive, extracts the notes of the version from `CHANGELOG.md`, creates the release with the archive and its checksum |

`tools/build-package.sh` copies an **allowlist** of runtime files (`setup.php`,
`hook.php`, `front/`, `src/`, `templates/`, `public/`, `README.md`, `LICENSE`,
`CHANGELOG.md`), compiles the translations, lints the staged PHP files, rejects
hidden files and builds a reproducible archive (fixed order, owners and dates taken
from the last commit). With `--tag vX.Y.Z` it fails if the tag does not match the
version in `setup.php`; it always fails if `CHANGELOG.md` has no section for the
version. `.gitattributes` applies the same scope to `git archive`.

**Release checklist**

1. Bump `PLUGIN_ENTITYSYNC_VERSION` in `setup.php` (and `PLUGIN_ENTITYSYNC_MAX_GLPI`
   if a new GLPI minor version is supported).
2. Add a `## X.Y.Z` section to `CHANGELOG.md`.
3. `make check`, then `make e2e` in the dev container.
4. Commit, tag `vX.Y.Z`, push the tag: the CI publishes the release.

## GLPI 11 notes

Points learned while building the plugin against GLPI 11.0:

- **Routing.** GLPI 11 serves plugin files through Symfony: PHP scripts are only
  reachable in `front/`, `ajax/` or `report/`; static files must be in `public/` and
  are served at `/plugins/<key>/<file>` (without `public/`). `front/` scripts do not
  need to include `inc/includes.php`: the kernel boots GLPI before running them.
- **`Html::redirect()` and `Html::back()` throw** a `RedirectException`: code after
  them never runs.
- **CSRF** is checked by a kernel listener for every POST (`_glpi_csrf_token` field,
  or `X-Glpi-Csrf-Token` header for AJAX). Tokens are single-use: render a new one in
  every form (`csrf_token()` in Twig).
- **`forcetab`** derives the item type from the URL, which does not match a
  namespaced class served by a flat `front/model.form.php`; `front/model.form.php`
  sets the active tab explicitly.
- **Return types.** Overridden methods of `CommonDBTM`/`CommonGLPI` and the
  `Session::haveRight()` result (an int) must be cast where a `bool` is declared.
- **Entities.** `glpi_entities` has `UNIQUE (entities_id, name)` with a
  case- and accent-insensitive collation; `Entity::pre_deleteItem()` refuses to
  delete an entity with children; `Entity::post_addItem()` adds the new entity to the
  session's active entities.
- **Messages.** `CommonDBTM` methods queue user messages in
  `$_SESSION['MESSAGE_AFTER_REDIRECT']`; pass `_no_message` and capture the rest (see
  `GlpiGateway::guard()`).
- **Twig.** Plugin templates are in the `@entitysync` namespace;
  `generic_show_form.html.twig` can be extended with the `form_fields` block. In the
  `development` environment, undefined variables are errors.

## Conventions

- Code, comments, commit messages and developer documentation in English; user
  documentation in English and French.
- `declare(strict_types=1)` in classes; typed properties and signatures; PSR-12
  style; 4 spaces (2 for YAML and JSON), see `.editorconfig`.
- The engine must not use GLPI classes or functions.
- Every user-visible string is translatable and translated in `fr_FR.po`.
- A change in behaviour comes with a unit test, an update of the documentation and
  a `CHANGELOG.md` entry.
