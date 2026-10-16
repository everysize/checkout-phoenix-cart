<?php

declare(strict_types=1);

namespace Everysize\Checkout;

use Everysize\Checkout\Api\ApiException;
use Everysize\Checkout\Api\ApiUrl;
use Everysize\Checkout\Api\Client;
use Everysize\Checkout\Db\DbInterface;
use Everysize\Checkout\Export\StatusExporter;
use Everysize\Checkout\Import\ImportException;
use Everysize\Checkout\Import\OrderImporter;

/**
 * One sync run: import new everysize orders, report their order numbers,
 * then report shipping/cancellations/returns. Run by the Gambio cronjob and
 * by bin/sync.php (system cron / tests).
 */
class Sync
{
    /** Gambio is single shop, so the lock does not need a shop id suffix. */
    private const LOCK_NAME = 'everysize_checkout_sync';

    private Settings $settings;
    private Client $client;
    private DbInterface $db;
    private OrderImporter $importer;
    private StatusExporter $exporter;
    private OrderLinks $links;
    private Protocol $protocol;

    public function __construct(
        Settings $settings,
        Client $client,
        DbInterface $db,
        OrderImporter $importer,
        StatusExporter $exporter,
        OrderLinks $links,
        Protocol $protocol
    ) {
        $this->settings = $settings;
        $this->client = $client;
        $this->db = $db;
        $this->importer = $importer;
        $this->exporter = $exporter;
        $this->links = $links;
        $this->protocol = $protocol;
    }

    /**
     * @return array{messages: string[], errors: string[]}
     */
    public function run(bool $import = true, bool $export = true): array
    {
        if (!$this->settings->isConfigured()) {
            return ['messages' => ['everysize credentials not configured, nothing to do'], 'errors' => []];
        }

        if ((int) $this->db->fetchValue('SELECT GET_LOCK(:name, 0)', [':name' => self::LOCK_NAME]) !== 1) {
            return ['messages' => ['another everysize sync is still running, skipped'], 'errors' => []];
        }

        try {
            $result = ['messages' => [sprintf('everysize API: %s', $this->client->getApiUrl())], 'errors' => []];

            try {
                ApiUrl::resolve($this->client->getApiUrl());
            } catch (ApiException $e) {
                // A typo in EVERYSIZE_CHECKOUT_API_URL must never fall back to production: stop before any call.
                $message = 'everysize API URL invalid: '.$e->getMessage();
                $this->protocol->add('everysize API URL invalid', $e->getMessage(), false);

                return ['messages' => [$message], 'errors' => [$message]];
            }

            if ($import) {
                $result = $this->merge($result, $this->import());
            }
            if ($export) {
                $result = $this->merge($result, $this->export());
            }
            $this->protocol->cleanup();

            return $result;
        } finally {
            $this->db->fetchValue('SELECT RELEASE_LOCK(:name)', [':name' => self::LOCK_NAME]);
        }
    }

    /**
     * @return array{messages: string[], errors: string[]}
     */
    private function import(): array
    {
        try {
            $orders = $this->client->getOpenOrders();
        } catch (ApiException $e) {
            $this->protocol->add('Import from everysize failed', $e->getMessage(), false);

            return ['messages' => [], 'errors' => [$e->getMessage()]];
        }

        $imported = 0;
        $errors = [];
        $warnings = [];
        foreach ($orders as $esOrder) {
            $everysizeOrderId = trim((string) ($esOrder['everysizeOrderId'] ?? ''));
            if ($everysizeOrderId === '') {
                $errors[] = 'everysize order without everysizeOrderId skipped';
                continue;
            }

            try {
                $orderId = $this->links->findOrderId($everysizeOrderId);
                if ($orderId === null) {
                    $result = $this->importer->import($esOrder);
                    $orderId = $result['orderId'];
                    $warnings = array_merge($warnings, $result['warnings']);
                    ++$imported;
                }

                if (!$this->links->isNumberReported($orderId)) {
                    $orderNumber = $this->orderNumber($orderId);
                    if ($orderNumber === null) {
                        // The order row is gone — never report an empty merchant order number.
                        throw new ImportException(sprintf('order %d not found in orders table', $orderId));
                    }
                    $this->client->sendMerchantOrderNumber($everysizeOrderId, $orderNumber);
                    $this->links->markNumberReported($orderId);
                }
            } catch (ImportException|ApiException $e) {
                $errors[] = sprintf('%s: %s', $everysizeOrderId, $e->getMessage());
            } catch (\Throwable $e) {
                $errors[] = sprintf('%s: %s: %s', $everysizeOrderId, \get_class($e), $e->getMessage());
            }
        }

        $title = sprintf('Imported %d of %d orders from everysize', $imported, \count($orders));
        if ($orders !== [] || $errors !== []) {
            $this->protocol->add($title, implode("\n", array_merge($errors, $warnings)), $errors === []);
        }

        return ['messages' => [$title], 'errors' => $errors];
    }

    /**
     * @return array{messages: string[], errors: string[]}
     */
    private function export(): array
    {
        try {
            $result = $this->exporter->export();
        } catch (ApiException $e) {
            $this->protocol->add('Report to everysize failed', $e->getMessage(), false);

            return ['messages' => [], 'errors' => [$e->getMessage()]];
        }

        $title = sprintf(
            'Reported to everysize: %d shipped, %d tracking codes added later, %d cancelled, %d returned, %d refunded automatically, %d skipped (revoked at everysize)',
            $result['fulfillments'],
            $result['trackingCodes'],
            $result['revocations'],
            $result['returns'],
            $result['autoRefunds'],
            $result['skipped']
        );
        if ($result['pending'] > 0 || $result['errors'] !== []) {
            $this->protocol->add($title, implode("\n", $result['errors']), $result['errors'] === []);
        }

        return ['messages' => [$title], 'errors' => $result['errors']];
    }

    private function orderNumber(string $orderId): ?string
    {
        return $this->db->fetchValue('SELECT orders_id FROM orders WHERE orders_id = :id', [':id' => $orderId]);
    }

    /**
     * @param array{messages: string[], errors: string[]} $a
     * @param array{messages: string[], errors: string[]} $b
     *
     * @return array{messages: string[], errors: string[]}
     */
    private function merge(array $a, array $b): array
    {
        return [
            'messages' => array_merge($a['messages'], $b['messages']),
            'errors' => array_merge($a['errors'], $b['errors']),
        ];
    }
}
