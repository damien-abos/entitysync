# Entity Sync — Installation and configuration guide

*[Version française](../fr/installation.md)*

This guide is for GLPI administrators: installing the plugin, granting rights, and
configuring import models. Running imports is described in the
[user guide](user-guide.md).

- [Requirements](#requirements)
- [Installation](#installation)
- [Upgrade](#upgrade)
- [Uninstallation](#uninstallation)
- [Background task (GLPI cron)](#background-task-glpi-cron)
- [Server settings](#server-settings)
- [Rights](#rights)
- [Configuring an import model](#configuring-an-import-model)
- [Worked example](#worked-example)
- [Before the first import](#before-the-first-import)
- [Troubleshooting](#troubleshooting)

## Requirements

| Component | Version |
|---|---|
| GLPI | 11.0.x (tested with 11.0.0 and 11.0.11) |
| PHP | The versions supported by your GLPI release (8.2 or later), with the `mbstring` and `intl` extensions (already required by GLPI) |
| Database | The one used by GLPI (MariaDB or MySQL) |
| GLPI cron | **Run by the system** (crontab or systemd timer), see [background task](#background-task-glpi-cron): the imports are applied by a GLPI automatic action |

## Installation

1. Download `entitysync-<version>.tar.gz` from the releases page of the repository,
   and optionally check it against the `.sha256` file:

   ```sh
   sha256sum -c entitysync-<version>.tar.gz.sha256
   ```

2. Extract it into the `plugins/` directory of GLPI. The directory **must** be named
   `entitysync`:

   ```sh
   tar -xzf entitysync-<version>.tar.gz -C /var/www/glpi/plugins/
   chown -R www-data:www-data /var/www/glpi/plugins/entitysync   # web server user
   ```

3. Install and activate the plugin, either in *Setup > Plugins* (buttons *Install*
   then *Enable*), or with the console, run as the web server user:

   ```sh
   cd /var/www/glpi
   sudo -u www-data php bin/console plugin:install entitysync --username=<admin login>
   sudo -u www-data php bin/console plugin:activate entitysync
   ```

4. Make sure GLPI's cron is run by the system (see
   [background task](#background-task-glpi-cron)): without it, imports stay waiting.

The installation creates four tables (`glpi_plugin_entitysync_models`, `_links`,
`_runs`, `_jobs`), the plugin right, the automatic action `entitysyncApply` and the
directory `files/_plugins/entitysync`. Profiles that can update the GLPI configuration
(Super-Admin by default) receive all the plugin rights; the other profiles receive
none.

The menu entry is *Administration > Entity import*.

## Upgrade

1. Replace the `plugins/entitysync` directory with the new version (keep the same
   directory name).
2. In *Setup > Plugins*, the plugin is shown as to be updated: run the update, then
   enable it. With the console:

   ```sh
   sudo -u www-data php bin/console plugin:install entitysync --username=<admin login> --force
   sudo -u www-data php bin/console plugin:activate entitysync
   ```

Models, managed entities and history are kept.

**From 1.0.x to 1.1.0**: the update adds the `glpi_plugin_entitysync_jobs` table, a
`status` column to the history and the automatic action `entitysyncApply`. Since
1.1.0, imports are applied in the background: configure GLPI's cron (next section)
before the first import.

## Uninstallation

Uninstalling the plugin (*Setup > Plugins > Uninstall*) drops its tables and rights:
models, links between models and entities, and the import history are lost. **The
entities created by the plugin stay in GLPI** as ordinary entities.

## Background task (GLPI cron)

When a user applies a preview, the plugin queues an **import job**. The GLPI automatic
action **`entitysyncApply`** applies the queued jobs by batches:

- each execution lasts at most 50 seconds, then the job goes on at the next execution;
- the progress is saved after each batch, so an interrupted execution resumes where it
  stopped;
- the plan is recomputed from the current state of GLPI at each execution;
- the action also removes the previews that were not applied after 24 hours.

GLPI 11 only launches automatic actions in *GLPI* mode when someone displays the login
page, which is not enough for a background task. The action is therefore installed in
**CLI** mode, and GLPI's cron must be run by the system every minute, as the web server
user:

```cron
* * * * * www-data php /var/www/glpi/front/cron.php
```

(a systemd timer works too). Check the action in *Setup > Automatic actions >
entitysyncApply*:

| Field | Value |
|---|---|
| Status | Scheduled |
| Run mode | CLI |
| Run frequency | 1 minute (default) |
| Number of operations per batch | 100 by default. A larger batch saves the progress less often; a smaller one gives a finer progress bar. |

The *Execute* button of that page runs it once by hand. As an order of magnitude, one
execution applied about 1,300 creations in 8 seconds on a test instance (measure,
depends on the server). The progress page of an import shows a warning when the action
has not run for 3 minutes after the import was queued.

## Server settings

| Setting | Why |
|---|---|
| `upload_max_filesize`, `post_max_size` (PHP) | Maximum size of the CSV file. The Import tab shows the current limit. |
| `memory_limit` (PHP) | The whole file is loaded in memory. As an order of magnitude, the engine used about 110 MB for 50,000 rows in a test (estimate, depends on the number of columns). |
| Web server timeouts | Only the preview is computed in a web request (about 1.6 s for 1,200 rows measured on a test instance); the changes are applied by the background task, so imports are not limited by `fastcgi_read_timeout`, `request_terminate_timeout` and the like. |
| `max_input_vars` (PHP) | No constraint since 1.1.0: the selection is sent by small AJAX calls, not as one form field per change. |
| `files/_plugins/entitysync` (GLPI) | Uploaded files and preview snapshots of the jobs. Must be writable by the web server **and** by the user running the cron (the same user, normally). Files are removed when the job ends. |

## Rights

The plugin adds an **Entity import** tab to profiles (*Administration > Profiles >
(profile) > Entity import*):

| Right | Allows |
|---|---|
| Read | See the models, their managed entities and their history |
| Create / Update / Delete permanently | Manage the models |
| Apply an import | Upload a file, preview and apply changes |

Applying an import also checks the user's rights on **entities**:

| Operation | Required entity right |
|---|---|
| Addition | Create and Update |
| Link, update | Update |
| Deletion | Delete permanently |

and requires a **recursive** access to the target root entity of the model. The
preview shows the operations the user is not allowed to apply as blocked, with the
reason. The background task has no user session: the rights of the user are checked
when the import is queued, and kept with the job.

## Configuring an import model

Create a model in *Administration > Entity import > Add*. The form has four parts.

### General

| Field | Description |
|---|---|
| Name | Name of the model. |
| Target root entity | The entities are created and managed below this entity. It can be the root entity or any sub-entity. |
| Comments | Free text. |

### CSV file

| Field | Description |
|---|---|
| Delimiter | *Automatic* (detected on the first line among `;`, `,`, tab and `\|`), or fixed. |
| Encoding | *Automatic* (UTF-8 if the file is valid UTF-8, Windows-1252 otherwise), or fixed (UTF-8, Windows-1252, ISO-8859-1, ISO-8859-15). A UTF-8 BOM is ignored. |
| Text delimiter | Character surrounding values that contain the delimiter (default `"`). A quote inside a value is written twice. |

The first row of the file must contain the column names. They must be unique and not
empty. Values are trimmed and blank lines are ignored. Column names are case
sensitive; once a file has been uploaded, the editors suggest its column names.

### Levels

![Levels editor](../images/en/model-levels.png)

Levels are ordered from the top (level 1, directly below the target root entity) to
the bottom. Use the arrows to reorder them.

| Setting | Description |
|---|---|
| Level label | Name of the level, used in messages and in the default name of empty values. |
| Key columns | One or more columns identifying the entity at this level. With several columns (for example *Country* + *City*), the key is the combination of their values. The key must be stable over time: when it changes, the plugin sees a different entity. |
| Name column | Column giving the name of the entity in GLPI. Empty: the key values, joined with ` - `. If the name column is empty on a row, the key is used. |
| Name when empty | Name of the entity created when this level is empty on a row but a deeper level is filled. Default: *No &lt;level&gt;* (for example *No site*). |
| Name replacement rules | Regular expressions applied in order to the name (see below). |
| Comment columns | Each column adds a `Label: value` line to the comment of the entity, in the order of the list. *Skip empty values* omits the lines whose value is empty. **Without comment columns, the plugin does not manage the comment**: existing comments are never overwritten. |

**Replacement rules.** Each rule has a *pattern*, a *replacement* and optional
*flags*. The pattern is a PCRE regular expression written **without delimiters**; the
flags are among `i` (case insensitive), `m`, `s`, `x` and `U`; Unicode is always
enabled. The replacement can reuse captured groups with `$1`, `$2`... Spaces are
normalized after the rules (no double spaces, no leading or trailing space). The
*Sample value* field below the rules shows the result as you type (computed by the
browser, which may differ from the server for advanced syntax).

| Goal | Pattern | Flags | Replacement | Example |
|---|---|---|---|---|
| Remove a prefix | `^SITE[-_ ]` | `i` | *(empty)* | `site_Lyon` → `Lyon` |
| Remove a code in parentheses | `\s*\([^)]*\)$` | | *(empty)* | `Paris Nord (PAR01)` → `Paris Nord` |
| Reorder parts | `^(\S+) - (.+)$` | | `$2 ($1)` | `FR75 - Paris` → `Paris (FR75)` |
| Underscores to spaces | `_` | | ` ` *(a space)* | `Data_Center_Est` → `Data Center Est` |
| Remove leading zeros | `^0+` | | *(empty)* | `00042` → `42` |

A name that is empty after the rules, or longer than 255 characters, puts the row in
error.

### Filter

![Filter editor](../images/en/model-filter.png)

The filter selects the rows of the file to import. It is a tree of **groups** and
**conditions**:

- a group combines its children with **AND** or **OR**; *Add a group* nests a group,
  for example *A AND (B OR C)*;
- **NOT** negates a group or a condition;
- a condition compares a **column** with one or more **values** (one per line) using
  an **operator**; *Aa* makes it case sensitive (insensitive by default).

| Operator | True when the cell... |
|---|---|
| equals / is different from | is equal / not equal to the value (numeric comparison when both are numbers) |
| is in the list / is not in the list | is one of / none of the values |
| contains / does not contain | contains / does not contain the value |
| starts with / ends with | starts / ends with the value |
| matches the regular expression | matches the PCRE expression (without delimiters) |
| is greater / less than (or equal to) | compares as numbers when both are numbers (`1,5` and `1.5` accepted), as dates for `YYYY-MM-DD` and `DD/MM/YYYY` (with an optional time), as text otherwise |
| is empty / is not empty | is empty / not empty (no value needed; a missing column counts as empty) |

With several values, a positive operator is true when **any** value matches; a
negative operator (*is different from*, *is not in the list*, *does not contain*) is
true when **no** value matches. A filter without conditions keeps every row.

**Rows removed by the filter count as absent from the file**: the entities they used
to produce are proposed for deletion. Change a filter with care on a model that is
already in use.

The model is validated when saved: missing key columns, invalid regular expressions or
incomplete conditions are reported and the model is not saved. Column names are
checked against the file at each import.

## Worked example

File `projects.csv`:

```csv
Organisation;Site;Code projet;Projet;Responsable;Budget;Statut
ACME;SITE-Paris;P001;Migration K8s;Alice;5000;Actif
ACME;SITE-Paris;P002;Refonte SI;Alice;1500;Actif
ACME;;P003;Projet orphelin;Alice;;Actif
ACME;site_Lyon;P004;Datacenter;Alice;20000;Fermé
Globex;Nantes;;;Bob;;Actif
Globex;Nantes;P005;Petit projet;Bob;200;Actif
```

Model (the configuration shown in the screenshots above):

| Level | Key | Name | Rules | Comment |
|---|---|---|---|---|
| Organisation | `Organisation` | (key) | | `Responsable` |
| Site | `Site` | (key) | `^SITE[-_ ]` → *(empty)*, flag `i` | |
| Projet | `Code projet` | `Projet` | | `Code projet` (label *Code*), `Budget` |

Filter: `Statut` *is not in the list* `Fermé`, `Annulé` **AND** (`Budget` *is greater
than or equal to* `1000` **OR** `Budget` *is empty*).

Result below the target root entity:

```text
ACME                               Responsable: Alice
├── No site
│   └── Projet orphelin            Code: P003
└── Paris
    ├── Migration K8s              Code: P001 / Budget: 5000
    └── Refonte SI                 Code: P002 / Budget: 1500
Globex                             Responsable: Bob
└── Nantes
```

Line 5 (`Fermé`) and line 7 (budget 200) are removed by the filter; line 4 has no site,
so *No site* is created; line 6 has no project, so it stops at *Nantes*.

## Before the first import

- Try the model on a test instance, or with a test target root entity, before using
  it on the production tree.
- If the tree already exists in GLPI, check the *Links* section of the first preview:
  existing entities with the same names are adopted instead of being duplicated.
- Give the *Apply an import* right only to the people in charge of the reference
  data.

## Troubleshooting

| Symptom | Cause and solution |
|---|---|
| *Level "X": column "Y" does not exist in the file* | Column names are case sensitive and must match the header exactly. Check the delimiter if the whole header is read as one column. |
| Accented characters are garbled | Set the encoding of the model explicitly (Windows-1252 for files saved by Excel on Windows, for example). |
| *You need a recursive access to the target root entity* | Switch to the target entity (or a parent) with the *recursive* option in the entity selector, or grant the profile on that entity recursively. |
| *This import has expired* / *This import no longer exists* | The preview was cancelled, or removed after 24 hours without being applied. Upload the file again. |
| The import stays *Waiting for the background task* | GLPI's cron is not run by the system, or the action `entitysyncApply` is disabled or in *GLPI* mode. See [background task](#background-task-glpi-cron); the *Execute* button of the action runs it once. |
| *Another import of this model is waiting or running* | Only one import per model runs at a time. Wait for it to end (its progress page is linked), or stop it. |
| A file of more than 1,000 changes cannot be applied (HTTP 400) | Version 1.0.x of the plugin: update to 1.1.0 or later. |
| A deletion is blocked: *Still contains...* | The entity still holds items (computers, tickets, user authorizations...). Move or delete them, then import again. |
| A row is in error: *the name "X" is already produced by another key* | Two different keys give the same name under the same parent, which GLPI forbids. Fix the source data, or change the name column or the rules. |
| The entity is not adopted: *already managed by another import model* | Two models target the same entities. Each entity can be managed by one model only. |
| The preview of a very large file takes too long | Raise the web server timeout and `memory_limit`, or apply the changes in several passes: select part of them (the search field helps, for example one organisation), apply, then import the same file again for the rest. Do not split the file itself: rows absent from a file are proposed for deletion. |
