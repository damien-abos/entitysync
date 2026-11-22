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
- [GLPI 11 and 12 notes](#glpi-11-and-12-notes)
- [Conventions](#conventions)

## Development environment

GLPI and MariaDB run in a Docker Compose stack, `docker/compose.yml`, used by the
dev container and the CI alike. The dev container itself only holds the tools.

### GLPI stack

- `docker/glpi/` builds the GLPI image: Alpine 3.24, Apache (port 8080) and PHP-FPM
  8.5, run as the non-root user `glpi` (uid 1000), GLPI's cron run every minute by
  supercronic, like the system crontab of a server. The GLPI release is the
  `GLPI_VERSION` build argument (default in `docker/compose.yml`).
- `docker/compose.yml` adds MariaDB 11.4 and mounts the repository as
  `/var/www/glpi/plugins/entitysync`, so PHP, Twig and JavaScript changes apply on the
  next request (clear the browser cache for JavaScript). GLPI runs in the
  `development` environment (strict Twig variables, debug logs) and its logs are
  written to `docker/log/` in the repository (`php-errors.log` must stay clean).
- `tools/glpi.sh` drives it (`make glpi-up`, `glpi-down`, `glpi-reset`): `up`
  compiles the translations, builds and starts the stack, waits for GLPI, writes the
  local constants and installs and activates the plugin. It is idempotent.

| Variable | Default | Effect |
|---|---|---|
| `COMPOSE` | `docker compose` | Compose command (e.g. `podman-compose`) |
| `GLPI_VERSION` | see `docker/compose.yml` | GLPI release, `X.Y.x` for the latest release of a branch (`11.0.x`, `12.0.x`) or `latest`; `make glpi-reset` first when changing it (the database is not migrated) |
| `GLPI_HTTP_PORT`, `DB_PORT` | `8080`, `3306` | Published ports |
| `ENTITYSYNC_RUN_TIME_BUDGET` | unset (50 s) | `PLUGIN_ENTITYSYNC_RUN_TIME_BUDGET`, seconds per execution of the background task |

GLPI is on <http://localhost:8080> (login `glpi` / `glpi`). Commands in the GLPI
container go through `tools/glpi.sh exec`:

```sh
tools/glpi.sh exec glpi-console plugin:install entitysync -u glpi --force   # after install/schema changes
tools/glpi.sh exec php /var/www/glpi/front/cron.php --force entitysyncApply # apply a queued job at once
```

The constants of `ENTITYSYNC_RUN_TIME_BUDGET` are written to
`/data/glpi/config/local_define.php` in the data volume; the image's
`config/local_define.php` loads it. Data (GLPI files, database) live in the
`entitysync_glpi-data` and `entitysync_glpi-db` volumes; `make glpi-reset` deletes
them.

### Dev container (recommended)

`.devcontainer/devcontainer.json` uses the `base:trixie` image with the
*docker-in-docker*, Node.js and Claude Code features.

1. Open the repository in VS Code (or any editor supporting dev containers) and run
   *Dev Containers: Reopen in Container*.
2. On creation, `.devcontainer/post-create.sh` installs the PHP CLI (Debian's, 8.4)
   with the extensions of the engine and of the e2e client, gettext and shellcheck.
3. On each start, `make glpi-up` starts the GLPI stack in the container's own Docker
   daemon (the first build takes a few minutes). Port 8080 is forwarded.

The images and volumes of the inner Docker daemon, and Claude Code's settings, are
kept in volumes of the dev container, so they survive rebuilds. There is no Xdebug
and GLPI's sources are not in the dev container (they are in the GLPI image).

### Without the dev container

You need Docker with Compose (or Podman with `COMPOSE=podman-compose`), PHP 8.2+
with `curl`, `intl`, `mbstring` and `mysqli`, and the gettext tools. Then
`make glpi-up`. On a host with SELinux (Fedora...), the bind mounts of
`docker/compose.yml` need relabelling or `label = false` in `containers.conf`.

### Make targets

| Target | Purpose |
|---|---|
| `make check` | Lint (PHP, JavaScript, shell), unit tests, translation checks |
| `make test` | Engine unit tests |
| `make e2e` | End-to-end scenario against the GLPI stack |
| `make locales` | Extract strings, update the `.po` files, compile `.mo` |
| `make package` | Build `dist/entitysync-<version>.tar.gz` |
| `make glpi-up` | Start the GLPI stack, install and activate the plugin |
| `make glpi-down`, `make glpi-reset` | Stop the GLPI stack, keeping or deleting its data |

## Repository layout

```text
setup.php, hook.php       Plugin declaration (version, requirements, hooks), install/uninstall
front/                    Legacy controllers routed by GLPI (/plugins/entitysync/front/*.php)
ajax/                     JSON endpoints of the preview and the progress page
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
  EntityProperties.php    Entity properties a level can manage: fields, choices, validation
  ImportController.php    Upload -> preview -> queue -> status workflow, AJAX handlers
  Job.php                 Import job (CommonDBTM) and the automatic action entitysyncApply
  JobRunner.php           Applies a queued job by batches
  JobStorage.php          Job files in files/_plugins/entitysync/jobs
  PlanFactory.php         Plan of an import against the current GLPI state
templates/                Twig views (@entitysync namespace)
public/                   JavaScript editors and preview logic, CSS (served at /plugins/entitysync/)
locales/                  entitysync.pot, fr_FR.po, en_GB.po (.mo built, not committed)
tests/
  run.php, TestCase.php   Dependency-free unit test runner
  Engine/                 Engine unit tests
  e2e/                    End-to-end scenario and its fixtures
tools/                    glpi.sh (GLPI stack), extract-locales.php, build-package.sh
docker/                   GLPI + MariaDB stack for development and CI (compose.yml, glpi/ image)
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
                ▼
   upload ──► snapshot (JSON file) ──► paginated preview ◄──► Selection (AJAX, kept in the job)
                                                │ queue
                                                ▼
   automatic action entitysyncApply ──► JobRunner: recompute Plan, Applier::applyBatch() by batches
                                                │
                                                ▼
                                     GatewayInterface (GlpiGateway) ──► Run (history)
```

An import is an **import job** (`Job`): the upload stores the file and a snapshot of
the preview (operations as arrays, issues, counters) in `files/_plugins/entitysync/
jobs`, and the job keeps the selection. The preview pages and the selection changes
are AJAX calls working on the snapshot, so nothing is posted per operation (PHP's
`max_input_vars`, 1,000 by default, made larger selections fail with HTTP 400 in
0.0.1). Queuing hands the job over to the automatic action, which recomputes the plan
against the current state and applies the selection by batches.

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

- linked path → `update` if name, comment (when managed), a managed property or parent
  differ;
- unlinked path, same-name entity under the expected parent, not linked elsewhere
  (or linked by this model to a path that disappeared) → `link`;
- otherwise → `add`;
- linked path absent from the nodes and not protected → `delete`.

Each `Operation` has an id (hash of type and path), the changes to apply, the ids of
the operations it **requires** (the creation or link of its parent; for a deletion,
the deletion of its managed children) and **block reasons** (items still attached,
unmanaged children, name collision, entity managed by another model, missing
rights). Blocks propagate to dependent operations.

### Entity properties

A level may manage other fields of its entities (`LevelConfig::$properties`, field =>
`{source: fixed, value}` or `{source: column, column}`). The engine treats them as
opaque strings: `TreeBuilder` stores the values of the first row on the `Node` (a
different value on another row is a warning, as for names and comments), `Planner`
compares them with `ExistingState` entities (`properties`, NULL and empty being equal)
and records the differences as `property:<field>` changes, and `Applier` passes them
to `createEntity()` and `updateEntity()`.

What GLPI accepts lives on the plugin side, in `EntityProperties`: the fields of the
*Advanced information* and *Assets* tabs of an entity, their labels (GLPI's own
strings, translated by GLPI), their type (text, or choice among the values of the
entity form) and choices, the validation of a model, the reading of the current values
(the negative values of `entities_id_software` and `transfers_id` are stored by GLPI in
`entities_strategy_software` / `transfers_strategy`) and the display of values in the
preview. Choice fields only take fixed values; text fields can also come from a column.
Adding a field there makes it available, provided that it exists in every supported
GLPI version.

### Selection

`Selection` works on the array form of the operations (the snapshot). It keeps the
selection consistent: selecting an operation selects what it requires, unselecting one
unselects what requires it, blocked operations are never selected. It also provides
the search (`matching()`), the pages (`page()`), the *select all / none matching*
actions and the counters of the toolbar and section headers (`summary()`). The default
selection (`defaultIds()`) is every non-blocked operation except deletions.

### Applier

`Applier::applyBatch()` applies exactly the given operations, in execution order
(deletions deepest first, then by level: updates, links, additions, so that a name
freed by a rename or a deletion can be reused). An operation whose prerequisite was not
applied by the same instance is skipped; a failure is recorded and the others go on.
The same instance can apply several batches of one plan (entities created by a batch
are known to the next ones). `Applier::apply()` expands a selection, then applies it in
one batch.

### Job runner

`JobRunner::run()` is called by the automatic action. At each execution it recomputes
the plan from the file and the current state, with the rights recorded when the job
was queued, then applies batches of the selected operations that have no result yet,
saving the results and counters after each batch, until the time budget (50 s) is
spent or nothing is left. Across executions, prerequisites applied earlier disappear
from the recomputed plan, so a batch never waits for them; a prerequisite that failed
is still in the plan and its dependents are skipped. At the end, selected operations
that are no longer in the plan are reported as skipped, a `Run` is recorded (also for
a stopped job) and the files are removed. Cancellation is checked between batches.

### Messages

Engine messages go through `Util\Msg::t()`, a `sprintf` wrapper. `setup.php` plugs
GLPI's `__()` with the `entitysync` domain into it, so the engine stays independent
while its messages are translated. `tools/extract-locales.php` extracts `Msg::t()`
strings too.

## GLPI integration

| File | Role |
|---|---|
| `setup.php` | Version and requirements, class registration, menu (`admin`), CSS/JS hooks, `item_purge` hook on `Entity` |
| `hook.php` | Install and update (tables, `status` column of the runs, rights through `Migration::addRight()`, automatic action through `CronTask::register()`, jobs directory), uninstall, purge hook that removes the links of an entity deleted outside the plugin |
| `src/Model.php` | Model item: form (`templates/model_form.html.twig`), tabs (*Import*, *Managed entities*, *Import runs*, history), input validation (JSON of the editors decoded and validated with the engine), `APPLY` right (128), search options |
| `src/ImportController.php` | `upload()` creates a draft job, stores the file, computes the plan and writes the snapshot; `show()` renders the preview of a draft or the progress page of a queued job; `page()` and `select()` serve the AJAX calls of the preview; `queue()` checks the rights, records them and queues the job; `cancel()` removes a draft, cancels a queued job or asks a running one to stop; `status()` serves the progress page |
| `src/Job.php` | Import job: statuses (`draft`, `queued`, `running`, `cancelling`, `done`, `cancelled`, `failed`), selection, recorded rights, results; automatic action `cronEntitysyncApply()` (oldest active job first, removal of drafts older than 24 h); health of the action for the progress page |
| `src/JobRunner.php`, `src/JobStorage.php`, `src/PlanFactory.php` | Batch application, job files, plan computation (shared by the upload and the runner) |
| `ajax/preview.php`, `ajax/selection.php`, `ajax/job.php` | JSON endpoints: one page of a section (rendered by `templates/preview_rows.html.twig`), selection changes (JSON body; on GLPI 11, CSRF token in the `X-Glpi-Csrf-Token` header), progress of a job |
| `src/GlpiGateway.php` | Loads the root subtree and the links; inspects every table with an `entities_id` column to block deletions; writes through `Entity::add/update/delete` so that GLPI rules, history, caches and hooks apply; captures the messages GLPI queues for the user and returns them as operation errors |
| `src/ProfileRights.php` | Rights matrix tab on profiles |

The snapshot is only used to display and select: the plan is recomputed by the
runner at each execution, and the selected operation ids are matched against it.
Operation ids are stable (hash of the type and the key path), which makes this
matching possible.

## Front end

`public/entitysync.js` is plain JavaScript (no build step), loaded on every page by
the `add_javascript` hook and initialized by inline calls in the templates:

- `EntitySync.initModelForm()` renders the **levels editor** and the **filter
  editor** from the JSON stored in hidden inputs (`levels_json`, `filter_json`) and
  writes it back on every change. Column inputs share a `<datalist>` built from the
  header of the last imported file (`known_columns`).
- `EntitySync.initPreview()` loads the pages of each section (`ajax/preview.php`),
  sends every selection change to `ajax/selection.php` (one request at a time) and
  updates the checkboxes, section toggles and counter from the answer; it handles the
  search (debounced), the page size (remembered in `localStorage`) and the
  confirmation of deletions. The selection logic itself is on the server.
- `EntitySync.initJobStatus()` polls `ajax/job.php` every 3 seconds while a job is
  active and reloads the page when its status changes.

Labels come from `Model::jsLabels()` (translated on the server) and are passed as
JSON. To add a label: add it there, use it in the JS, run `make locales`.

## Data model

| Table | Columns | Notes |
|---|---|---|
| `glpi_plugin_entitysync_models` | `name`, `comment`, `entities_id_target`, `csv_delimiter`, `csv_enclosure`, `csv_encoding`, `levels` (JSON), `filter` (JSON), `known_columns` (JSON), dates | One row per model |
| `glpi_plugin_entitysync_links` | `plugin_entitysync_models_id`, `entities_id`, `path` (JSON key path), `path_hash` (SHA-1), dates | `UNIQUE (models_id, path_hash)`; defines the managed entities |
| `glpi_plugin_entitysync_runs` | `plugin_entitysync_models_id`, `users_id`, `date`, `filename`, counters (`nb_*`), `details` (JSON list of operation results), `status` (final status of the job) | Import history |
| `glpi_plugin_entitysync_jobs` | `plugin_entitysync_models_id`, `users_id`, `status`, `filename`, `nb_rows`, `nb_total`, `nb_processed`, counters (`nb_*`), `selection` (JSON ids), `forbidden` (JSON rights restrictions), `results` (JSON, id → result), `message`, `plugin_entitysync_runs_id`, dates (`date_queued`, `date_start`, `date_end`) | Import jobs; `selection` and `results` are cleared when the job ends |

The JSON formats of `levels` and `filter` are documented in the docblocks of
`Engine\Config\LevelConfig` and `Engine\Filter\FilterEvaluator`.

**Schema changes.** `plugin_entitysync_install()` creates the missing tables and
applies the migrations, guarded by checks on the current schema (0.0.2 added the
`jobs` table and `runs.status` with `Migration::addField()`). For a new change, add the
migration there and bump `PLUGIN_ENTITYSYNC_VERSION` so that GLPI asks for the update.

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

`tests/e2e/run.php` drives a running GLPI over HTTP (login, CSRF tokens on GLPI 11,
`Origin` header and re-authentication on GLPI 12, forms,
multipart upload, AJAX tabs, paginated preview, JSON selection calls) and runs GLPI's
cron (`front/cron.php --force entitysyncApply`, in `GLPI_DIR` through the `GLPI_EXEC`
command prefix) until each job ends,
then checks the database. It creates its own target root entity and model, then runs:
initial import, idempotent re-import, adoption of an existing entity, sibling name
collision, deletions blocked by an item, server-side dependency expansion, a job
stopped before it runs, and a volume import (1,200 rows, 1,320 operations, more than
PHP's default `max_input_vars`; `E2E_VOLUME_ROWS` changes it, 0 skips it), and full
cleanup. With `ENTITYSYNC_RUN_TIME_BUDGET=1 make glpi-up`, executions are cut after
one second, which exercises the resumption over several executions (the CI sets 2
seconds). It fails on any new line in
`php-errors.log` when `GLPI_LOG_DIR` is set (`make e2e` sets it to `docker/log`).
When it fails, what it created is left in place for inspection: an
`E2E entitysync <date>` entity and an `E2E model` import model.

Its variables are documented at the top of the file; the Makefile sets them for the
stack of `docker/compose.yml`, so `make e2e` works as is after `make glpi-up`. Fixtures are in `tests/e2e/fixtures/`.

## Continuous integration and releases

`.github/workflows/ci.yml`:

| Job | Content |
|---|---|
| Lint and translations | `make lint`, `make locales-check` |
| Unit tests | `make test` on PHP 8.2 to 8.5 |
| E2E | Stack of `docker/compose.yml` (PHP 8.5, MariaDB 11.4) built for the oldest and latest release of GLPI 11.0 and 12.0, GLPI in `development` mode, background task cut after 2 s to exercise resumption; GLPI and compose logs uploaded on failure |
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

## GLPI 11 and 12 notes

The plugin supports GLPI 11.0 and 12.0 with one code base. The differences are kept in
`src/Compat/` (`Compat\Glpi` tells the running version) and covered by the CI matrix:

- **Typed properties (12).** GLPI 12 declares native types on the properties of its
  base classes (`public static string $rightname`, `public bool $dohistory`). A
  redeclaration must repeat the type on GLPI 12 and must not have one on GLPI 11, so
  the plugin classes take these properties from the traits of `src/Compat/`
  (`ModelRightName`, `ProfileRightName`, `WithHistory`), declared according to the
  running version. Add a trait there for any other redeclared base property.
- **CSRF (12).** GLPI 12 drops the tokens: POST requests are checked on the browser's
  `Sec-Fetch-Site` / `Origin` headers (requests with neither are accepted), and
  `csrf_token()` / `getAjaxCsrfToken()` are deprecated (logged in development). Forms
  include `@entitysync/csrf_field.html.twig`, which renders the token on GLPI 11
  only; the JavaScript sends `X-Glpi-Csrf-Token` only when the page has the
  `glpi:csrf_token` meta tag (GLPI 11).
- **Re-authentication (12).** Sensitive items (profiles, groups, configuration...)
  redirect to `/ReAuth/Prompt` until the password is given again (`/ReAuth/Verify`,
  field `user_input`); the e2e scenario does it before opening the profile tab.
- **Console noise.** `database:install`, `plugin:install` and `cache:clear` log a
  `CRITICAL ... Error thrown while running command` entry in `php-errors.log` (GLPI's
  own Symfony cache clearing, plugin or not, GLPI 11 and 12); the e2e scenario only
  checks the entries written during its run, and `make glpi-up` does not run
  `cache:clear`.

Points learned while building the plugin against GLPI 11.0:

- **Routing.** GLPI 11 serves plugin files through Symfony: PHP scripts are only
  reachable in `front/`, `ajax/` or `report/`; static files must be in `public/` and
  are served at `/plugins/<key>/<file>` (without `public/`). `front/` scripts do not
  need to include `inc/includes.php`: the kernel boots GLPI before running them.
- **`Html::redirect()` and `Html::back()` throw** a `RedirectException`: code after
  them never runs.
- **CSRF (11)** is checked by a kernel listener for every POST (`_glpi_csrf_token`
  field, or `X-Glpi-Csrf-Token` header for AJAX). Tokens are single-use: render a new
  one in every form (`@entitysync/csrf_field.html.twig`).
- **`forcetab`** derives the item type from the URL, which does not match a
  namespaced class served by a flat `front/model.form.php`; `front/model.form.php`
  sets the active tab explicitly.
- **Return types.** Overridden methods of `CommonDBTM`/`CommonGLPI` and the
  `Session::haveRight()` result (an int on GLPI 11, a bool on 12) must be cast where a
  `bool` is declared.
- **Entities.** `glpi_entities` has `UNIQUE (entities_id, name)` with a
  case- and accent-insensitive collation; `Entity::pre_deleteItem()` refuses to
  delete an entity with children; `Entity::post_addItem()` adds the new entity to the
  session's active entities.
- **Form size.** PHP drops the fields beyond `max_input_vars` (1,000 by default) and
  GLPI 11 then answers HTTP 400: never post one field per row of a large list.
- **Automatic actions.** In *GLPI* (internal) mode, GLPI 11 only triggers automatic
  actions from the login page; background work needs the CLI mode and a system cron.
  `front/cron.php --force <name>` runs one action at once. In the cron context there
  is no user session (`Session::isCron()`), the active entity is the root entity.
  On GLPI 11.0.0, `front/cron.php` crashes when run as root even with
  `--allow-superuser` (fixed in later 11.0.x): run it as the web server user.
- **AJAX.** Plugin scripts in `ajax/` are routed like `front/`; POST requests sent with
  `X-Requested-With: XMLHttpRequest` are checked against the `X-Glpi-Csrf-Token`
  header on GLPI 11 (the `glpi:csrf_token` meta tag of the page), and the token is
  kept.
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
