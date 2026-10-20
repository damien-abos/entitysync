<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Engine\Tree;

/**
 * Warning or error attached to the CSV or to the plan.
 */
final class Issue
{
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_ERROR = 'error';

    public function __construct(
        public readonly string $severity,
        public readonly string $message,
        public readonly ?int $line = null,
    ) {}

    public static function warning(string $message, ?int $line = null): self
    {
        return new self(self::SEVERITY_WARNING, $message, $line);
    }

    public static function error(string $message, ?int $line = null): self
    {
        return new self(self::SEVERITY_ERROR, $message, $line);
    }

    /**
     * @return array{severity: string, message: string, line: int|null}
     */
    public function toArray(): array
    {
        return ['severity' => $this->severity, 'message' => $this->message, 'line' => $this->line];
    }
}
