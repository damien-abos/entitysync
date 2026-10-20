# Changelog

## 0.0.2

- **Fix**: applying a preview of more than about 1,000 operations failed with HTTP 400,
  because the selection was posted as one form field per operation and PHP drops the
  request beyond `max_input_vars` (1,000 by default). The selection is now kept on the
  server and changed through small AJAX calls.
- Paginated preview: each section is browsed page by page (50, 100 or 500 rows), the
  search is done on the server, and *Select all* / *Select none* and the section
  checkboxes apply to every page matching the search.
- Background application by batches: *Apply* queues an import job, applied by the new
  GLPI automatic action `entitysyncApply` (batch size as its parameter, at most 50 s
  per execution, progress saved after each batch, resumable). A progress page shows the
  status and can stop the job. **GLPI's cron must be run by the system** (see the
  installation guide).
- New table `glpi_plugin_entitysync_jobs`; import runs now record the final status of
  their job; the history shows at most 500 results per run, failures first.
- The dev container runs GLPI's cron every minute; the end-to-end scenario goes
  through the background task and includes a 1,200-row import.

## 0.0.1

- First release for GLPI 11.0.x.
- Import models with target root entity, CSV options, levels (composite keys, name
  column, replacement rules, comment columns, placeholder name for empty values) and
  AND / OR / NOT filter tree.
- Preview panel with additions, links, updates and deletions to select, dependency
  handling and path filter.
- Managed entities tab, import history, profile rights, fr_FR and en_GB translations.
- User guide and installation guide (English and French), developer guide.
- Tooling: GitHub Actions CI (lint, unit tests on PHP 8.2–8.5, end-to-end tests on
  GLPI 11.0.0 and latest 11.0.x, reproducible package, release on tag), dev container
  with GLPI and MariaDB, end-to-end test scenario.
