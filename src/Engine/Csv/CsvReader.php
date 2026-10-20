<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Engine\Csv;

use GlpiPlugin\Entitysync\Engine\EngineException;
use GlpiPlugin\Entitysync\Engine\Util\Msg;

/**
 * Reads a CSV file with a mandatory header row.
 *
 * Columns are referenced by header name in the model configuration, so the header
 * must be present and its names must be unique.
 */
final class CsvReader
{
    public const ENCODING_AUTO = 'auto';
    public const DELIMITER_AUTO = 'auto';

    /** Encodings offered in the model form. */
    public const SUPPORTED_ENCODINGS = ['auto', 'UTF-8', 'Windows-1252', 'ISO-8859-1', 'ISO-8859-15'];

    /** Delimiters tried by auto-detection, in priority order. */
    private const CANDIDATE_DELIMITERS = [';', ',', "\t", '|'];

    public function __construct(
        private readonly string $delimiter = self::DELIMITER_AUTO,
        private readonly string $enclosure = '"',
        private readonly string $encoding = self::ENCODING_AUTO,
    ) {}

    /**
     * @throws EngineException When the file cannot be read or has no usable header.
     */
    public function readFile(string $path): CsvData
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new EngineException(Msg::t('Unable to read file "%s".', basename($path)));
        }

        return $this->readString($content);
    }

    /**
     * @throws EngineException When the content has no usable header.
     */
    public function readString(string $content): CsvData
    {
        [$content, $encoding] = $this->toUtf8($content);

        // Strip UTF-8 BOM.
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (trim($content) === '') {
            throw new EngineException(Msg::t('The file is empty.'));
        }

        $delimiter = $this->delimiter === self::DELIMITER_AUTO
            ? $this->detectDelimiter($content)
            : $this->unescapeDelimiter($this->delimiter);

        $enclosure = $this->enclosure === '' ? '"' : mb_substr($this->enclosure, 0, 1);

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new EngineException(Msg::t('Unable to allocate a temporary stream.'));
        }
        fwrite($stream, $content);
        rewind($stream);

        $columns = null;
        $rows = [];
        $line = 0;
        try {
            // Empty escape character: RFC 4180 behaviour (quotes are escaped by doubling them).
            while (($record = fgetcsv($stream, null, $delimiter, $enclosure, '')) !== false) {
                $line++;
                if ($record === [null] || $this->isBlank($record)) {
                    continue;
                }

                $record = array_map(static fn ($value): string => trim((string) $value), $record);

                if ($columns === null) {
                    $columns = $this->validateHeader($record);
                    continue;
                }

                $row = [];
                foreach ($columns as $index => $column) {
                    $row[$column] = $record[$index] ?? '';
                }
                $rows[$line] = $row;
            }
        } finally {
            fclose($stream);
        }

        if ($columns === null) {
            throw new EngineException(Msg::t('The file has no header row.'));
        }

        return new CsvData($columns, $rows, $encoding, $delimiter);
    }

    /**
     * @param list<string> $record
     *
     * @return list<string>
     *
     * @throws EngineException
     */
    private function validateHeader(array $record): array
    {
        $columns = [];
        foreach ($record as $index => $name) {
            if ($name === '') {
                throw new EngineException(Msg::t('Header column #%d has no name.', $index + 1));
            }
            if (in_array($name, $columns, true)) {
                throw new EngineException(Msg::t('Header column "%s" is duplicated.', $name));
            }
            $columns[] = $name;
        }

        return $columns;
    }

    /**
     * @param array<int, string|null> $record
     */
    private function isBlank(array $record): bool
    {
        foreach ($record as $value) {
            if ($value !== null && trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{0: string, 1: string} Converted content and the source encoding.
     */
    private function toUtf8(string $content): array
    {
        $encoding = $this->encoding === '' ? self::ENCODING_AUTO : $this->encoding;

        if ($encoding === self::ENCODING_AUTO) {
            if (str_starts_with($content, "\xEF\xBB\xBF") || mb_check_encoding($content, 'UTF-8')) {
                return [$content, 'UTF-8'];
            }
            // Spreadsheet exports on Windows are the usual non-UTF-8 source.
            $encoding = 'Windows-1252';
        }

        if (strcasecmp($encoding, 'UTF-8') === 0) {
            return [$content, 'UTF-8'];
        }

        $converted = mb_convert_encoding($content, 'UTF-8', $encoding);

        return [$converted, $encoding];
    }

    private function detectDelimiter(string $content): string
    {
        // Look at the first non-empty line, outside quoted sections.
        $firstLine = '';
        foreach (preg_split('/\R/u', $content) ?: [] as $candidate) {
            if (trim($candidate) !== '') {
                $firstLine = $candidate;
                break;
            }
        }
        $unquoted = (string) preg_replace('/"[^"]*"/u', '', $firstLine);

        $best = self::CANDIDATE_DELIMITERS[0];
        $bestCount = 0;
        foreach (self::CANDIDATE_DELIMITERS as $candidate) {
            $count = substr_count($unquoted, $candidate);
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    private function unescapeDelimiter(string $delimiter): string
    {
        return match ($delimiter) {
            '\t', 'tab', 'TAB' => "\t",
            '' => ';',
            default => mb_substr($delimiter, 0, 1),
        };
    }
}
