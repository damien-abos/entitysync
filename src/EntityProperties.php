<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync;

use AuthLDAP;
use Entity;
use GlpiPlugin\Entitysync\Engine\Config\LevelConfig;
use Infocom;
use State;
use Transfer;

/**
 * Entity properties a level of a model can manage: the fields of the "Advanced
 * information" and "Assets" tabs of an entity (same fields in GLPI 11 and 12).
 *
 * Values are strings, as stored in the model: text fields can be fixed or read in a
 * column of the file; fields with a list of choices take a fixed value among the
 * choices GLPI offers on the entity form (the same keys as GLPI stores).
 */
final class EntityProperties
{
    public const GROUP_ADVANCED = 'advanced';
    public const GROUP_ASSETS = 'assets';

    public const TYPE_TEXT = 'text';
    public const TYPE_CHOICE = 'choice';

    /** Text fields stored as NULL when empty (NULL means "inherit" for them). */
    private const NULL_WHEN_EMPTY = ['agent_base_url'];

    /** Fields whose negative values (never, inherit) are stored in a strategy field. */
    private const STRATEGY_FIELDS = [
        'entities_id_software' => 'entities_strategy_software',
        'transfers_id'         => 'transfers_strategy',
    ];

    /** @var array<string, array<string, string>> */
    private static array $optionsCache = [];

    /**
     * Managed fields, in display order.
     *
     * @return array<string, array{group: string, section: string, label: string, type: string}>
     */
    public static function definitions(): array
    {
        $advanced = __('Values for the generic rules for assignment to entities');
        $ldap = __('Values used in the interface to search users from a LDAP directory');
        $autofill = __('Autofill dates for financial and administrative information');
        $software = \Software::getTypeName(1);
        $transfer = __('Transfer');
        $inventory = __('Automatic inventory');
        $autoupdate = __('Automatically update of the elements related to the computers');
        $connect = __('When connecting or updating the relevant field');
        $disconnect = __('When disconnecting');

        $text = self::TYPE_TEXT;
        $choice = self::TYPE_CHOICE;
        $a = self::GROUP_ADVANCED;
        $s = self::GROUP_ASSETS;

        $definitions = [
            'tag'               => [$a, $advanced, __('Information in inventory tool (TAG) representing the entity'), $text],
            'ldap_dn'           => [$a, $advanced, __('LDAP directory information attribute representing the entity'), $text],
            'mail_domain'       => [$a, $advanced, __('Mail domain surrogates entity'), $text],
            'authldaps_id'      => [$a, $ldap, AuthLDAP::getTypeName(1), $choice],
            'entity_ldapfilter' => [$a, $ldap, __('LDAP filter associated to the entity (if necessary)'), $text],

            'autofill_buy_date'          => [$s, $autofill, __('Date of purchase'), $choice],
            'autofill_order_date'        => [$s, $autofill, __('Order date'), $choice],
            'autofill_delivery_date'     => [$s, $autofill, __('Delivery date'), $choice],
            'autofill_use_date'          => [$s, $autofill, __('Startup date'), $choice],
            'autofill_warranty_date'     => [$s, $autofill, __('Start date of warranty'), $choice],
            'autofill_decommission_date' => [$s, $autofill, __('Decommission date'), $choice],
            'entities_id_software'       => [$s, $software, __('Entity for software creation'), $choice],
            'transfers_id'               => [$s, $transfer, __('Model for automatic entity transfer on inventories'), $choice],
            'agent_base_url'             => [$s, $inventory, __('Agent base URL'), $text],
        ];
        $columns = [
            'contact'  => __('Alternate username'),
            'user'     => \User::getTypeName(1),
            'group'    => \Group::getTypeName(1),
            'location' => \Location::getTypeName(1),
        ];
        foreach ($columns as $key => $label) {
            $definitions["is_{$key}_autoupdate"] = [$s, $autoupdate, sprintf('%s — %s', $label, $connect), $choice];
        }
        $definitions['state_autoupdate_mode'] = [$s, $autoupdate, sprintf('%s — %s', __('Status'), $connect), $choice];
        foreach ($columns as $key => $label) {
            $definitions["is_{$key}_autoclean"] = [$s, $autoupdate, sprintf('%s — %s', $label, $disconnect), $choice];
        }
        $definitions['state_autoclean_mode'] = [$s, $autoupdate, sprintf('%s — %s', __('Status'), $disconnect), $choice];

        return array_map(
            static fn (array $d): array => ['group' => $d[0], 'section' => $d[1], 'label' => $d[2], 'type' => $d[3]],
            $definitions,
        );
    }

    /**
     * @return array<string, string>
     */
    public static function groupLabels(): array
    {
        return [
            self::GROUP_ADVANCED => __('Advanced information'),
            self::GROUP_ASSETS   => _n('Asset', 'Assets', \Session::getPluralNumber()),
        ];
    }

    /**
     * Choices of a field (value => label), as offered by GLPI on the form of a
     * sub-entity; empty for text fields.
     *
     * @param int $targetEntityId Target root of the model: it and its ancestors are the
     *                            entities offered for software creation.
     *
     * @return array<string, string>
     */
    public static function options(string $field, int $targetEntityId = 0): array
    {
        $cacheKey = $field . '#' . $targetEntityId;
        if (isset(self::$optionsCache[$cacheKey])) {
            return self::$optionsCache[$cacheKey];
        }

        $inherit = [(string) Entity::CONFIG_PARENT => __('Inheritance of the parent entity')];
        $options = match ($field) {
            'authldaps_id' => ['0' => __('Default server')] + self::records(AuthLDAP::getTable()),
            'autofill_buy_date' => self::autofill([]),
            'autofill_order_date' => self::autofill([Infocom::COPY_BUY_DATE => __('Copy the date of purchase')]),
            'autofill_delivery_date' => self::autofill([
                Infocom::COPY_BUY_DATE   => __('Copy the date of purchase'),
                Infocom::COPY_ORDER_DATE => __('Copy the order date'),
            ]),
            'autofill_use_date' => self::autofill([
                Infocom::COPY_BUY_DATE      => __('Copy the date of purchase'),
                Infocom::COPY_ORDER_DATE    => __('Copy the order date'),
                Infocom::COPY_DELIVERY_DATE => __('Copy the delivery date'),
            ]),
            'autofill_warranty_date', 'autofill_decommission_date' => self::autofill([
                Infocom::COPY_BUY_DATE      => __('Copy the date of purchase'),
                Infocom::COPY_ORDER_DATE    => __('Copy the order date'),
                Infocom::COPY_DELIVERY_DATE => __('Copy the delivery date'),
            ], false),
            'entities_id_software' => [(string) Entity::CONFIG_NEVER => __('No change of entity')] + $inherit
                + self::ancestorsAndSelf($targetEntityId),
            'transfers_id' => [(string) Entity::CONFIG_NEVER => __('No automatic transfer')] + $inherit
                + self::records(Transfer::getTable()),
            'is_contact_autoupdate', 'is_user_autoupdate', 'is_group_autoupdate', 'is_location_autoupdate'
                => ['0' => __('Do not copy'), '1' => __('Copy')] + $inherit,
            'is_contact_autoclean', 'is_user_autoclean', 'is_group_autoclean', 'is_location_autoclean'
                => ['0' => __('Do not delete'), '1' => __('Delete')] + $inherit,
            'state_autoupdate_mode' => self::stringKeys(State::getBehaviours(__('Copy computer status'), true)),
            'state_autoclean_mode' => self::stringKeys(State::getBehaviours(__('Clear status'), true)),
            default => [],
        };

        return self::$optionsCache[$cacheKey] = $options;
    }

    /**
     * Columns of `glpi_entities` to read to know the current values.
     *
     * @return list<string>
     */
    public static function dbColumns(): array
    {
        return array_merge(array_keys(self::definitions()), array_values(self::STRATEGY_FIELDS));
    }

    /**
     * Current values of the properties of an entity row (strategy fields merged: a
     * negative strategy is the value, as on the form).
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, string|null>
     */
    public static function fromEntityRow(array $row): array
    {
        $values = [];
        foreach (array_keys(self::definitions()) as $field) {
            $value = $row[$field] ?? null;
            $strategyField = self::STRATEGY_FIELDS[$field] ?? null;
            if ($strategyField !== null && (int) ($row[$strategyField] ?? 0) !== 0) {
                $value = $row[$strategyField];
            }
            $values[$field] = $value === null ? null : (string) $value;
        }

        return $values;
    }

    /**
     * Entity input for property values (GLPI moves the negative values of strategy
     * fields into their strategy field itself).
     *
     * @param array<string, string> $properties
     *
     * @return array<string, string|null>
     */
    public static function toEntityInput(array $properties): array
    {
        $input = [];
        foreach ($properties as $field => $value) {
            $input[$field] = $value === '' && in_array($field, self::NULL_WHEN_EMPTY, true) ? null : $value;
        }

        return $input;
    }

    /**
     * Display value of a property (label of a choice, text otherwise).
     */
    public static function format(string $field, ?string $value, int $targetEntityId = 0): string
    {
        $value ??= '';
        $definition = self::definitions()[$field] ?? null;
        if ($definition === null || $definition['type'] === self::TYPE_TEXT || $value === '') {
            return $value;
        }

        return self::options($field, $targetEntityId)[$value]
            ?? sprintf(__('Unknown value (%s)', 'entitysync'), $value);
    }

    /**
     * Validates the properties of the levels of a model.
     *
     * @param list<array<string, mixed>> $levels Levels as posted by the editor.
     *
     * @return list<string> Error messages.
     */
    public static function validate(array $levels, int $targetEntityId): array
    {
        $definitions = self::definitions();
        $errors = [];
        foreach ($levels as $position => $level) {
            $name = trim((string) ($level['label'] ?? '')) ?: (string) ($position + 1);
            foreach ((array) ($level['properties'] ?? []) as $field => $spec) {
                $definition = $definitions[$field] ?? null;
                if ($definition === null) {
                    $errors[] = sprintf(__('Level "%1$s": unknown entity property "%2$s".', 'entitysync'), $name, $field);
                    continue;
                }
                $source = (string) ($spec['source'] ?? '');
                if ($source === LevelConfig::SOURCE_COLUMN) {
                    if ($definition['type'] !== self::TYPE_TEXT) {
                        $errors[] = sprintf(__('Level "%1$s": "%2$s" can only take a fixed value.', 'entitysync'), $name, $definition['label']);
                    } elseif (trim((string) ($spec['column'] ?? '')) === '') {
                        $errors[] = sprintf(__('Level "%1$s": choose the column of "%2$s".', 'entitysync'), $name, $definition['label']);
                    }
                } elseif ($source === LevelConfig::SOURCE_FIXED) {
                    $value = trim((string) ($spec['value'] ?? ''));
                    if ($definition['type'] === self::TYPE_CHOICE && !array_key_exists($value, self::options($field, $targetEntityId))) {
                        $errors[] = sprintf(__('Level "%1$s": choose a value for "%2$s".', 'entitysync'), $name, $definition['label']);
                    }
                } else {
                    $errors[] = sprintf(__('Level "%1$s": choose how "%2$s" is filled.', 'entitysync'), $name, $definition['label']);
                }
            }
        }

        return $errors;
    }

    /**
     * Catalog for the levels editor: groups, sections and fields with their choices
     * (as lists of [value, label] pairs, to keep their order in JSON).
     *
     * @return list<array{group: string, label: string, fields: list<array<string, mixed>>}>
     */
    public static function catalog(int $targetEntityId): array
    {
        $groups = [];
        foreach (self::groupLabels() as $group => $label) {
            $groups[$group] = ['group' => $group, 'label' => $label, 'fields' => []];
        }
        foreach (self::definitions() as $field => $definition) {
            $options = [];
            foreach (self::options($field, $targetEntityId) as $value => $optionLabel) {
                $options[] = [(string) $value, $optionLabel];
            }
            $groups[$definition['group']]['fields'][] = [
                'field'   => $field,
                'section' => $definition['section'],
                'label'   => $definition['label'],
                'type'    => $definition['type'],
                'options' => $options,
            ];
        }

        return array_values($groups);
    }

    /**
     * Choices of an autofill date: no autofill, inheritance, the given copies, the
     * status changes (and, except for the warranty and decommission dates, the copy of
     * the warranty start date).
     *
     * @param array<int, string> $copies
     *
     * @return array<string, string>
     */
    private static function autofill(array $copies, bool $warrantyCopy = true): array
    {
        $options = ['0' => __('No autofill'), (string) Entity::CONFIG_PARENT => __('Inheritance of the parent entity')];
        foreach (self::records(State::getTable()) as $id => $name) {
            $options[Infocom::ON_STATUS_CHANGE . '_' . $id] = sprintf(__('Fill when shifting to state %s'), $name);
        }
        if ($warrantyCopy) {
            $options[(string) Infocom::COPY_WARRANTY_DATE] = __('Copy the start date of warranty');
        }
        foreach ($copies as $value => $label) {
            $options[(string) $value] = $label;
        }

        return $options;
    }

    /**
     * @return array<string, string> id => name
     */
    private static function records(string $table): array
    {
        global $DB;

        $records = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => $table, 'ORDER' => 'name']) as $row) {
            $records[(string) $row['id']] = (string) $row['name'];
        }

        return $records;
    }

    /**
     * @return array<string, string> id => complete name, from the root to the entity
     */
    private static function ancestorsAndSelf(int $entityId): array
    {
        global $DB;

        $ids = array_map('intval', array_values(getAncestorsOf(Entity::getTable(), $entityId)));
        $ids[] = $entityId;
        $entities = [];
        foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => Entity::getTable(), 'WHERE' => ['id' => $ids], 'ORDER' => 'level']) as $row) {
            $entities[(string) $row['id']] = (string) $row['completename'];
        }

        return $entities;
    }

    /**
     * @param array<int|string, string> $options
     *
     * @return array<string, string>
     */
    private static function stringKeys(array $options): array
    {
        $result = [];
        foreach ($options as $value => $label) {
            $result[(string) $value] = $label;
        }

        return $result;
    }
}
