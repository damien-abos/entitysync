<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Engine;

/**
 * Blocking error raised by the engine (unreadable file, invalid configuration...).
 */
final class EngineException extends \RuntimeException {}
