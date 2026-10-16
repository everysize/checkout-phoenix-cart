<?php

declare(strict_types=1);

namespace Everysize\Checkout\Phoenix;

/**
 * Order confirmation through Phoenix' own mail path: Notifications::mail
 * (the shop's email class, ending in mail() with the -f envelope — msmtp →
 * Mailpit in the demo). Called by the importer after the commit; a failure
 * is a warning, the order exists and is linked.
 *
 * The mail is a plain-text summary (items with their attributes, the stored
 * order-total texts, the order id Phoenix knows). Store name and sender
 * come from the configuration table, so the class works everywhere the DB
 * is reachable — no defines needed.
 */
class PhoenixOrderMailer
{
    private const TEXTS = [
        'german' => [
            'subject' => 'Ihre Bestellung %s bei %s',
            'greeting' => "Sehr geehrte Kundin, sehr geehrter Kunde,\n\nvielen Dank für Ihre Bestellung bei %s.\n\nIhre Bestellnummer: %s\nBestelldatum: %s\n\nBestellübersicht:\n",
            'closing' => "\nDiese Bestellung wurde über everysize Checkout bezahlt.\n\nMit freundlichen Grüßen,\n%s",
        ],
        'english' => [
            'subject' => 'Your order %s at %s',
            'greeting' => "Dear customer,\n\nthank you for your order at %s.\n\nYour order number: %s\nOrder date: %s\n\nOrder summary:\n",
            'closing' => "\nThis order was paid via everysize Checkout.\n\nKind regards,\n%s",
        ],
    ];

    private PhoenixDb $db;

    public function __construct(PhoenixDb $db)
    {
        $this->db = $db;
    }

    public function sendConfirmation(string $ordersId): void
    {
        if (!\class_exists('\Notifications')) {
            throw new \RuntimeException('Phoenix mail classes are not loaded (shop autoloader not booted)');
        }

        $order = $this->db->fetchRows(
            'SELECT customers_name, customers_email_address, date_purchased FROM orders WHERE orders_id = :id LIMIT 1',
            [':id' => $ordersId]
        );
        if ($order === []) {
            throw new \RuntimeException('order '.$ordersId.' not found');
        }
        $order = $order[0];

        $storeName = $this->config('STORE_NAME') ?: 'Shop';
        $texts = self::TEXTS['german']; // Phoenix keeps no language on the order; the demo is German.
        $text = sprintf($texts['greeting'], $storeName, $ordersId, (string) $order['date_purchased']);

        foreach ($this->db->fetchRows(
            'SELECT orders_products_id, products_name, products_quantity, products_model
             FROM orders_products WHERE orders_id = :id ORDER BY orders_products_id',
            [':id' => $ordersId]
        ) as $item) {
            $line = sprintf('%s x %s', (string) $item['products_quantity'], (string) $item['products_name']);
            if ((string) $item['products_model'] !== '') {
                $line .= ' ['.((string) $item['products_model']).']';
            }
            foreach ($this->db->fetchRows(
                'SELECT products_options, products_options_values FROM orders_products_attributes
                 WHERE orders_products_id = :opId',
                [':opId' => (string) $item['orders_products_id']]
            ) as $attribute) {
                $line .= sprintf("\n  %s: %s", (string) $attribute['products_options'], (string) $attribute['products_options_values']);
            }
            $text .= $line."\n";
        }

        foreach ($this->db->fetchRows(
            "SELECT title, text FROM orders_total WHERE orders_id = :id AND class <> 'ot_tax' ORDER BY sort_order",
            [':id' => $ordersId]
        ) as $total) {
            $text .= sprintf("\n%s %s", (string) $total['title'], (string) $total['text']);
        }

        $text .= sprintf($texts['closing'], $this->config('STORE_OWNER') ?: $storeName);

        \Notifications::mail(
            (string) $order['customers_name'],
            (string) $order['customers_email_address'],
            sprintf($texts['subject'], $ordersId, $storeName),
            $text,
            $this->config('STORE_OWNER') ?: $storeName,
            $this->config('STORE_OWNER_EMAIL_ADDRESS') ?: 'shop@localhost'
        );
    }

    private function config(string $key): ?string
    {
        return $this->db->fetchValue(
            'SELECT configuration_value FROM configuration WHERE configuration_key = :key LIMIT 1',
            [':key' => $key]
        );
    }
}
