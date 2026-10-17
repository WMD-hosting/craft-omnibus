<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\helpers;

use DateTime;

/**
 * The `anchorDateRules` setting: which products take another reference day
 * than `anchorDate`. A rule matches a product by its product type handle or by
 * a related element (a category or entry, its descendants included); the first
 * matching rule wins.
 *
 * Rules come from the CP (an editable table, so comma-separated strings) or
 * from config/omnibus.php (arrays); both normalise to the same shape.
 *
 * @since 1.3.0
 */
final class AnchorDateRules
{
    /**
     * @param mixed $raw The setting as stored.
     * @return list<array{date: string, productTypes: list<string>, relatedTo: list<int>}>
     */
    public static function normalize(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $rules = [];
        foreach ($raw as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $date = trim((string)($rule['date'] ?? ''));
            $types = array_values(array_unique(array_filter(array_map('trim', self::list($rule['productTypes'] ?? [])), static fn(string $h) => $h !== '')));
            $related = array_values(array_unique(array_filter(array_map('intval', self::list($rule['relatedTo'] ?? [])), static fn(int $id) => $id > 0)));
            if ($date === '' && !$types && !$related) {
                // An empty table row.
                continue;
            }
            $rules[] = ['date' => $date, 'productTypes' => $types, 'relatedTo' => $related];
        }

        return $rules;
    }

    /**
     * Validation errors of normalised rules, one per broken rule.
     *
     * @param list<array{date: string, productTypes: list<string>, relatedTo: list<int>}> $rules
     * @return list<string>
     */
    public static function errors(array $rules): array
    {
        $errors = [];
        foreach ($rules as $i => $rule) {
            $n = $i + 1;
            if (!self::isDate($rule['date'])) {
                $errors[] = "Rule $n: \"{$rule['date']}\" is not a Y-m-d date.";
            }
            if (!$rule['productTypes'] && !$rule['relatedTo']) {
                $errors[] = "Rule $n names no product type and no related element.";
            }
        }

        return $errors;
    }

    /**
     * The date of the first rule that matches, or null.
     *
     * @param list<array{date: string, productTypes: list<string>, relatedTo: list<int>}> $rules
     * @param callable(int $rule): array<int, true> $relatedIds Element ids related to rule n's
     * elements (or their descendants), asked for only when a rule needs it.
     * @param int[] $elementIds The product's and the variant's ids.
     */
    public static function match(array $rules, ?string $productTypeHandle, array $elementIds, callable $relatedIds): ?string
    {
        foreach ($rules as $i => $rule) {
            if ($productTypeHandle !== null && in_array($productTypeHandle, $rule['productTypes'], true)) {
                return $rule['date'];
            }
            if ($rule['relatedTo']) {
                $related = $relatedIds($i);
                foreach ($elementIds as $id) {
                    if (isset($related[$id])) {
                        return $rule['date'];
                    }
                }
            }
        }

        return null;
    }

    public static function isDate(string $date): bool
    {
        $parsed = DateTime::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /**
     * @return list<string>
     */
    private static function list(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', array_filter($value, 'is_scalar')));
        }
        if (is_scalar($value)) {
            return preg_split('/[\s,;]+/', (string)$value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return [];
    }
}
