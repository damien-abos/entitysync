<?php

/**
 * Entity Sync plugin for GLPI
 *
 * End-to-end test: drives a running GLPI instance over HTTP (like a browser would)
 * and checks the resulting entity tree in the database.
 *
 * The scenario creates its own target root entity, so it can run on a development
 * instance; everything it creates is removed at the end when it succeeds.
 *
 * Environment variables:
 *   GLPI_URL        Base URL of GLPI            (default http://127.0.0.1:8080)
 *   GLPI_USER       Super-Admin login           (default glpi)
 *   GLPI_PASSWORD   Its password                (default glpi)
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD  GLPI database (default 127.0.0.1, 3306, glpi, glpi, glpi)
 *   GLPI_LOG_DIR    GLPI log directory; when set, new PHP errors fail the test
 *
 * Usage: php tests/e2e/run.php
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

const FIXTURES = __DIR__ . '/fixtures';

final class E2EFailure extends RuntimeException {}

/**
 * Minimal HTTP client keeping the GLPI session cookie.
 */
final class Browser
{
    private string $cookieJar;

    public function __construct(private readonly string $baseUrl)
    {
        $this->cookieJar = (string) tempnam(sys_get_temp_dir(), 'entitysync-e2e-');
    }

    public function __destruct()
    {
        @unlink($this->cookieJar);
    }

    /**
     * @return array{status: int, location: string|null, body: string}
     */
    public function get(string $path, bool $ajax = false): array
    {
        return $this->request('GET', $path, null, $ajax);
    }

    /**
     * @param array<string, mixed>|string $fields Form fields, or an already encoded body.
     *
     * @return array{status: int, location: string|null, body: string}
     */
    public function post(string $path, array|string $fields, bool $multipart = false): array
    {
        if (is_array($fields) && !$multipart) {
            $fields = http_build_query($fields);
        }

        return $this->request('POST', $path, $fields);
    }

    /**
     * Returns a fresh CSRF token (tokens are single-use in GLPI).
     */
    public function csrfToken(): string
    {
        $page = $this->get('/front/central.php');
        if (preg_match('/name="_glpi_csrf_token" value="([0-9a-f]{64})"/', $page['body'], $m) !== 1) {
            throw new E2EFailure('No CSRF token found on the central page (not logged in?).');
        }

        return $m[1];
    }

    /**
     * Loads an item tab the way GLPI's tab widget does.
     */
    public function tab(string $itemtype, int $id, string $tab, string $target): array
    {
        return $this->get('/ajax/common.tabs.php?' . http_build_query([
            '_glpi_tab' => $tab,
            '_target'   => $target,
            '_itemtype' => $itemtype,
            'id'        => $id,
        ]), true);
    }

    /**
     * @param array<string, mixed>|string|null $body
     *
     * @return array{status: int, location: string|null, body: string}
     */
    private function request(string $method, string $path, array|string|null $body, bool $ajax = false): array
    {
        $url = str_starts_with($path, 'http') ? $path : $this->baseUrl . $path;
        $curl = curl_init($url);
        $headers = [];
        if ($ajax) {
            $headers[] = 'X-Requested-With: XMLHttpRequest';
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 120,
        ]);
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($curl);
        if ($response === false) {
            throw new E2EFailure(sprintf('%s %s failed: %s', $method, $path, curl_error($curl)));
        }
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $location = curl_getinfo($curl, CURLINFO_REDIRECT_URL) ?: null;

        return ['status' => $status, 'location' => $location, 'body' => (string) $response];
    }
}

/**
 * Operations rendered in the preview panel.
 */
final class Preview
{
    /** @var list<array{id: string, type: string, path: string, blocked: bool, checked: bool}> */
    public array $operations = [];

    public string $token = '';

    public bool $nothingToChange = false;

    public int $errors = 0;

    public function __construct(public readonly string $html)
    {
        if (preg_match('/name="token" value="([0-9a-f]{32})"/', $html, $m) === 1) {
            $this->token = $m[1];
        }
        $this->nothingToChange = !str_contains($html, 'id="es-apply-form"') && str_contains($html, 'ti-circle-check');
        $this->errors = substr_count($html, 'ti-circle-x text-danger');

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//tr[@data-es-row]') ?: [] as $row) {
            /** @var DOMElement $row */
            $input = $xpath->query('.//input[@data-es-op]', $row)?->item(0);
            if (!$input instanceof DOMElement) {
                continue;
            }
            $path = trim((string) $xpath->query('.//td[contains(@class,"entitysync-path")]/strong', $row)?->item(0)?->textContent);
            $this->operations[] = [
                'id'      => $input->getAttribute('data-es-op'),
                'type'    => $input->getAttribute('data-es-type'),
                'path'    => $path,
                'blocked' => $input->hasAttribute('disabled'),
                'checked' => $input->hasAttribute('checked'),
            ];
        }
    }

    /**
     * @return array<string, list<string>> type => sorted paths (blocked ones suffixed with " [blocked]")
     */
    public function summary(): array
    {
        $summary = ['add' => [], 'link' => [], 'update' => [], 'delete' => []];
        foreach ($this->operations as $op) {
            $summary[$op['type']][] = $op['path'] . ($op['blocked'] ? ' [blocked]' : '');
        }
        foreach ($summary as &$paths) {
            sort($paths);
        }

        return $summary;
    }

    /**
     * @return list<string> Ids of the operations that can be selected.
     */
    public function selectableIds(): array
    {
        return array_values(array_map(
            static fn (array $op): string => $op['id'],
            array_filter($this->operations, static fn (array $op): bool => !$op['blocked']),
        ));
    }
}

final class Scenario
{
    private Browser $browser;
    private mysqli $db;
    private int $rootId = 0;
    private int $modelId = 0;
    private string $rootPrefix = '';
    private int $logOffset = 0;
    private ?string $logFile;
    private int $step = 0;

    public function __construct()
    {
        $this->browser = new Browser(rtrim(getenv('GLPI_URL') ?: 'http://127.0.0.1:8080', '/'));
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->db = new mysqli(
            getenv('DB_HOST') ?: '127.0.0.1',
            getenv('DB_USER') ?: 'glpi',
            getenv('DB_PASSWORD') ?: 'glpi',
            getenv('DB_NAME') ?: 'glpi',
            (int) (getenv('DB_PORT') ?: 3306),
        );
        $this->db->set_charset('utf8mb4');

        $logDir = getenv('GLPI_LOG_DIR') ?: null;
        $this->logFile = $logDir !== null ? rtrim($logDir, '/') . '/php-errors.log' : null;
        if ($this->logFile !== null && is_file($this->logFile)) {
            $this->logOffset = (int) filesize($this->logFile);
        }
    }

    public function run(): void
    {
        $this->login();
        $this->createRootAndModel();
        $this->checkModelScreens();

        $this->section('Initial import');
        $preview = $this->upload('01-initial.csv');
        $this->assertSame([
            'add' => [
                'ACME', 'ACME > Paris', 'ACME > Paris > Migration K8s', 'ACME > Paris > Refonte SI',
                'ACME > Sans site', 'ACME > Sans site > Projet orphelin', 'Globex', 'Globex > Nantes',
            ],
            'link' => [], 'update' => [], 'delete' => [],
        ], $preview->summary(), 'initial preview');
        $this->apply($preview, $preview->selectableIds());
        $this->assertTree([
            'ACME'                               => 'Responsable: Alice',
            'ACME > Paris'                       => null,
            'ACME > Paris > Migration K8s'       => "Code: P001\nBudget: 5000",
            'ACME > Paris > Refonte SI'          => "Code: P002\nBudget: 1500",
            'ACME > Sans site'                   => null,
            'ACME > Sans site > Projet orphelin' => 'Code: P003',
            'Globex'                             => 'Responsable: Bob',
            'Globex > Nantes'                    => null,
        ]);
        $this->assertLastRun(['nb_added' => 8, 'nb_failed' => 0, 'nb_skipped' => 0]);

        $this->section('Re-import of the same file');
        $preview = $this->upload('01-initial.csv');
        $this->assertTrue($preview->nothingToChange, 'second import proposes nothing');
        $this->cancel($preview);

        $this->section('Changes, adoption, collision and blocked deletions');
        $unmanagedId = $this->createEntity('initech', $this->rootId);
        $computerId = $this->createComputer('E2E-PC', $this->entityId('Globex > Nantes'));
        $preview = $this->upload('02-changes.csv');
        $this->assertSame(1, $preview->errors, 'one row in error (sibling name collision)');
        $this->assertSame([
            'add'    => ['ACME > Paris > Projet orphelin'],
            'link'   => ['Initech'],
            'update' => ['ACME', 'ACME > Paris > Refonte du SI'],
            'delete' => ['ACME > Sans site', 'ACME > Sans site > Projet orphelin', 'Globex > Nantes [blocked]', 'Globex [blocked]'],
        ], $preview->summary(), 'preview of the changes');
        $this->apply($preview, $preview->selectableIds());
        $this->assertTree([
            'ACME'                           => 'Responsable: Alice Martin',
            'ACME > Paris'                   => null,
            'ACME > Paris > Migration K8s'   => "Code: P001\nBudget: 5000",
            'ACME > Paris > Projet orphelin' => 'Code: P003',
            'ACME > Paris > Refonte du SI'   => "Code: P002\nBudget: 1500",
            'Globex'                         => 'Responsable: Bob',
            'Globex > Nantes'                => null,
            'Initech'                        => 'Responsable: Carol',
        ]);
        $this->assertSame($unmanagedId, $this->entityId('Initech'), 'the existing entity was adopted, not recreated');
        $this->assertLastRun(['nb_added' => 1, 'nb_linked' => 1, 'nb_updated' => 2, 'nb_deleted' => 2, 'nb_failed' => 0]);

        $this->section('Selecting a parent deletion pulls its managed children (server side)');
        $preview = $this->upload('03-empty.csv');
        $this->assertSame(
            ['ACME', 'ACME > Paris', 'ACME > Paris > Migration K8s', 'ACME > Paris > Projet orphelin',
                'ACME > Paris > Refonte du SI', 'Globex > Nantes [blocked]', 'Globex [blocked]', 'Initech'],
            $preview->summary()['delete'],
            'empty file proposes every managed entity for deletion',
        );
        $this->assertTrue(
            !in_array(true, array_column(array_filter($preview->operations, fn ($o) => $o['type'] === 'delete'), 'checked'), true),
            'deletions are not selected by default',
        );
        // Deleting ACME requires deleting its managed children first: select ACME only.
        $acme = array_values(array_filter($preview->operations, static fn (array $op): bool => $op['path'] === 'ACME'))[0]['id'];
        $this->apply($preview, [$acme]);
        $this->assertTree([
            'Globex'          => 'Responsable: Bob',
            'Globex > Nantes' => null,
            'Initech'         => 'Responsable: Carol',
        ]);
        $this->assertLastRun(['nb_deleted' => 5, 'nb_failed' => 0, 'nb_skipped' => 0]);

        $this->section('Cleanup');
        $this->purge('/front/computer.form.php', $computerId);
        $preview = $this->upload('03-empty.csv');
        $this->assertSame(['Globex', 'Globex > Nantes', 'Initech'], $preview->summary()['delete'], 'nothing blocked any more');
        $this->apply($preview, $preview->selectableIds());
        $this->assertTree([]);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM glpi_plugin_entitysync_links WHERE plugin_entitysync_models_id = ?', [$this->modelId]), 'no link left');
        $this->purge('/plugins/entitysync/front/model.form.php', $this->modelId);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM glpi_plugin_entitysync_runs WHERE plugin_entitysync_models_id = ?', [$this->modelId]), 'history purged with the model');
        $this->purge('/front/entity.form.php', $this->rootId);

        $this->checkLogs();
        $this->say("\nE2E scenario passed ({$this->step} checks).");
    }

    private function login(): void
    {
        $this->section('Login');
        $page = $this->browser->get('/');
        if (preg_match('/name="_glpi_csrf_token" value="([0-9a-f]{64})"/', $page['body'], $m) !== 1) {
            throw new E2EFailure('Login page not reachable at ' . (getenv('GLPI_URL') ?: 'http://127.0.0.1:8080'));
        }
        $response = $this->browser->post('/front/login.php', [
            'login_name'       => getenv('GLPI_USER') ?: 'glpi',
            'login_password'   => getenv('GLPI_PASSWORD') ?: 'glpi',
            '_glpi_csrf_token' => $m[1],
            'noAUTO'           => 0,
            'redirect'         => '',
        ]);
        $this->assertTrue(str_contains((string) $response['location'], '/front/central.php'), 'login redirects to the central page');
    }

    private function createRootAndModel(): void
    {
        $this->section('Model creation');
        $this->rootId = $this->createEntity('E2E entitysync ' . date('Ymd-His'), 0);
        $this->rootPrefix = (string) $this->scalar('SELECT completename FROM glpi_entities WHERE id = ?', [$this->rootId]) . ' > ';

        $response = $this->browser->post('/plugins/entitysync/front/model.form.php', [
            'name'               => 'E2E model',
            'entities_id_target' => $this->rootId,
            'csv_delimiter'      => 'auto',
            'csv_encoding'       => 'auto',
            'csv_enclosure'      => '"',
            'levels_json'        => file_get_contents(FIXTURES . '/levels.json'),
            'filter_json'        => file_get_contents(FIXTURES . '/filter.json'),
            'add'                => 1,
            '_glpi_csrf_token'   => $this->browser->csrfToken(),
        ]);
        if (preg_match('/model\.form\.php\?id=(\d+)/', (string) $response['location'], $m) !== 1) {
            throw new E2EFailure('Model creation did not redirect to the new model (HTTP ' . $response['status'] . ').');
        }
        $this->modelId = (int) $m[1];
        $this->assertTrue($this->modelId > 0, 'model created');

        // An invalid regular expression is rejected and leaves the model untouched.
        $this->browser->post('/plugins/entitysync/front/model.form.php', [
            'id'               => $this->modelId,
            'levels_json'      => '[{"label":"X","key_columns":["Organisation"],"name_rules":[{"pattern":"(","replacement":""}]}]',
            'update'           => 1,
            '_glpi_csrf_token' => $this->browser->csrfToken(),
        ]);
        $this->assertSame(3, (int) $this->scalar('SELECT JSON_LENGTH(levels) FROM glpi_plugin_entitysync_models WHERE id = ?', [$this->modelId]), 'invalid configuration rejected');
    }

    private function checkModelScreens(): void
    {
        $this->assertSame(200, $this->browser->get('/plugins/entitysync/front/model.php')['status'], 'model list');
        $this->assertSame(200, $this->browser->get('/plugins/entitysync/front/model.form.php?id=' . $this->modelId)['status'], 'model form');
        $target = '/plugins/entitysync/front/model.form.php';
        foreach (['main', '1', '2', '3'] as $tab) {
            $response = $this->browser->tab('GlpiPlugin\\Entitysync\\Model', $this->modelId, 'GlpiPlugin\\Entitysync\\Model$' . $tab, $target);
            $this->assertSame(200, $response['status'], "model tab $tab");
        }
        $response = $this->browser->tab('Profile', 4, 'GlpiPlugin\\Entitysync\\ProfileRights$1', '/front/profile.form.php');
        $this->assertTrue($response['status'] === 200 && str_contains($response['body'], '_plugin_entitysync_model['), 'profile rights tab');
    }

    private function upload(string $fixture): Preview
    {
        $response = $this->browser->post('/plugins/entitysync/front/import.php', [
            'models_id'        => $this->modelId,
            'preview'          => 1,
            '_glpi_csrf_token' => $this->browser->csrfToken(),
            'csv_file'         => new CURLFile(FIXTURES . '/' . $fixture, 'text/csv', $fixture),
        ], true);
        if (!str_contains((string) $response['location'], 'import.php?token=')) {
            throw new E2EFailure("Upload of $fixture did not redirect to the preview (HTTP {$response['status']}).");
        }
        $page = $this->browser->get((string) $response['location']);
        $this->assertSame(200, $page['status'], "preview of $fixture");

        return new Preview($page['body']);
    }

    /**
     * @param list<string> $ids
     */
    private function apply(Preview $preview, array $ids): void
    {
        $body = http_build_query([
            'token'            => $preview->token,
            'apply'            => 1,
            '_glpi_csrf_token' => $this->browser->csrfToken(),
        ]);
        foreach ($ids as $id) {
            $body .= '&' . rawurlencode('ops[]') . '=' . rawurlencode($id);
        }
        $response = $this->browser->post('/plugins/entitysync/front/import.php', $body);
        $this->assertTrue(str_contains((string) $response['location'], '&run='), 'apply redirects to the run history');
    }

    private function cancel(Preview $preview): void
    {
        $response = $this->browser->post('/plugins/entitysync/front/import.php', [
            'token'            => $preview->token,
            'cancel'           => 1,
            '_glpi_csrf_token' => $this->browser->csrfToken(),
        ]);
        $this->assertTrue(str_contains((string) $response['location'], 'model.form.php?id='), 'cancel returns to the model');
    }

    private function createEntity(string $name, int $parentId): int
    {
        $response = $this->browser->post('/front/entity.form.php', [
            'name'             => $name,
            'entities_id'      => $parentId,
            'add'              => 1,
            '_glpi_csrf_token' => $this->browser->csrfToken(),
        ]);
        if (preg_match('/entity\.form\.php\?id=(\d+)/', (string) $response['location'], $m) !== 1) {
            throw new E2EFailure("Entity \"$name\" could not be created.");
        }

        return (int) $m[1];
    }

    private function createComputer(string $name, int $entityId): int
    {
        $response = $this->browser->post('/front/computer.form.php', [
            'name'             => $name,
            'entities_id'      => $entityId,
            'add'              => 1,
            '_glpi_csrf_token' => $this->browser->csrfToken(),
        ]);
        if (preg_match('/computer\.form\.php\?id=(\d+)/', (string) $response['location'], $m) !== 1) {
            throw new E2EFailure("Computer \"$name\" could not be created.");
        }

        return (int) $m[1];
    }

    private function purge(string $form, int $id): void
    {
        $this->browser->post($form, [
            'id'               => $id,
            'purge'            => 1,
            '_glpi_csrf_token' => $this->browser->csrfToken(),
        ]);
    }

    private function entityId(string $relativePath): int
    {
        $id = $this->scalar('SELECT id FROM glpi_entities WHERE completename = ?', [$this->rootPrefix . $relativePath]);
        if ($id === null) {
            throw new E2EFailure("Entity \"$relativePath\" not found.");
        }

        return (int) $id;
    }

    /**
     * @param array<string, string|null> $expected Relative path => comment.
     */
    private function assertTree(array $expected): void
    {
        $statement = $this->db->prepare('SELECT completename, comment FROM glpi_entities WHERE completename LIKE ? ORDER BY completename');
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->rootPrefix) . '%';
        $statement->bind_param('s', $like);
        $statement->execute();
        $actual = [];
        foreach ($statement->get_result() as $row) {
            $comment = $row['comment'] === null || $row['comment'] === '' ? null : str_replace("\r\n", "\n", $row['comment']);
            $actual[substr($row['completename'], strlen($this->rootPrefix))] = $comment;
        }
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual, 'entity tree');
    }

    /**
     * @param array<string, int> $expected
     */
    private function assertLastRun(array $expected): void
    {
        $result = $this->db->query(sprintf(
            'SELECT %s FROM glpi_plugin_entitysync_runs WHERE plugin_entitysync_models_id = %d ORDER BY id DESC LIMIT 1',
            implode(', ', array_keys($expected)),
            $this->modelId,
        ));
        $row = array_map('intval', (array) $result->fetch_assoc());
        $this->assertSame($expected, $row, 'last run counters');
    }

    /**
     * @param list<int|string> $params
     */
    private function scalar(string $sql, array $params): mixed
    {
        $statement = $this->db->prepare($sql);
        $statement->bind_param(str_repeat('s', count($params)), ...array_map('strval', $params));
        $statement->execute();
        $row = $statement->get_result()->fetch_row();

        return $row[0] ?? null;
    }

    private function checkLogs(): void
    {
        if ($this->logFile === null || !is_file($this->logFile)) {
            return;
        }
        $new = (string) file_get_contents($this->logFile, false, null, $this->logOffset);
        $lines = array_filter(explode("\n", $new), static fn (string $l): bool => trim($l) !== '' && !str_contains($l, 'Test logger'));
        $this->assertSame([], array_values($lines), 'no new entry in php-errors.log');
    }

    private function section(string $title): void
    {
        $this->say("\n== $title");
    }

    private function assertTrue(bool $condition, string $label): void
    {
        $this->step++;
        if (!$condition) {
            throw new E2EFailure("Check failed: $label");
        }
        $this->say("  ok  $label");
    }

    private function assertSame(mixed $expected, mixed $actual, string $label): void
    {
        $this->step++;
        if ($expected !== $actual) {
            throw new E2EFailure(sprintf(
                "Check failed: %s\n--- expected\n%s\n--- actual\n%s",
                $label,
                json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            ));
        }
        $this->say("  ok  $label");
    }

    private function say(string $line): void
    {
        fwrite(STDOUT, $line . "\n");
    }
}

try {
    (new Scenario())->run();
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\nE2E FAILURE: " . $e->getMessage() . "\n");
    exit(1);
}
