<?php

/**
 * Entity Sync plugin for GLPI
 *
 * Extracts the translatable strings of the plugin into a gettext template (.pot).
 *
 * Recognized calls, in PHP and Twig files:
 *   __('text', 'entitysync'), __s('text', 'entitysync'),
 *   _n('singular', 'plural', $n, 'entitysync'),
 *   Msg::t('text', ...)   (engine messages, translated with the plugin domain at runtime)
 *
 * The output is deterministic (no creation date, sorted references) so that CI can
 * check that the committed template is up to date.
 *
 * Usage: php tools/extract-locales.php [output.pot]   (default: locales/entitysync.pot)
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

const DOMAIN = 'entitysync';
const SCAN_DIRS = ['front', 'src', 'templates'];
const SCAN_FILES = ['setup.php', 'hook.php'];

$root = dirname(__DIR__);
$output = $argv[1] ?? $root . '/locales/entitysync.pot';

$string = "('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")";
$patterns = [
    // __('text', 'domain') and __s('text', 'domain')
    'single' => '/(?<![\w>:$])__s?\(\s*' . $string . '\s*,\s*' . $string . '/',
    // _n('singular', 'plural', $n, 'domain')
    'plural' => '/(?<![\w>:$])_n\(\s*' . $string . '\s*,\s*' . $string . '\s*,\s*[^,()]+(?:\([^()]*\))?\s*,\s*' . $string . '/',
    // Msg::t('text', ...)
    'engine' => '/Msg::t\(\s*' . $string . '/',
];

/**
 * Decodes a PHP/Twig string literal.
 */
function unquote(string $literal): string
{
    $quote = $literal[0];
    $body = substr($literal, 1, -1);
    if ($quote === "'") {
        return str_replace(["\\'", '\\\\'], ["'", '\\'], $body);
    }

    return stripcslashes($body);
}

function poEscape(string $text): string
{
    return addcslashes($text, "\\\"\n\t");
}

$files = SCAN_FILES;
foreach (SCAN_DIRS as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (preg_match('/\.(php|twig)$/', $file->getFilename()) === 1) {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
}
sort($files);

/** @var array<string, array{plural: string|null, refs: array<string, true>}> $entries */
$entries = [];
foreach ($files as $file) {
    $content = (string) file_get_contents($root . '/' . $file);

    foreach ($patterns as $kind => $pattern) {
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $line = substr_count(substr($content, 0, $match[0][1]), "\n") + 1;
            $plural = null;
            switch ($kind) {
                case 'single':
                    if (unquote($match[2][0]) !== DOMAIN) {
                        continue 2;
                    }
                    $msgid = unquote($match[1][0]);
                    break;
                case 'plural':
                    if (unquote($match[3][0]) !== DOMAIN) {
                        continue 2;
                    }
                    $msgid = unquote($match[1][0]);
                    $plural = unquote($match[2][0]);
                    break;
                default:
                    $msgid = unquote($match[1][0]);
            }
            $entries[$msgid] ??= ['plural' => $plural, 'refs' => []];
            $entries[$msgid]['plural'] ??= $plural;
            $entries[$msgid]['refs']["$file:$line"] = true;
        }
    }
}

$out = [
    '# Entity Sync plugin for GLPI',
    '# This file is distributed under the same license as the plugin (GPL-3.0-or-later).',
    'msgid ""',
    'msgstr ""',
    '"Project-Id-Version: entitysync\n"',
    '"MIME-Version: 1.0\n"',
    '"Content-Type: text/plain; charset=UTF-8\n"',
    '"Content-Transfer-Encoding: 8bit\n"',
    '"Plural-Forms: nplurals=INTEGER; plural=EXPRESSION;\n"',
    '',
];
foreach ($entries as $msgid => $entry) {
    $refs = array_keys($entry['refs']);
    $out[] = '#: ' . implode(' ', $refs);
    if (preg_match('/%(\d+\$)?[sd]/', $msgid) === 1) {
        $out[] = '#, php-format';
    }
    $out[] = 'msgid "' . poEscape($msgid) . '"';
    if ($entry['plural'] !== null) {
        $out[] = 'msgid_plural "' . poEscape($entry['plural']) . '"';
        $out[] = 'msgstr[0] ""';
        $out[] = 'msgstr[1] ""';
    } else {
        $out[] = 'msgstr ""';
    }
    $out[] = '';
}

file_put_contents($output, implode("\n", $out));
fwrite(STDERR, sprintf("%d strings written to %s\n", count($entries), $output));
