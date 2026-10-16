<?php

declare(strict_types=1);

namespace Everysize\Checkout\Phoenix;

use Everysize\Checkout\Api\ApiException;
use Everysize\Checkout\Api\Client;
use Everysize\Checkout\Db\DbInterface;
use Everysize\Checkout\OrderLinks;
use Everysize\Checkout\Settings;

/**
 * The "everysize" box in the order detail (orderTab hook), three sections
 * like the other everysize plugins but with Phoenix' own admin look
 * (Bootstrap, no pixel copying):
 *
 * 1. "everysize-Bestellung" — info table (number, import/report times,
 *    capture id, forwarding e-mail, tracking input)
 * 2. "Erstattung über everysize" — paid/refunded/open tiles, refunds
 *    table, AJAX refund form (fresh idempotency key per page view, CSRF
 *    via the admin form token) and a notice box; only with a capture id.
 *    An API failure shows the reason and keeps the order data visible.
 * 3. "Rückmeldungen an everysize" — the report table.
 *
 * Only rendered for orders that came from everysize (render returns ''
 * otherwise). DB timestamps are shown as stored (the sync and the import
 * write shop time); API ISO times are converted to the PHP time zone.
 * All text comes from the admin language file everysize_checkout_order_box
 * (loaded by the hook), passed in as a plain array.
 */
class OrderBox
{
    /** Refund statuses that count as "erstattet" (the money is on its way). */
    private const REFUNDED_STATES = ['COMPLETED', 'PENDING'];

    /** Known refund reasons of the API in plain words (the raw text behind it). */
    public const REFUND_REASONS = [
        'ORDER_NOT_PAID_USING_EVERYSIZE_CHECKOUT_PAYMENTS' => 'not_possible',
        'REFUND_AMOUNT_INVALID' => 'amount_rejected',
        'PAYPAL_REFUND_FAILED' => 'paypal_rejected',
    ];

    private PhoenixDb $db;

    private Settings $settings;

    private OrderLinks $links;

    private ?Client $client;

    public function __construct(PhoenixDb $db, Settings $settings, OrderLinks $links, ?Client $client = null)
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->links = $links;
        $this->client = $client;
    }

    // --- Static helpers (used by the orderTab hook AND the AJAX endpoints) ----

    /**
     * Texts of the box language file (admin/includes/languages/<dir>/
     * everysize_checkout_order_box.php) with an English fallback. The file
     * returns an array — no global constants needed.
     *
     * @return array<string, string>
     */
    public static function loadTexts(string $languageDirectory): array
    {
        foreach ([$languageDirectory, 'english'] as $directory) {
            $path = DIR_FS_ADMIN.'includes/languages/'.$directory.'/everysize_checkout_order_box.php';
            if (is_file($path)) {
                $texts = include $path;
                if (\is_array($texts)) {
                    return $texts;
                }
            }
        }

        return [];
    }

    /** Language directory of the active admin language (from the languages table). */
    public static function languageDirectory(DbInterface $db, int $languageId): string
    {
        if ($languageId > 0) {
            $row = $db->fetchRows(
                'SELECT directory FROM languages WHERE languages_id = :id LIMIT 1',
                [':id' => (string) $languageId]
            );
            if ($row !== [] && (string) $row[0]['directory'] !== '') {
                return (string) $row[0]['directory'];
            }
        }

        return 'german';
    }

    /** Known problem reasons of the refund endpoint in plain words (the language key). */
    public static function refundReasonKey(string $reason): string
    {
        return self::REFUND_REASONS[$reason] ?? 'failed';
    }

    /**
     * @param array<string, string> $texts language file (everysize_checkout_order_box)
     * @param array<string, string> $carriers carrier list for the tracking select
     */
    public function render(
        string $orderId,
        string $refundEndpoint,
        string $trackingEndpoint,
        string $formToken,
        string $refundKey,
        array $texts,
        array $carriers
    ): string {
        $link = $this->links->getLink($orderId);
        if ($link === null) {
            return '';
        }

        $t = static function (string $key) use ($texts): string {
            return $texts[$key] ?? $key;
        };
        $orderId = (string) $link['ORDER_ID'];
        $everysizeOrderId = (string) $link['EVERYSIZE_ORDER_ID'];

        $html = '<div class="tab-pane fade" id="section_everysize_content" role="tabpanel">'
            . '<div class="card mb-3"><div class="card-body">';

        // --- 1. everysize-Bestellung --------------------------------------------
        $html .= '<h5 class="card-title mb-3">'.htmlspecialchars($t('section_order'), ENT_QUOTES, 'UTF-8').'</h5>'
            . '<div class="table-responsive"><table class="table table-sm"><tbody>'
            . $this->infoRow($t('es_order_number'), '<strong>'.htmlspecialchars($everysizeOrderId, ENT_QUOTES, 'UTF-8').'</strong>')
            . $this->infoRow($t('imported'), htmlspecialchars($this->dbTime((string) $link['CREATED']), ENT_QUOTES, 'UTF-8'))
            . $this->infoRow($t('number_reported'), htmlspecialchars(
                $link['NUMBER_REPORTED'] !== null ? $this->dbTime((string) $link['NUMBER_REPORTED']) : $t('not_yet'),
                ENT_QUOTES, 'UTF-8'
            ))
            . $this->infoRow($t('paypal_capture'), '<code>'.htmlspecialchars((string) $link['PAYPAL_CAPTURE_ID'], ENT_QUOTES, 'UTF-8').'</code>')
            . $this->infoRow(
                $t('original_email'),
                htmlspecialchars($link['ORIGINAL_EMAIL'] !== '' ? (string) $link['ORIGINAL_EMAIL'] : '–', ENT_QUOTES, 'UTF-8')
                . '<div class="form-text">'.htmlspecialchars($t('original_email_hint'), ENT_QUOTES, 'UTF-8').'</div>'
            )
            . '</tbody></table></div>';

        // --- Sendungsnummer + Carrier (AJAX-Speichern, kein Formular) -----------
        $trackingCode = (string) $link['TRACKING_CODE'];
        $trackingCarrier = (string) $link['CARRIER'];
        if ($trackingCarrier === '') {
            $trackingCarrier = (string) $this->settings->getCarrier();
        }
        $carrierOptions = '';
        foreach ($carriers as $carrier) {
            $carrierOptions .= '<option value="'.htmlspecialchars($carrier, ENT_QUOTES, 'UTF-8').'"'
                .($carrier === $trackingCarrier ? ' selected' : '').'>'.htmlspecialchars($carrier, ENT_QUOTES, 'UTF-8').'</option>';
        }
        $html .= '<h5 class="card-title mb-2 mt-4">'.htmlspecialchars($t('section_tracking'), ENT_QUOTES, 'UTF-8').'</h5>'
            . '<div class="row g-2 align-items-end mb-2" id="everysize-tracking-row">'
            . '<div class="col-sm-4"><label class="form-label" for="everysize_tracking_code">'.htmlspecialchars($t('tracking_code'), ENT_QUOTES, 'UTF-8').'</label>'
            . '<input type="text" class="form-control" id="everysize_tracking_code" value="'.htmlspecialchars($trackingCode, ENT_QUOTES, 'UTF-8').'" autocomplete="off"></div>'
            . '<div class="col-sm-3"><label class="form-label" for="everysize_tracking_carrier">'.htmlspecialchars($t('tracking_carrier'), ENT_QUOTES, 'UTF-8').'</label>'
            . '<select class="form-select" id="everysize_tracking_carrier">'.$carrierOptions.'</select></div>'
            . '<div class="col-sm-auto">'
            . '<button type="button" class="btn btn-outline-primary" id="everysize_tracking_save"'
            .' data-endpoint="'.htmlspecialchars($trackingEndpoint, ENT_QUOTES, 'UTF-8').'"'
            .' data-token="'.htmlspecialchars($formToken, ENT_QUOTES, 'UTF-8').'"'
            .' data-order-id="'.(int) $orderId.'">'
            . htmlspecialchars($t('tracking_save'), ENT_QUOTES, 'UTF-8').'</button></div>'
            . '<div class="col-sm-auto"><span id="everysize_tracking_feedback" class="form-text"></span></div>'
            . '</div>'
            . '<div class="form-text mb-2">'.htmlspecialchars($t('tracking_hint'), ENT_QUOTES, 'UTF-8').'</div>';

        // --- 2. Erstattung über everysize (nur mit Capture-ID) -------------------
        if ($link['PAYPAL_CAPTURE_ID'] !== '') {
            $html .= $this->refundSection($orderId, $everysizeOrderId, $refundEndpoint, $formToken, $refundKey, $t);
        }

        // --- 3. Rückmeldungen an everysize ---------------------------------------
        $html .= '<h5 class="card-title mb-2 mt-4">'.htmlspecialchars($t('section_reports'), ENT_QUOTES, 'UTF-8').'</h5>'
            . $this->reportsTable($orderId, $t);

        $html .= '</div></div></div>'
            // The handlers: tracking save (inline feedback) and the refund
            // (confirm, POST, on success a fresh page — tiles and reports
            // come from the server, no client-side recalculation).
            . '<script>(function(){'
            .'function post(endpoint,token,data){'
            ."var b=new URLSearchParams();b.append(\"formid\",token);"
            .'for(var k in data){b.append(k,data[k]);}'
            ."return fetch(endpoint,{method:\"POST\",body:b,credentials:\"same-origin\"}).then(function(r){return r.json();});}"
            .'function feedback(id,text){var el=document.getElementById(id);if(el){el.textContent=text;}}'
            .'var track=document.getElementById("everysize_tracking_save");'
            .'if(track){track.addEventListener("click",function(){'
            ."post(track.dataset.endpoint,track.dataset.token,{action:\"orderTracking\",order_id:track.dataset.orderId,"
            ."tracking_code:document.getElementById(\"everysize_tracking_code\").value,"
            ."carrier:document.getElementById(\"everysize_tracking_carrier\").value})"
            .'.then(function(d){feedback("everysize_tracking_feedback",d.message||"");});});}'
            .'var refund=document.getElementById("everysize_refund_submit");'
            .'if(refund){refund.addEventListener("click",function(){'
            .'var amount=document.getElementById("everysize_refund_amount").value.trim();'
            ."if(!/^\\d+([.,]\\d{1,2})?$/.test(amount)||parseFloat(amount.replace(\",\",\".\"))<=0){"
            .'feedback("everysize_refund_feedback",refund.dataset.invalid);return;}'
            ."if(!confirm(refund.dataset.confirm.replace(\"%s\",amount))){return;}"
            .'refund.disabled=true;feedback("everysize_refund_feedback","");'
            .'var ret=document.getElementById("everysize_report_return");'
            ."post(refund.dataset.endpoint,refund.dataset.token,{action:\"orderRefund\",order_id:refund.dataset.orderId,"
            ."refund_amount:amount,refund_key:refund.dataset.refundKey,refund:\"1\",report_return:ret&&ret.checked?\"1\":\"0\"})"
            .'.then(function(d){if(d.reload){location.reload();}else{feedback("everysize_refund_feedback",d.message||"");refund.disabled=false;}})'
            .'.catch(function(){refund.disabled=false;});});}'
            .'var retOnly=document.getElementById("everysize_return_submit");'
            .'if(retOnly){retOnly.addEventListener("click",function(){'
            .'if(!confirm(retOnly.dataset.confirm)){return;}'
            .'retOnly.disabled=true;feedback("everysize_refund_feedback","");'
            ."post(retOnly.dataset.endpoint,retOnly.dataset.token,{action:\"orderRefund\",order_id:retOnly.dataset.orderId,"
            ."refund_key:retOnly.dataset.refundKey,refund:\"0\",report_return:\"1\"})"
            .'.then(function(d){if(d.reload){location.reload();}else{feedback("everysize_refund_feedback",d.message||"");retOnly.disabled=false;}})'
            .'.catch(function(){retOnly.disabled=false;});});}'
            .'})();</script>'
            // The tab navigation is hard-coded in the edit view — the pane
            // appends its own nav item (Bootstrap 5 picks it up live).
            . '<script>document.addEventListener("DOMContentLoaded",function(){'
            .'var u=document.querySelector("#orderTabs ul.nav-tabs");'
            .'if(u&&!document.querySelector("#orderTabs a[href=\"#section_everysize_content\"]")){'
            .'var l=document.createElement("li");l.className="nav-item";'
            .'l.innerHTML=\'<a class="nav-link" data-bs-toggle="tab" href="#section_everysize_content" role="tab">\'+'
            .var_export($t('nav_title'), true).'+\'</a>\';u.appendChild(l);}});</script>';

        return $html;
    }

    // --- Sections -------------------------------------------------------------

    /**
     * @param callable(string): string $t
     */
    private function refundSection(string $orderId, string $everysizeOrderId, string $refundEndpoint, string $formToken, string $refundKey, callable $t): string
    {
        $html = '<h5 class="card-title mb-2 mt-4">'.htmlspecialchars($t('section_refund'), ENT_QUOTES, 'UTF-8').'</h5>';
        $info = $this->refundInfo($orderId, $everysizeOrderId, $t);

        if ($info['error'] !== '') {
            return $html.'<div class="alert alert-warning">'.htmlspecialchars($t('refunds_error'), ENT_QUOTES, 'UTF-8').' '
                .htmlspecialchars($info['error'], ENT_QUOTES, 'UTF-8').'</div>';
        }

        $html .= '<div class="row g-3 mb-3">'
            . $this->tile($t('paid'), $this->money($info['paid']), false)
            . $this->tile($t('refunded'), $this->money($info['refunded']), false)
            . $this->tile($t('open'), $this->money($info['open']), $info['open'] > 0.004)
            . '</div>';

        $html .= '<div class="table-responsive mb-3"><table class="table table-sm table-striped"><thead><tr>'
            . '<th>'.htmlspecialchars($t('date'), ENT_QUOTES, 'UTF-8').'</th>'
            . '<th>'.htmlspecialchars($t('refund_amount'), ENT_QUOTES, 'UTF-8').'</th>'
            . '<th>'.htmlspecialchars($t('refund_status'), ENT_QUOTES, 'UTF-8').'</th>'
            . '<th>'.htmlspecialchars($t('refund_id'), ENT_QUOTES, 'UTF-8').'</th>'
            . '</tr></thead><tbody>';
        if ($info['refunds'] === []) {
            // Empty list still shows the table (with headers) — and the form
            // below, which is the normal case for a not-yet-refunded order.
            $html .= '<tr><td colspan="4" class="text-muted">'.htmlspecialchars($t('refunds_none'), ENT_QUOTES, 'UTF-8').'</td></tr></tbody></table></div>';
        } else {
            foreach ($info['refunds'] as $refund) {
                $html .= '<tr><td>'.htmlspecialchars($refund['created'], ENT_QUOTES, 'UTF-8')
                    .'</td><td>'.htmlspecialchars($refund['amount'], ENT_QUOTES, 'UTF-8')
                    .'</td><td>'.htmlspecialchars($refund['status'], ENT_QUOTES, 'UTF-8')
                    .'</td><td><code>'.htmlspecialchars($refund['refundId'], ENT_QUOTES, 'UTF-8').'</code></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }

        // A return can be reported once the shipment went out and as long as a
        // position has no revocation yet: with the refund (checkbox) or alone
        // (button) when nothing is left to refund.
        $canReturn = $this->links->hasSentFulfillment($orderId) && $this->links->getUnrevokedSkus($orderId) !== [];
        $buttonData = ' data-endpoint="'.htmlspecialchars($refundEndpoint, ENT_QUOTES, 'UTF-8').'"'
            .' data-token="'.htmlspecialchars($formToken, ENT_QUOTES, 'UTF-8').'"'
            .' data-order-id="'.(int) $orderId.'"'
            .' data-refund-key="'.htmlspecialchars($refundKey, ENT_QUOTES, 'UTF-8').'"';

        if ($info['open'] <= 0.004 && $canReturn) {
            $html .= '<div class="row g-2 align-items-end mb-2" id="everysize-refund-row">'
                . '<div class="col-sm-auto">'
                . '<button type="button" class="btn btn-outline-primary" id="everysize_return_submit"'.$buttonData
                .' data-confirm="'.htmlspecialchars($t('return_confirm'), ENT_QUOTES, 'UTF-8').'">'
                . htmlspecialchars($t('return_button'), ENT_QUOTES, 'UTF-8').'</button></div>'
                . '<div class="col-sm-auto"><span id="everysize_refund_feedback" class="form-text"></span></div>'
                . '</div>';
        }

        if ($info['open'] > 0.004) {
            $openInput = number_format($info['open'], 2, ',', '');
            $html .= '<div class="row g-2 align-items-end mb-2" id="everysize-refund-row">'
                . '<div class="col-sm-3"><label class="form-label" for="everysize_refund_amount">'.htmlspecialchars($t('amount'), ENT_QUOTES, 'UTF-8').'</label>'
                . '<input type="text" class="form-control" id="everysize_refund_amount" value="'.htmlspecialchars($openInput, ENT_QUOTES, 'UTF-8').'" autocomplete="off"></div>'
                . '<div class="col-sm-auto">'
                . '<button type="button" class="btn btn-primary" id="everysize_refund_submit"'
                .' data-endpoint="'.htmlspecialchars($refundEndpoint, ENT_QUOTES, 'UTF-8').'"'
                .' data-token="'.htmlspecialchars($formToken, ENT_QUOTES, 'UTF-8').'"'
                .' data-order-id="'.(int) $orderId.'"'
                .' data-refund-key="'.htmlspecialchars($refundKey, ENT_QUOTES, 'UTF-8').'"'
                .' data-confirm="'.htmlspecialchars($t('refund_confirm'), ENT_QUOTES, 'UTF-8').'"'
                .' data-invalid="'.htmlspecialchars($t('refund_invalid'), ENT_QUOTES, 'UTF-8').'">'
                . htmlspecialchars($t('refund_button'), ENT_QUOTES, 'UTF-8').'</button></div>'
                . '<div class="col-sm-auto"><span id="everysize_refund_feedback" class="form-text"></span></div>'
                . '</div>';
            if ($canReturn) {
                $html .= '<div class="form-check mb-2">'
                    . '<input class="form-check-input" type="checkbox" value="1" id="everysize_report_return">'
                    . '<label class="form-check-label" for="everysize_report_return">'.htmlspecialchars($t('return_checkbox'), ENT_QUOTES, 'UTF-8').'</label>'
                    . '</div>';
            }
        }

        $hint = $t('hint_refund');
        if ($canReturn && $info['open'] > 0.004) {
            $hint .= ' '.$t('hint_return');
        }
        if ($this->settings->isAutoRefundDecline()) {
            $hint .= ' '.$t('hint_autorefund');
        }

        return $html.'<div class="alert alert-info">'.htmlspecialchars($hint, ENT_QUOTES, 'UTF-8').'</div>';
    }

    /**
     * Paid/refunded/open from everysize (the live refund state) plus the
     * order total as "paid". An API failure shows the reason and keeps the
     * form usable — the order data is local.
     *
     * @param callable(string): string $t
     *
     * @return array{refunds: array<int, array{created: string, amount: string, status: string, refundId: string}>, error: string, paid: float, refunded: float, open: float}
     */
    private function refundInfo(string $orderId, string $everysizeOrderId, callable $t): array
    {
        $info = ['refunds' => [], 'error' => '', 'paid' => 0.0, 'refunded' => 0.0, 'open' => 0.0];
        $paid = $this->db->fetchValue(
            "SELECT value FROM orders_total WHERE orders_id = :id AND class = 'ot_total' ORDER BY sort_order LIMIT 1",
            [':id' => $orderId]
        );
        $info['paid'] = $paid !== null ? (float) $paid : 0.0;

        if ($this->client === null || !$this->settings->isConfigured()) {
            $info['error'] = $t('no_credentials');

            return $info;
        }

        try {
            $refunds = $this->client->getRefunds($everysizeOrderId);
        } catch (ApiException $e) {
            $info['error'] = $this->refundError($e, $t);

            return $info;
        }

        foreach ($refunds as $refund) {
            $amount = (float) str_replace(',', '', (string) ($refund['amount'] ?? '0'));
            $status = strtoupper((string) ($refund['status'] ?? ''));
            if (\in_array($status, self::REFUNDED_STATES, true)) {
                $info['refunded'] += $amount;
            }
            $info['refunds'][] = [
                'created' => $this->apiTime((string) ($refund['created'] ?? '')),
                'amount' => $this->money($amount),
                'status' => $status,
                'refundId' => (string) ($refund['refundId'] ?? ''),
            ];
        }
        $info['open'] = max(0.0, round($info['paid'] - $info['refunded'], 2));

        return $info;
    }

    /**
     * @param callable(string): string $t
     */
    private function reportsTable(string $orderId, callable $t): string
    {
        $reports = $this->links->getReports($orderId);
        $html = '<div class="table-responsive"><table class="table table-sm table-striped"><thead><tr>'
            . '<th>'.htmlspecialchars($t('report_time'), ENT_QUOTES, 'UTF-8').'</th>'
            . '<th>'.htmlspecialchars($t('report_kind'), ENT_QUOTES, 'UTF-8').'</th>'
            . '<th>'.htmlspecialchars($t('report_item'), ENT_QUOTES, 'UTF-8').'</th>'
            . '<th>'.htmlspecialchars($t('report_reason'), ENT_QUOTES, 'UTF-8').'</th>'
            . '<th>'.htmlspecialchars($t('report_result'), ENT_QUOTES, 'UTF-8').'</th>'
            . '</tr></thead><tbody>';
        if ($reports === []) {
            return $html.'<tr><td colspan="5" class="text-muted">'.htmlspecialchars($t('report_none'), ENT_QUOTES, 'UTF-8').'</td></tr></tbody></table></div>';
        }
        foreach ($reports as $report) {
            $kind = (string) $report['KIND'];
            $sku = (string) $report['SKU'];
            $rawReason = (string) $report['REASON'];
            $item = '–';
            if ($kind === OrderLinks::KIND_REFUND) {
                $item = $sku === OrderLinks::AUTO_REFUND ? $t('auto_refund_sku') : $sku;
            } elseif ($sku !== '') {
                $item = $sku;
            }
            if ($kind === OrderLinks::KIND_REFUND && is_numeric($rawReason)) {
                $reason = $this->money((float) $rawReason);
            } elseif ($rawReason === '') {
                $reason = '–';
            } else {
                $reason = $this->reasonLabel($rawReason, $t);
            }
            // A revocation after a reported shipment is a return (RETOUR) —
            // shown as such, not as a cancellation.
            $kindLabel = $kind === OrderLinks::KIND_REVOCATION && $rawReason === Client::REASON_RETOUR
                ? $t('kind_return')
                : $this->kindLabel($kind, $t);
            $html .= '<tr><td>'.htmlspecialchars($this->dbTime((string) $report['CREATED']), ENT_QUOTES, 'UTF-8').'</td>'
                .'<td>'.htmlspecialchars($kindLabel, ENT_QUOTES, 'UTF-8').'</td>'
                .'<td>'.htmlspecialchars($item, ENT_QUOTES, 'UTF-8').'</td>'
                .'<td>'.htmlspecialchars($reason, ENT_QUOTES, 'UTF-8').'</td>'
                .'<td>'.htmlspecialchars($this->resultLabel((string) $report['RESULT'], $t), ENT_QUOTES, 'UTF-8').'</td></tr>';
        }

        return $html.'</tbody></table></div>';
    }

    // --- Small helpers ----------------------------------------------------------

    private function infoRow(string $label, string $value): string
    {
        return '<tr><th class="text-muted fw-normal" style="width:260px">'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</th><td>'.$value.'</td></tr>';
    }

    private function tile(string $label, string $value, bool $highlight): string
    {
        return '<div class="col-auto"><div class="card'.($highlight ? ' border-danger' : '').'"><div class="card-body py-2 px-3">'
            .'<span class="text-muted d-block small">'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</span>'
            .'<strong class="fs-4'.($highlight ? ' text-danger' : '').'">'.htmlspecialchars($value, ENT_QUOTES, 'UTF-8').'</strong>'
            .'</div></div></div>';
    }

    /** Known revocation and refund reasons in plain words; unknown stays as it is. */
    private function reasonLabel(string $reason, callable $t): string
    {
        if ($reason === Client::REASON_MERCHANT_DECLINE) {
            return $t('reason_merchant_decline');
        }
        if ($reason === Client::REASON_RETOUR) {
            return $t('kind_return');
        }
        $key = self::refundReasonKey($reason);
        if ($key !== 'failed') {
            return $t('refund_'.$key);
        }

        return $reason;
    }

    private function kindLabel(string $kind, callable $t): string
    {
        switch ($kind) {
            case OrderLinks::KIND_FULFILLMENT:
                return $t('kind_fulfillment');
            case OrderLinks::KIND_TRACKING:
                return $t('kind_tracking');
            case OrderLinks::KIND_REVOCATION:
                return $t('kind_revocation');
            case OrderLinks::KIND_REFUND:
                return $t('kind_refund');
            default:
                return $kind;
        }
    }

    private function resultLabel(string $result, callable $t): string
    {
        switch ($result) {
            case OrderLinks::RESULT_SENT:
                return $t('result_sent');
            case OrderLinks::RESULT_KNOWN:
                return $t('result_known');
            case OrderLinks::RESULT_SKIPPED:
                return $t('result_skipped');
            default:
                return $result;
        }
    }

    /** Known problem reasons of the refund endpoint in plain words, the API's own text behind it. */
    private function refundError(ApiException $e, callable $t): string
    {
        $key = self::refundReasonKey($e->getReason());

        return $t('refund_'.$key).' ('.$e->getMessage().')';
    }

    /** DB timestamps are already shop-local (import and protocol write shop time). */
    private function dbTime(string $dbValue): string
    {
        if ($dbValue === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($dbValue))->format('d.m.Y H:i');
        } catch (\Exception $e) {
            return $dbValue;
        }
    }

    /** PayPal's ISO 8601 time as shop time. */
    private function apiTime(string $iso): string
    {
        if ($iso === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($iso))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->format('d.m.Y H:i');
        } catch (\Exception $e) {
            return $iso;
        }
    }

    /** Like the shop's currency format for the order currency ("364.87€" on the demo). */
    private function money(float $amount): string
    {
        $row = $this->db->fetchRows("SELECT symbol_left, symbol_right, decimal_point, thousands_point, decimal_places FROM currencies WHERE code = 'EUR' LIMIT 1");
        if ($row === []) {
            return number_format($amount, 2, ',', '').' EUR';
        }
        $places = (int) $row[0]['decimal_places'];

        return $row[0]['symbol_left']
            . number_format(round($amount, $places), $places, (string) $row[0]['decimal_point'], (string) $row[0]['thousands_point'])
            . $row[0]['symbol_right'];
    }
}
