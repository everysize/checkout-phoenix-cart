<?php

declare(strict_types=1);

namespace Everysize\Checkout\Phoenix;

use Everysize\Checkout\Api\Amount;
use Everysize\Checkout\Api\Client;
use Everysize\Checkout\Db\DbInterface;
use Everysize\Checkout\Import\ImportException;
use Everysize\Checkout\Import\OrderImporter;
use Everysize\Checkout\Import\PriceCalculator;
use Everysize\Checkout\OrderLinks;
use Everysize\Checkout\Settings;

/**
 * Creates a Phoenix order from an everysize order: validates before writing
 * anything, then in one transaction resolves the customer (existing account
 * or guest), inserts orders (with the columns the checkout's
 * insert_order.php writes), orders_products, the size as an
 * orders_products_attributes row (Phoenix has no variant article number —
 * see the SKU matching below), orders_total (titles, texts and sort orders
 * like the ot_* modules), an orders_status_history entry and reduces stock
 * like update_stock.php. Never sends customer mail during the import; a
 * REVOKED order is created and immediately put into the cancellation status
 * without stock reduction. The merchant order number is reported by the
 * sync AFTER the commit.
 *
 * SKU matching is done by the SkuResolver: 1. exact match on
 * products_model; 2. otherwise the part after the last "-" is the size,
 * matched as an options value of the base article's "Größe"/"Size" option
 * (case-sensitive first), so the size shows in the order detail, invoice
 * and packing slip via an orders_products_attributes row.
 */
class PhoenixOrderImporter implements OrderImporter
{
    private const PAYMENT_METHOD = 'everysize (PayPal)';
    private const SHIPPING_METHOD = 'everysize Versand';

    private DbInterface $db;
    private Settings $settings;
    private OrderLinks $links;
    private ?PhoenixOrderMailer $mailer;
    private SkuResolver $skuResolver;
    private ?array $defaultLanguage = null;

    public function __construct(DbInterface $db, Settings $settings, OrderLinks $links, ?PhoenixOrderMailer $mailer = null)
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->links = $links;
        $this->mailer = $mailer;
        $this->skuResolver = new SkuResolver($db);
    }

    /**
     * @param array<string, mixed> $esOrder order as returned by the everysize API
     *
     * @return array{orderId: string, orderNumber: string, warnings: string[]}
     */
    public function import(array $esOrder): array
    {
        // --- Validation, before writing anything ---------------------------------
        $everysizeOrderId = $this->requireString($esOrder, 'everysizeOrderId');
        $customer = \is_array($esOrder['customer'] ?? null) ? $esOrder['customer'] : [];
        $email = $this->requireString($customer, 'email', 'customer.email');
        // Information only: the customer keeps the forwarding address above.
        $originalEmail = filter_var(trim((string) ($customer['originalEmail'] ?? '')), \FILTER_VALIDATE_EMAIL) ?: '';
        $currency = strtoupper((string) ($esOrder['currency'] ?? 'EUR'));
        if ($currency !== 'EUR') {
            throw new ImportException(sprintf('%s: order currency %s is not the shop currency EUR', $everysizeOrderId, $currency));
        }

        $billing = $this->address($esOrder['billingAddress'] ?? null, 'billingAddress');
        $shipping = \is_array($esOrder['shippingAddress'] ?? null) && $esOrder['shippingAddress'] !== []
            ? $this->address($esOrder['shippingAddress'], 'shippingAddress')
            : $billing;
        $revoked = strtoupper((string) ($esOrder['status'] ?? '')) === Client::STATUS_REVOKED;
        $warnings = [];

        $language = $this->defaultLanguage();
        $lines = $this->resolveLines($everysizeOrderId, $esOrder['lineItems'] ?? null, $shipping['countryId'], $language, $warnings);
        $prices = PriceCalculator::calculate(
            array_map(static function (array $line): array {
                return ['gross' => $line['gross'], 'quantity' => $line['quantity'], 'vat' => $line['vat']];
            }, $lines),
            Amount::parse($esOrder['shippingCosts'] ?? 0, 'shippingCosts')
        );
        if (isset($esOrder['grossPrice'])) {
            $warning = PriceCalculator::checkTotal($esOrder['grossPrice'], $prices['totalOrderSum'], $everysizeOrderId);
            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        $payment = \is_array($esOrder['payment'] ?? null) ? $esOrder['payment'] : [];
        $payPalCaptureId = (string) ($payment['resourceId'] ?? $payment['transactionId'] ?? '');
        $orderDate = $this->localDate($esOrder['created'] ?? null, 'created');

        // The tax title of an orders_total row is the tax description of the
        // resolved rate (like the checkout's tax_groups keys). String keys
        // (like the vatGroups keys) — float array keys drift and are
        // truncated to int in PHP.
        $taxDescriptions = [];
        foreach ($lines as $line) {
            $key = $this->rateKey((float) $line['vat']);
            if ($line['taxDescription'] !== '' && !isset($taxDescriptions[$key])) {
                $taxDescriptions[$key] = $line['taxDescription'];
            }
        }

        $importStatus = (string) $this->settings->getImportStatus();
        $cancelStatus = (string) ($this->settings->getCancellationStatuses()[0] ?? '0');
        $orderStatus = $revoked ? $cancelStatus : $importStatus;

        // --- Writing -------------------------------------------------------------
        $this->db->beginTransaction();
        try {
            [$customerId] = $this->resolveCustomer($email);
            $ordersId = $this->insertOrder(
                $everysizeOrderId, $email, $customerId,
                $billing, $shipping, $orderDate, $orderStatus, $revoked
            );

            foreach ($lines as $key => $line) {
                $ordersProductsId = $this->insertOrderProduct($ordersId, $line, $prices['items'][$key], $language);
                if (!$revoked) {
                    $warnings = array_merge($warnings, $this->reduceStock($line, $everysizeOrderId));
                }
                $this->links->addOrderItem($ordersProductsId, $ordersId, $line['sku']);
            }

            $this->insertOrderTotals($ordersId, $prices, $language, $taxDescriptions);
            $this->insertStatusHistory($ordersId, $orderStatus, $everysizeOrderId);
            $this->links->addOrder($ordersId, $everysizeOrderId, $payPalCaptureId, $originalEmail);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Only after the commit: the optional order confirmation, through
        // Phoenix' own mail path (Notifications). A mail failure is a
        // warning; the order exists and is linked.
        if (!$revoked && $this->mailer !== null && $this->settings->sendOrderConfirmation()) {
            try {
                $this->mailer->sendConfirmation($ordersId);
            } catch (\Throwable $e) {
                $warnings[] = sprintf('%s: order confirmation mail failed: %s', $everysizeOrderId, $e->getMessage());
            }
        }

        return ['orderId' => $ordersId, 'orderNumber' => $ordersId, 'warnings' => $warnings];
    }

    // --- Validation helpers --------------------------------------------------

    private function requireString(array $data, string $key, ?string $label = null): string
    {
        $value = $data[$key] ?? null;
        if (!\is_scalar($value) || trim((string) $value) === '') {
            throw new ImportException(sprintf('everysize order has no %s', $label ?? $key));
        }

        return trim((string) $value);
    }

    /**
     * Phoenix keeps the street as one line (no house-number column), so the
     * address is written as delivered; addressLine2 goes to the suburb.
     *
     * @return array{firstName: string, lastName: string, street: string, suburb: string, zip: string, city: string, countryId: string, country: string, addressFormatId: string}
     */
    private function address($address, string $field): array
    {
        if (!\is_array($address)) {
            throw new ImportException(sprintf('everysize order has no %s', $field));
        }

        $street = $this->requireString($address, 'addressLine1', $field.'.addressLine1');
        $countryCode = strtoupper($this->requireString($address, 'countryCode', $field.'.countryCode'));
        $country = $this->db->fetchRows(
            'SELECT countries_id, countries_name, address_format_id FROM countries
             WHERE countries_iso_code_2 = :iso AND status = 1 LIMIT 1',
            [':iso' => $countryCode]
        );
        if ($country === []) {
            throw new ImportException(sprintf('Unknown or inactive country %s in %s', $countryCode, $field));
        }

        return [
            'firstName' => trim((string) ($address['firstName'] ?? '')),
            'lastName' => $this->requireString($address, 'lastName', $field.'.lastName'),
            'street' => $street,
            'suburb' => trim((string) ($address['addressLine2'] ?? '')),
            'zip' => $this->requireString($address, 'postalCode', $field.'.postalCode'),
            'city' => $this->requireString($address, 'city', $field.'.city'),
            'countryId' => (string) $country[0]['countries_id'],
            'country' => (string) $country[0]['countries_name'],
            'addressFormatId' => (string) ($country[0]['address_format_id'] ?? '5'),
        ];
    }

    /**
     * @return array{sku: string, title: string, quantity: int, gross: float, vat: float, taxDescription: string, productsId: string, name: string, model: string, size: ?array{optionName: string, valueName: string, price: float, prefix: string}}
     */
    private function resolveLines(string $everysizeOrderId, $lineItems, string $countryId, array $language, array &$warnings): array
    {
        if (!\is_array($lineItems) || $lineItems === []) {
            throw new ImportException('everysize order has no line items');
        }

        $lines = [];
        foreach (array_values($lineItems) as $item) {
            if (!\is_array($item)) {
                throw new ImportException('invalid line item in everysize order');
            }
            $sku = $this->requireString($item, 'sku', 'lineItems.sku');
            $resolved = $this->skuResolver->resolve($sku);

            $productsId = $resolved['productsId'];
            $size = null;
            if ($resolved['size'] !== null) {
                // The everysize price is final — the attribute is written with
                // a price of 0, so the order shows no "(+ X €)".
                $size = [
                    'optionName' => $this->optionName($resolved['size']['optionId'], $language['id']),
                    'valueName' => $this->valueName($resolved['size']['valueId'], $language['id']),
                    'price' => 0.0,
                    'prefix' => '+',
                ];
            }

            [$rate, $taxDescription] = $this->taxRate($resolved['taxClassId'], $countryId);
            if ($rate === 0.0 && $taxDescription === '') {
                $warnings[] = sprintf('%s: no tax rate for tax class %s in delivery country, item %s imported with 0%%',
                    $everysizeOrderId, $resolved['taxClassId'], $sku);
            }
            $lines[] = [
                'sku' => $sku,
                'title' => trim((string) ($item['title'] ?? '')),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'gross' => Amount::parse($item['price'] ?? null, 'lineItems.price'),
                'vat' => $rate,
                'taxDescription' => $taxDescription,
                'productsId' => $productsId,
                'name' => $this->productName($productsId, $language),
                'model' => $resolved['model'],
                'size' => $size,
            ];
        }

        return $lines;
    }

    /** Option name ("Größe"/"Size") of the attribute's option id, in the order language. */
    private function optionName(string $optionId, string $languageId): string
    {
        return (string) ($this->db->fetchValue(
            'SELECT products_options_name FROM products_options
             WHERE products_options_id = :id AND language_id = :lang LIMIT 1',
            [':id' => $optionId, ':lang' => $languageId]
        ) ?? '');
    }

    /** Options value name in the order language (same id, one row per language). */
    private function valueName(string $valuesId, string $languageId): string
    {
        return (string) ($this->db->fetchValue(
            'SELECT products_options_values_name FROM products_options_values
             WHERE products_options_values_id = :id AND language_id = :lang LIMIT 1',
            [':id' => $valuesId, ':lang' => $languageId]
        ) ?? '');
    }

    /**
     * VAT rate and description of the article's tax class for the delivery
     * address — the same query as Tax::fetch (geo zones without a country
     * match every country, a zone_id of 0 matches every zone, several rates
     * with the same priority multiply like stacked taxes). The delivery
     * address has no zone (Phoenix orders keep the street in one line), so
     * the zone id is 0.
     *
     * @return array{0: float, 1: string} rate and tax_description
     */
    private function taxRate(string $taxClassId, string $countryId): array
    {
        $rows = $this->db->fetchRows(
            'SELECT SUM(tr.tax_rate) AS rate, GROUP_CONCAT(tr.tax_description SEPARATOR \' + \') AS description
             FROM tax_rates tr
               LEFT JOIN zones_to_geo_zones za ON (tr.tax_zone_id = za.geo_zone_id)
               LEFT JOIN geo_zones tz ON (tz.geo_zone_id = tr.tax_zone_id)
             WHERE (za.zone_country_id IS NULL OR za.zone_country_id = 0 OR za.zone_country_id = :countryId)
               AND (za.zone_id IS NULL OR za.zone_id = 0 OR za.zone_id = :zoneId)
               AND tr.tax_class_id = :taxClassId
             GROUP BY tr.tax_priority
             ORDER BY tr.tax_priority',
            [':countryId' => $countryId, ':zoneId' => '0', ':taxClassId' => $taxClassId]
        );
        if ($rows === []) {
            return [0.0, ''];
        }

        $multiplier = 1.0;
        foreach (array_column($rows, 'rate') as $rate) {
            $multiplier *= 1.0 + ((float) $rate / 100);
        }

        return [($multiplier - 1.0) * 100, implode(' + ', array_column($rows, 'description'))];
    }

    private function productName(string $productsId, array $language): string
    {
        return (string) ($this->db->fetchValue(
            'SELECT products_name FROM products_description
             WHERE products_id = :id AND language_id = :lang LIMIT 1',
            [':id' => $productsId, ':lang' => $language['id']]
        ) ?? '');
    }

    private function localDate($value, string $field): string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return date('Y-m-d H:i:s');
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            throw new ImportException(sprintf('everysize order has an invalid %s date: %s', $field, $value));
        }
    }

    /**
     * The shop's default language (the DEFAULT_LANGUAGE configuration,
     * falling back to the first sorted language): id, directory, code.
     */
    private function defaultLanguage(): array
    {
        if ($this->defaultLanguage !== null) {
            return $this->defaultLanguage;
        }

        $code = (string) ($this->shopConfig('DEFAULT_LANGUAGE') ?? '');
        $row = [];
        if ($code !== '') {
            $row = $this->db->fetchRows(
                'SELECT languages_id, code, directory FROM languages WHERE code = :code ORDER BY languages_id LIMIT 1',
                [':code' => $code]
            );
        }
        if ($row === []) {
            $row = $this->db->fetchRows('SELECT languages_id, code, directory FROM languages ORDER BY sort_order, languages_id LIMIT 1');
        }
        if ($row === []) {
            $this->defaultLanguage = ['id' => '1', 'directory' => 'english', 'code' => 'en'];

            return $this->defaultLanguage;
        }

        $this->defaultLanguage = [
            'id' => (string) $row[0]['languages_id'],
            'directory' => (string) $row[0]['directory'],
            'code' => (string) $row[0]['code'],
        ];

        return $this->defaultLanguage;
    }

    // --- Writing helpers -----------------------------------------------------

    /**
     * The everysize customer either matches an existing shop account
     * (kept untouched, only referenced) or the order is a guest order
     * (no account is created, no registration mail).
     *
     * @return array{0: string} customers_id
     */
    private function resolveCustomer(string $email): array
    {
        $customerId = $this->db->fetchValue(
            'SELECT customers_id FROM customers WHERE customers_email_address = :email ORDER BY customers_id LIMIT 1',
            [':email' => $email]
        );

        return [$customerId ?? '0'];
    }

    /**
     * @param array{firstName: string, lastName: string, street: string, suburb: string, zip: string, city: string, countryId: string, country: string, addressFormatId: string} $billing
     * @param array{firstName: string, lastName: string, street: string, suburb: string, zip: string, city: string, countryId: string, country: string, addressFormatId: string} $shipping
     */
    private function insertOrder(
        string $everysizeOrderId, string $email, string $customerId,
        array $billing, array $shipping, string $orderDate, string $orderStatus, bool $revoked
    ): string {
        $this->db->execute(
            'INSERT INTO orders (customers_id, customers_name, customers_company,
                customers_street_address, customers_suburb, customers_city, customers_postcode,
                customers_state, customers_country, customers_country_id, customers_telephone,
                customers_email_address, customers_address_format_id,
                delivery_name, delivery_company, delivery_street_address, delivery_suburb,
                delivery_city, delivery_postcode, delivery_state, delivery_country, delivery_country_id,
                delivery_address_format_id,
                billing_name, billing_company, billing_street_address, billing_suburb,
                billing_city, billing_postcode, billing_state, billing_country, billing_country_id,
                billing_address_format_id,
                payment_method, date_purchased, orders_status, currency, currency_value)
             VALUES (:customerId, :customersName, :company,
                :street, :suburb, :city, :zip,
                :state, :country, :countryId, :telephone,
                :email, :formatId,
                :deliveryName, :deliveryCompany, :deliveryStreet, :deliverySuburb,
                :deliveryCity, :deliveryZip, :deliveryState, :deliveryCountry, :deliveryCountryId,
                :deliveryFormatId,
                :billingName, :billingCompany, :billingStreet, :billingSuburb,
                :billingCity, :billingZip, :billingState, :billingCountry, :billingCountryId,
                :billingFormatId,
                :paymentMethod, :datePurchased, :orderStatus, :currency, 1.000000)',
            [
                ':customerId' => $customerId,
                ':customersName' => $this->cut($this->name($billing['firstName'], $billing['lastName']), 255),
                ':company' => $this->cut('', 255),
                ':street' => $this->cut($billing['street'], 255),
                ':suburb' => $this->cut($billing['suburb'], 255),
                ':city' => $this->cut($billing['city'], 255),
                ':zip' => $this->cut($billing['zip'], 255),
                ':state' => $this->cut('', 255),
                ':country' => $this->cut($billing['country'], 255),
                ':countryId' => $billing['countryId'],
                ':telephone' => $this->cut('', 255),
                ':email' => $this->cut($email, 255),
                ':formatId' => $billing['addressFormatId'],
                ':deliveryName' => $this->cut($this->name($shipping['firstName'], $shipping['lastName']), 255),
                ':deliveryCompany' => $this->cut('', 255),
                ':deliveryStreet' => $this->cut($shipping['street'], 255),
                ':deliverySuburb' => $this->cut($shipping['suburb'], 255),
                ':deliveryCity' => $this->cut($shipping['city'], 255),
                ':deliveryZip' => $this->cut($shipping['zip'], 255),
                ':deliveryState' => $this->cut('', 255),
                ':deliveryCountry' => $this->cut($shipping['country'], 255),
                ':deliveryCountryId' => $shipping['countryId'],
                ':deliveryFormatId' => $shipping['addressFormatId'],
                ':billingName' => $this->cut($this->name($billing['firstName'], $billing['lastName']), 255),
                ':billingCompany' => $this->cut('', 255),
                ':billingStreet' => $this->cut($billing['street'], 255),
                ':billingSuburb' => $this->cut($billing['suburb'], 255),
                ':billingCity' => $this->cut($billing['city'], 255),
                ':billingZip' => $this->cut($billing['zip'], 255),
                ':billingState' => $this->cut('', 255),
                ':billingCountry' => $this->cut($billing['country'], 255),
                ':billingCountryId' => $billing['countryId'],
                ':billingFormatId' => $billing['addressFormatId'],
                ':paymentMethod' => self::PAYMENT_METHOD,
                ':datePurchased' => $orderDate,
                ':orderStatus' => $orderStatus,
                ':currency' => 'EUR',
            ]
        );

        $ordersId = $this->db->lastInsertId();
        if ($ordersId === null) {
            throw new ImportException(sprintf('%s: order could not be inserted', $everysizeOrderId));
        }

        return $ordersId;
    }

    /** "Firstname Lastname" — the order tables keep the name as one column. */
    private function name(string $firstName, string $lastName): string
    {
        return trim($firstName.' '.$lastName);
    }

    /**
     * @param array{sku: string, name: string, model: string, size: ?array{optionName: string, valueName: string, price: float, prefix: string}, quantity: int} $line
     * @param array{unitGross: float, totalGross: float, vat: float} $price
     */
    private function insertOrderProduct(string $ordersId, array $line, array $price, array $language): string
    {
        // The shop's article name first (like the other everysize plugins),
        // everysize's title only as fallback.
        $name = $line['name'] !== '' ? $line['name'] : $line['title'];

        // Phoenix stores the NET unit price here and re-adds the tax for
        // display (Tax::add(final_price, products_tax) * qty = line gross),
        // so the stored price is the gross unit price de-taxed at 4
        // decimals. products_price and final_price are the same — the
        // checkout writes the attribute surcharge into the difference, and
        // the everysize price is final (attribute surcharge 0).
        $unitNet = round($price['unitGross'] / (1 + $price['vat'] / 100), 4);

        $this->db->execute(
            'INSERT INTO orders_products (orders_id, products_id, products_model, products_name,
                products_price, final_price, products_tax, products_quantity)
             VALUES (:ordersId, :productsId, :model, :name,
                :unitNet, :unitNet, :tax, :quantity)',
            [
                ':ordersId' => $ordersId,
                ':productsId' => $line['productsId'],
                ':model' => $this->cut($line['model'], 255),
                ':name' => $this->cut($name, 255),
                ':unitNet' => number_format($unitNet, 4, '.', ''),
                ':tax' => number_format($price['vat'], 4, '.', ''),
                ':quantity' => (string) $line['quantity'],
            ]
        );
        $ordersProductsId = (string) $this->db->lastInsertId();

        if ($line['size'] !== null) {
            $this->db->execute(
                'INSERT INTO orders_products_attributes (orders_id, orders_products_id,
                    products_options, products_options_values, options_values_price, price_prefix)
                 VALUES (:ordersId, :ordersProductsId, :option, :value, :price, :prefix)',
                [
                    ':ordersId' => $ordersId,
                    ':ordersProductsId' => $ordersProductsId,
                    ':option' => $this->cut($line['size']['optionName'], 255),
                    ':value' => $this->cut($line['size']['valueName'], 255),
                    ':price' => number_format($line['size']['price'], 4, '.', ''),
                    ':prefix' => $line['size']['prefix'] !== '' ? mb_substr($line['size']['prefix'], 0, 1) : '+',
                ]
            );
        }

        return $ordersProductsId;
    }

    /**
     * Stock like the checkout's update_stock.php: nothing unless
     * STOCK_LIMITED; products with an attribute download are virtual and
     * skipped when DOWNLOAD_ENABLED. Stock may go negative (paid order!),
     * that is a warning not an error; with STOCK_ALLOW_CHECKOUT false the
     * product is deactivated when the stock runs out.
     *
     * @param array{sku: string, quantity: int, productsId: string} $line
     *
     * @return list<string>
     */
    private function reduceStock(array $line, string $everysizeOrderId): array
    {
        $warnings = [];
        // Sales statistics always (like update_products_ordered.php).
        $this->db->execute(
            'UPDATE products SET products_ordered = products_ordered + :qty WHERE products_id = :id',
            [':qty' => (string) $line['quantity'], ':id' => $line['productsId']]
        );

        if ($this->shopConfig('STOCK_LIMITED') !== 'true') {
            return $warnings;
        }

        if ($this->shopConfig('DOWNLOAD_ENABLED') === 'true'
            && $this->db->fetchValue(
                'SELECT 1 FROM products_attributes pa
                 JOIN products_attributes_download pad ON pad.products_attributes_id = pa.products_attributes_id
                 WHERE pa.products_id = :id AND pad.products_attributes_filename <> \'\' LIMIT 1',
                [':id' => $line['productsId']]
            ) !== null) {
            // Virtual product (download) — the checkout does not reduce stock.
            return $warnings;
        }

        $this->db->execute(
            'UPDATE products SET products_quantity = products_quantity - :qty WHERE products_id = :id',
            [':qty' => (string) $line['quantity'], ':id' => $line['productsId']]
        );
        $remaining = $this->db->fetchValue(
            'SELECT products_quantity FROM products WHERE products_id = :id',
            [':id' => $line['productsId']]
        );
        if ($remaining !== null && (float) $remaining < 0) {
            $warnings[] = sprintf('%s: stock of %s went negative (%.0f)', $everysizeOrderId, $line['sku'], (float) $remaining);
        }
        if ($remaining !== null && (float) $remaining < 1 && $this->shopConfig('STOCK_ALLOW_CHECKOUT') === 'false') {
            $this->db->execute(
                'UPDATE products SET products_status = 0 WHERE products_id = :id',
                [':id' => $line['productsId']]
            );
        }

        return $warnings;
    }

    /**
     * orders_total with the titles, texts and sort orders the ot_* modules
     * write: title from the module language files, text formatted like
     * currencies->format, sort order from the configuration, the ot_total
     * text bold. The tax title is the tax description from tax_rates (the
     * checkout's tax_groups key); tax groups of 0 are not written
     * (ot_tax only outputs value > 0). With DISPLAY_PRICE_WITH_TAX='false'
     * (Phoenix default) subtotal and delivery cost are stored net and the
     * VAT lines carry the difference — like the checkout's info array.
     *
     * @param array{totalGross: float, totalNet: float, vatGroups: array<string, float>, deliveryCost: float, deliveryVat: float, totalOrderSum: float} $prices
     * @param array<string, string> $taxDescriptions tax description per rate key
     */
    private function insertOrderTotals(string $ordersId, array $prices, array $language, array $taxDescriptions): void
    {
        $texts = $this->orderTotalTitles($language);
        $displayGross = $this->shopConfig('DISPLAY_PRICE_WITH_TAX') === 'true';

        // Shipping carries the VAT rate of the items (PriceCalculator) — its
        // VAT joins the highest item rate, like the checkout shows one tax line.
        $deliveryVat = $prices['deliveryCost'] > 0 && $prices['deliveryVat'] > 0
            ? round($prices['deliveryCost'] - $prices['deliveryCost'] / (1 + $prices['deliveryVat'] / 100), 2)
            : 0.0;
        $shippingNet = round($prices['deliveryCost'] - $deliveryVat, 2);

        $this->insertTotal(
            $ordersId, $this->cut($texts['subtotal'], 255),
            $displayGross ? round($prices['totalGross'], 2) : round($prices['totalNet'], 2),
            'ot_subtotal', $this->sortOrder('SUBTOTAL', '10')
        );
        $this->insertTotal(
            $ordersId, $this->cut(self::SHIPPING_METHOD, 255),
            $displayGross ? round($prices['deliveryCost'], 2) : $shippingNet,
            'ot_shipping', $this->sortOrder('SHIPPING', '20')
        );

        $top = null;
        foreach ($prices['vatGroups'] as $rate => $amount) {
            if ($top === null) {
                $top = $rate;
            }
            $value = $amount + ($rate == $top ? $deliveryVat : 0.0);
            if ($value <= 0) {
                continue; // ot_tax writes only value > 0 — no 0% line
            }
            $title = $taxDescriptions[$rate]
                ?? sprintf('%s%%', rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.'));
            $this->insertTotal($ordersId, $this->cut($title, 255), $value, 'ot_tax', $this->sortOrder('TAX', '30'));
        }
        $this->insertTotal(
            $ordersId, $this->cut($texts['total'], 255),
            $prices['totalOrderSum'], 'ot_total', $this->sortOrder('TOTAL', '40'), true
        );
    }

    /**
     * Titles of the ot modules from the order language's module language
     * files (MODULE_ORDER_TOTAL_*_TITLE); unknown stays with the fallback.
     *
     * @return array{subtotal: string, total: string}
     */
    private function orderTotalTitles(array $language): array
    {
        $directory = $language['directory'] !== '' ? $language['directory'] : 'german';
        $values = ['subtotal' => 'Zwischensumme', 'total' => 'Gesamtpreis'];
        $files = [
            'subtotal' => ['MODULE_ORDER_TOTAL_SUBTOTAL_TITLE', 'Zwischensumme'],
            'total' => ['MODULE_ORDER_TOTAL_TOTAL_TITLE', 'Gesamtpreis'],
        ];
        foreach ($files as $module => [$constant, $fallback]) {
            $title = '';
            if (\defined('DIR_FS_CATALOG')) {
                $file = DIR_FS_CATALOG.'includes/languages/'.$directory.'/modules/order_total/ot_'.$module.'.php';
                if (is_file($file)) {
                    include_once $file;
                    $title = \defined($constant) ? (string) \constant($constant) : '';
                }
            }

            $values[$module] = $title !== '' ? $title : $fallback;
        }

        return $values;
    }

    private function sortOrder(string $module, string $fallback): string
    {
        return (string) ($this->shopConfig('MODULE_ORDER_TOTAL_'.$module.'_SORT_ORDER') ?: $fallback);
    }

    /** VAT rate as an array key ("19", "7") — floats drift and get truncated. */
    private function rateKey(float $vat): string
    {
        return rtrim(rtrim(number_format($vat, 2, '.', ''), '0'), '.');
    }

    private function insertTotal(string $ordersId, string $title, float $value, string $class, string $sortOrder, bool $strong = false): void
    {
        $text = $this->money($value);
        $this->db->execute(
            'INSERT INTO orders_total (orders_id, title, text, value, class, sort_order)
             VALUES (:ordersId, :title, :text, :value, :class, :sortOrder)',
            [
                ':ordersId' => $ordersId,
                ':title' => $title,
                ':text' => $strong ? '<strong>'.$text.'</strong>' : $text,
                ':value' => number_format($value, 4, '.', ''),
                ':class' => $class,
                ':sortOrder' => $sortOrder,
            ]
        );
    }

    /**
     * Money text like currencies->format for the order currency ("124.89€"
     * with the demo's EUR row): symbol_left + formatted number + symbol_right.
     */
    private function money(float $value): string
    {
        $currency = $this->db->fetchRows(
            "SELECT symbol_left, symbol_right, decimal_point, thousands_point, decimal_places FROM currencies WHERE code = 'EUR' LIMIT 1"
        );
        if ($currency === []) {
            return number_format($value, 2, ',', '').' EUR';
        }
        $places = (int) $currency[0]['decimal_places'];

        return $currency[0]['symbol_left']
            . number_format(round($value, $places), $places, (string) $currency[0]['decimal_point'], (string) $currency[0]['thousands_point'])
            . $currency[0]['symbol_right'];
    }

    private function insertStatusHistory(string $ordersId, string $statusId, string $everysizeOrderId): void
    {
        $this->db->execute(
            'INSERT INTO orders_status_history (orders_id, orders_status_id, date_added, customer_notified, comments)
             VALUES (:ordersId, :statusId, :now, 0, :comments)',
            [
                ':ordersId' => $ordersId,
                ':statusId' => $statusId,
                ':now' => date('Y-m-d H:i:s'),
                ':comments' => sprintf('Importiert aus everysize (%s)', $everysizeOrderId),
            ]
        );
    }

    // --- Small helpers -------------------------------------------------------

    /** Trims to the column length. */
    private function cut(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }

    /** A shop configuration value (the configuration table, not a define). */
    private function shopConfig(string $key): ?string
    {
        return $this->db->fetchValue(
            'SELECT configuration_value FROM configuration WHERE configuration_key = :key LIMIT 1',
            [':key' => $key]
        );
    }
}
