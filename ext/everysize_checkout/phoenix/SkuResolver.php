<?php

declare(strict_types=1);

namespace Everysize\Checkout\Phoenix;

use Everysize\Checkout\Db\DbInterface;
use Everysize\Checkout\Import\ImportException;

/**
 * Resolves an everysize SKU to a Phoenix article. Phoenix has no per-
 * attribute article number, so a size SKU is the base products_model plus
 * the size as an options value of the "Größe"/"Size" option.
 *
 * 1. Exact match on products_model — case-sensitive beats case-insensitive,
 *    active articles beat inactive; two equally good candidates are an
 *    error ("not unique"). Stock is deliberately not considered: a paid
 *    order is imported even when the article is out of stock.
 * 2. Otherwise the part after the last "-" is the size: the base
 *    products_model is looked up the same way and the size is matched as an
 *    options value actually attached to that article (products_attributes).
 */
class SkuResolver
{
    /** Options a size can be attached as (the demo catalog uses both). */
    private const SIZE_OPTIONS = ['Größe', 'Size'];

    private DbInterface $db;

    public function __construct(DbInterface $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{productsId: string, taxClassId: string, model: string, size: ?array{optionId: string, valueId: string}}
     */
    public function resolve(string $sku): array
    {
        // 1. Exact match on products_model (the plain case — a size SKU
        //    never matches, Phoenix has no variant article numbers).
        $row = $this->findArticleByModel($sku);
        $size = null;

        // 2. Otherwise the part after the last "-" is the size: look up
        //    the base model and match the size as an options value of
        //    that article.
        if ($row === null) {
            $separator = strrpos($sku, '-');
            $suffix = $separator === false ? '' : substr($sku, $separator + 1);
            if ($suffix === '') {
                throw new ImportException(sprintf('SKU %s not found in the shop', $sku));
            }
            $row = $this->findArticleByModel(substr($sku, 0, $separator));
            if ($row === null) {
                throw new ImportException(sprintf('SKU %s not found in the shop', $sku));
            }
            $size = $this->resolveSize((string) $row['products_id'], $suffix);
            if ($size === null) {
                throw new ImportException(sprintf('size %s of SKU %s has no matching option value in the shop', $suffix, $sku));
            }
        }

        return [
            'productsId' => (string) $row['products_id'],
            'taxClassId' => (string) $row['products_tax_class_id'],
            'model' => (string) $row['products_model'],
            'size' => $size,
        ];
    }

    /**
     * SKU/model → products row. Exact (case-sensitive) match on
     * products_model beats a case-insensitive one, an active article beats
     * an inactive one; two equally good candidates are an error. Returns
     * null when nothing matches.
     */
    public function findArticleByModel(string $model): ?array
    {
        $rows = $this->db->fetchRows(
            'SELECT products_id, products_status, products_tax_class_id, products_model,
                    (BINARY products_model = BINARY :model) AS EXACT_MATCH
             FROM products
             WHERE LOWER(products_model) = LOWER(:model)',
            [':model' => $model]
        );
        if ($rows === []) {
            return null;
        }
        usort($rows, static function (array $a, array $b): int {
            return [(int) $b['EXACT_MATCH'], (int) $b['products_status']]
                <=> [(int) $a['EXACT_MATCH'], (int) $a['products_status']];
        });
        if (\count($rows) > 1
            && (int) $rows[0]['EXACT_MATCH'] === (int) $rows[1]['EXACT_MATCH']
            && (int) $rows[0]['products_status'] === (int) $rows[1]['products_status']) {
            throw new ImportException(sprintf('SKU %s is not unique in the shop', $model));
        }

        return $rows[0];
    }

    /**
     * The size suffix as an options value of the article's "Größe"/"Size"
     * option. Returns null when the article has no matching option value —
     * only an option value actually attached to the article
     * (products_attributes) counts, so a plain article never gets a bogus
     * attribute row. Two equally good candidates are an error ("not
     * unique").
     *
     * @return ?array{optionId: string, valueId: string}
     */
    public function resolveSize(string $productsId, string $suffix): ?array
    {
        if ($suffix === '') {
            return null;
        }

        $params = [':suffix' => $suffix, ':productsId' => $productsId];
        $placeholders = [];
        foreach (self::SIZE_OPTIONS as $index => $option) {
            $placeholders[] = ':opt'.$index;
            $params[':opt'.$index] = $option;
        }

        // The options table keeps one row per language — the join produces
        // one row per attribute AND language, so the result is deduplicated
        // by products_attributes_id before ranking.
        $rows = $this->db->fetchRows(
            'SELECT pa.products_attributes_id, pa.options_id, pa.options_values_id,
                    (BINARY ov.products_options_values_name = BINARY :suffix) AS EXACT_MATCH
             FROM products_attributes pa
             JOIN products_options po ON po.products_options_id = pa.options_id
               AND po.products_options_name IN ('.implode(', ', $placeholders).')
             JOIN products_options_values ov ON ov.products_options_values_id = pa.options_values_id
             WHERE pa.products_id = :productsId
               AND LOWER(ov.products_options_values_name) = LOWER(:suffix)',
            $params
        );
        $attributes = [];
        foreach ($rows as $row) {
            $attributes[(string) $row['products_attributes_id']] = $row;
        }
        if ($attributes === []) {
            return null;
        }
        $rows = array_values($attributes);
        usort($rows, static function (array $a, array $b): int {
            return (int) $b['EXACT_MATCH'] <=> (int) $a['EXACT_MATCH'];
        });
        if (\count($rows) > 1 && (int) $rows[0]['EXACT_MATCH'] === (int) $rows[1]['EXACT_MATCH']) {
            throw new ImportException(sprintf('size %s is not unique for article %s', $suffix, $productsId));
        }

        return ['optionId' => (string) $rows[0]['options_id'], 'valueId' => (string) $rows[0]['options_values_id']];
    }
}
