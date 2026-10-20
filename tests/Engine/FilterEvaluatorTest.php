<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Tests\Engine;

use GlpiPlugin\Entitysync\Engine\Filter\FilterEvaluator;
use GlpiPlugin\Entitysync\Tests\TestCase;

final class FilterEvaluatorTest extends TestCase
{
    /**
     * @param list<string>|string $values
     *
     * @return array<string, mixed>
     */
    private function cond(string $column, string $operator, array|string $values = [], bool $negate = false, bool $case = false): array
    {
        return [
            'type'           => 'condition',
            'column'         => $column,
            'operator'       => $operator,
            'values'         => (array) $values,
            'negate'         => $negate,
            'case_sensitive' => $case,
        ];
    }

    /**
     * @param list<array<string, mixed>> $children
     *
     * @return array<string, mixed>
     */
    private function group(string $logic, array $children, bool $negate = false): array
    {
        return ['type' => 'group', 'logic' => $logic, 'negate' => $negate, 'children' => $children];
    }

    public function testEmptyFilterKeepsEverything(): void
    {
        $this->assertTrue((new FilterEvaluator(null))->matches(['A' => 'x']));
        $this->assertTrue((new FilterEvaluator([]))->matches([]));
    }

    public function testTextOperators(): void
    {
        $row = ['Status' => 'Active', 'Name' => 'Paris Nord'];
        $cases = [
            ['eq', 'active', true],
            ['eq', 'Inactive', false],
            ['neq', 'Inactive', true],
            ['contains', 'nord', true],
            ['not_contains', 'sud', true],
            ['starts_with', 'paris', true],
            ['ends_with', 'NORD', true],
            ['regex', '^paris\s+n', true],
        ];
        foreach ($cases as [$operator, $value, $expected]) {
            $column = in_array($operator, ['eq', 'neq'], true) ? 'Status' : 'Name';
            $evaluator = new FilterEvaluator($this->group('AND', [$this->cond($column, $operator, $value)]));
            $this->assertSame($expected, $evaluator->matches($row), "$operator $value");
        }
    }

    public function testCaseSensitivity(): void
    {
        $evaluator = new FilterEvaluator($this->group('AND', [$this->cond('S', 'eq', 'active', case: true)]));
        $this->assertFalse($evaluator->matches(['S' => 'Active']));
    }

    public function testListSemantics(): void
    {
        $in = new FilterEvaluator($this->group('AND', [$this->cond('S', 'in', ['A', 'B'])]));
        $notIn = new FilterEvaluator($this->group('AND', [$this->cond('S', 'not_in', ['A', 'B'])]));

        $this->assertTrue($in->matches(['S' => 'b']));
        $this->assertFalse($in->matches(['S' => 'C']));
        $this->assertFalse($notIn->matches(['S' => 'A']));
        $this->assertTrue($notIn->matches(['S' => 'C']));
    }

    public function testNumericDateAndTextOrdering(): void
    {
        $gt = fn (string $value) => new FilterEvaluator($this->group('AND', [$this->cond('V', 'gt', $value)]));

        $this->assertTrue($gt('9')->matches(['V' => '10']), 'numbers are compared numerically');
        $this->assertTrue($gt('1,5')->matches(['V' => '2']), 'decimal comma');
        $this->assertTrue($gt('31/12/2025')->matches(['V' => '01/01/2026']), 'French dates');
        $this->assertTrue($gt('2025-12-31')->matches(['V' => '2026-01-01']), 'ISO dates');
        $this->assertTrue($gt('abc')->matches(['V' => 'abd']), 'text');

        $lte = new FilterEvaluator($this->group('AND', [$this->cond('V', 'lte', '10')]));
        $this->assertTrue($lte->matches(['V' => '10.0']));
    }

    public function testEmptyOperators(): void
    {
        $empty = new FilterEvaluator($this->group('AND', [$this->cond('V', 'empty')]));
        $notEmpty = new FilterEvaluator($this->group('AND', [$this->cond('V', 'not_empty')]));

        $this->assertTrue($empty->matches(['V' => '  ']));
        $this->assertTrue($empty->matches([]), 'missing column counts as empty');
        $this->assertTrue($notEmpty->matches(['V' => 'x']));
    }

    public function testNestedGroupsWithNegation(): void
    {
        // (Country = FR OR Country = BE) AND NOT (Status = Closed)
        $tree = $this->group('AND', [
            $this->group('OR', [$this->cond('Country', 'eq', 'FR'), $this->cond('Country', 'eq', 'BE')]),
            $this->group('AND', [$this->cond('Status', 'eq', 'Closed')], negate: true),
        ]);
        $evaluator = new FilterEvaluator($tree);

        $this->assertTrue($evaluator->matches(['Country' => 'BE', 'Status' => 'Open']));
        $this->assertFalse($evaluator->matches(['Country' => 'FR', 'Status' => 'Closed']));
        $this->assertFalse($evaluator->matches(['Country' => 'DE', 'Status' => 'Open']));

        $negatedCondition = new FilterEvaluator($this->group('AND', [$this->cond('Country', 'eq', 'FR', negate: true)]));
        $this->assertTrue($negatedCondition->matches(['Country' => 'DE']));
    }

    public function testValidation(): void
    {
        $tree = $this->group('AND', [
            $this->cond('Missing', 'eq', 'x'),
            $this->cond('A', 'unknown', 'x'),
            $this->cond('A', 'regex', '(unclosed'),
            $this->cond('A', 'contains', []),
        ]);
        $errors = (new FilterEvaluator($tree))->validate(['A']);

        $this->assertCount(4, $errors, implode(' | ', $errors));
        $this->assertSame(['Missing', 'A'], (new FilterEvaluator($tree))->referencedColumns());
    }
}
