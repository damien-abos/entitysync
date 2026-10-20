<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync;

use CommonDBTM;
use Glpi\Application\View\TemplateRenderer;
use User;

/**
 * History of applied imports.
 */
class Run extends CommonDBTM
{
    public static $rightname = 'plugin_entitysync_model';

    /** Number of runs listed in the model tab. */
    private const HISTORY_LIMIT = 50;

    /** Number of operation results displayed per run (failures first). */
    private const DETAILS_LIMIT = 500;

    public static function getTypeName($nb = 0)
    {
        return _n('Import run', 'Import runs', $nb, 'entitysync');
    }

    public static function getIcon()
    {
        return 'ti ti-history';
    }

    public static function countForModel(int $modelId): int
    {
        return countElementsInTable(self::getTable(), ['plugin_entitysync_models_id' => $modelId]);
    }

    /**
     * Records an applied import.
     *
     * @param array{results: list<array<string, mixed>>, counts: array<string, int>} $report
     * @param string $status Final status of the job (done, cancelled, failed).
     */
    public static function record(Model $model, string $filename, int $rows, int $selected, array $report, int $userId, string $status = 'done'): int
    {
        $run = new self();
        $id = $run->add([
            'plugin_entitysync_models_id' => $model->getID(),
            'users_id'                    => $userId,
            'status'                      => $status,
            'date'                        => $_SESSION['glpi_currenttime'],
            'filename'                    => mb_substr($filename, 0, 255),
            'nb_rows'                     => $rows,
            'nb_selected'                 => $selected,
            'nb_added'                    => $report['counts']['add'] ?? 0,
            'nb_linked'                   => $report['counts']['link'] ?? 0,
            'nb_updated'                  => $report['counts']['update'] ?? 0,
            'nb_deleted'                  => $report['counts']['delete'] ?? 0,
            'nb_failed'                   => $report['counts']['failed'] ?? 0,
            'nb_skipped'                  => $report['counts']['skipped'] ?? 0,
            'details'                     => json_encode($report['results'], JSON_UNESCAPED_UNICODE),
            '_no_message'                 => true,
        ]);

        return (int) $id;
    }

    public static function showForModel(Model $model): void
    {
        global $DB;

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_entitysync_models_id' => $model->getID()],
            'ORDER' => ['date DESC', 'id DESC'],
            'LIMIT' => self::HISTORY_LIMIT,
        ]);

        $runs = [];
        foreach ($iterator as $row) {
            $details = json_decode((string) ($row['details'] ?? '[]'), true);
            $details = is_array($details) ? $details : [];
            // Failures and skipped operations first, then the others, up to the limit.
            usort($details, static fn (array $a, array $b): int => ((($a['status'] ?? '') === 'done') <=> (($b['status'] ?? '') === 'done')));
            $runs[] = $row + [
                'user_name'     => getUserName((int) $row['users_id']),
                'details_list'  => array_slice($details, 0, self::DETAILS_LIMIT),
                'details_total' => count($details),
            ];
        }

        TemplateRenderer::getInstance()->display('@entitysync/history.html.twig', [
            'runs'          => $runs,
            'highlight'     => (int) ($_GET['run'] ?? 0),
            'limit'         => self::HISTORY_LIMIT,
            'details_limit' => self::DETAILS_LIMIT,
            'user_url'      => User::getFormURL(),
        ]);
    }
}
