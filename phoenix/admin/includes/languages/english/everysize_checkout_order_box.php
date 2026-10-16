<?php

/*
  $Id$

  everysize Checkout — texts of the order detail box (listen_orderTab hook)
  and the everysize search in the orders list. Loaded by the hook (not by
  Phoenix, hence a returned array instead of constants).
*/

return [
    'nav_title' => '<i class="fas fa-sync-alt fa-fw me-1"></i>everysize',

    // Box sections
    'section_order'   => 'everysize order',
    'section_refund'  => 'Refund via everysize',
    'section_reports' => 'Reports to everysize',
    'section_tracking' => 'Shipment',

    // Info table
    'es_order_number'    => 'everysize order number',
    'imported'           => 'Imported',
    'number_reported'    => 'Order number reported to everysize',
    'not_yet'            => 'not yet',
    'paypal_capture'     => 'PayPal transaction (capture ID)',
    'original_email'     => 'Customer\'s original e-mail',
    'original_email_hint' => '(for your information only — shop mails go to the order\'s everysize forwarding address)',

    // Shipment
    'tracking_code'   => 'Tracking code',
    'tracking_carrier' => 'Carrier',
    'tracking_save'   => 'Save tracking code',
    'tracking_saved'  => 'Tracking code saved.',
    'tracking_hint'   => 'Reported to everysize with the next sync (status "shipped").',

    // Refund
    'paid'            => 'Paid',
    'refunded'        => 'refunded',
    'open'            => 'still open',
    'date'            => 'Date',
    'refund_amount'   => 'Refunded amount',
    'refund_status'   => 'Status',
    'refund_id'       => 'Refund ID',
    'amount'          => 'Amount (EUR)',
    'refund_button'   => 'Refund',
    'refund_confirm'  => 'Refund %s € to the customer via everysize? This cannot be undone.',
    'hint_refund'     => 'The refund runs through everysize: the amount goes back through the PayPal account the customer paid with. No PayPal credentials in the shop.',
    'hint_autorefund' => 'A cancellation before shipping without any refund is refunded automatically in full (setting on the everysize page).',
    'refunds_error'   => 'Refunds could not be loaded:',
    'refunds_none'    => 'No refunds yet.',
    'no_credentials'  => 'No credentials — the refund needs the everysize connection (settings on the everysize page).',
    'not_installed'   => 'The add-on is not installed.',
    'refund_failed'   => 'Refund failed:',
    'refund_not_possible' => 'This order cannot be refunded via everysize.',
    'refund_amount_rejected' => 'everysize rejected the amount, e.g. because it exceeds the amount not yet refunded.',
    'refund_paypal_rejected' => 'PayPal rejected the refund.',
    'refund_invalid'  => 'Please enter a valid amount greater than 0.',
    'return_checkbox' => 'Goods came back: report a return to everysize',
    'return_button'   => 'Report return',
    'return_confirm'  => 'Report a return to everysize? This cannot be undone.',
    'return_done'     => 'Return reported to everysize.',
    'return_skipped'  => 'The order is already revoked at everysize, the return was not reported.',
    'return_not_shipped' => 'A return needs a reported shipment — set the order to the "shipped" status first.',
    'hint_return'     => 'For a return, tick the box and enter the amount to refund — e.g. without the shipping costs. The return is reported first, then the amount is refunded.',

    // Reports
    'report_none'      => 'No reports yet.',
    'report_time'      => 'Time',
    'report_kind'      => 'Report',
    'report_item'      => 'Item / tracking code / refund',
    'report_reason'    => 'Reason / amount',
    'report_result'    => 'Result',
    'kind_fulfillment' => 'Shipping',
    'kind_tracking'    => 'Tracking code',
    'kind_revocation'  => 'Cancellation',
    'kind_return'      => 'Return',
    'kind_refund'      => 'Refund',
    'result_sent'      => 'sent',
    'result_known'     => 'already known',
    'result_skipped'   => 'skipped',
    'auto_refund_sku'  => 'automatic, cancellation before shipping',
    'reason_merchant_decline' => 'Merchant declined',

    // Orders list
    'overview_search' => 'everysize number',
    'overview_search_active' => 'Filter: everysize number "%s"',
    'overview_search_reset' => 'Reset filter',
];
