<?php

/*
  $Id$

  everysize Checkout — Hooks für die Admin-Bestellseite (admin/orders.php).
  Phoenix lädt Dateien aus includes/hooks/admin/<seite>/ als Klasse
  hook_admin_<seite>_<dateiname> und ruft deren listen_<aktion>-Methoden an
  den Hookpunkten der Views (admin/includes/actions/orders/…):

  - listen_orderTab          die "everysize"-Box im Bestelldetail (nur bei
                             everysize-Bestellungen), gerendert von der
                             OrderBox-Klasse des Add-ons
  - listen_injectSQL         Kennzeichen "everysize <Nummer>" in der
                             Bestellübersicht (die Spalten des Views sind
                             hart kodiert — die Nummer reist als Suffix der
                             Gesamtsumme, strip_tags macht daraus Text) und
                             der Filter für die everysize-Nummernsuche
  - listen_injectFilterForm  das Suchfeld der everysize-Nummernsuche

  Alle Methoden booten den Add-on-Autoloader selbst — der Admin-Kontext
  kennt ihn nicht von sich aus.
*/

use Everysize\Checkout\Phoenix\OrderBox;
use Everysize\Checkout\Phoenix\PhoenixDb;
use Everysize\Checkout\Phoenix\PhoenixFactory;
use Everysize\Checkout\Phoenix\PhoenixSettings;

class hook_admin_orders_everysize_checkout
{
    /** everysize-Nummer als GET-Parameter der Übersichtssuche. */
    private const SEARCH_PARAM = 'everysize_order_id';

    private ?PhoenixDb $db = null;

    public function listen_orderTab(): string
    {
        $this->boot();

        $orderId = (string) ($_GET['oID'] ?? '');
        if ($orderId === '' || !ctype_digit($orderId)) {
            return '';
        }

        $db = $this->db();
        $settings = new PhoenixSettings($db);
        $client = $settings->isConfigured() ? PhoenixFactory::client($settings) : null;
        $links = new \Everysize\Checkout\OrderLinks($db, $settings);

        $box = new OrderBox($db, $settings, $links, $client);

        return $box->render(
            $orderId,
            (string) $GLOBALS['Admin']->link('everysize_checkout.php', ['action' => 'orderRefund']),
            (string) $GLOBALS['Admin']->link('everysize_checkout.php', ['action' => 'orderTracking']),
            (string) ($_SESSION['sessiontoken'] ?? ''),
            bin2hex(random_bytes(16)), // frischer Idempotency-Key je Seitenaufruf
            OrderBox::loadTexts(OrderBox::languageDirectory($db, (int) ($_SESSION['languages_id'] ?? 0))),
            PhoenixSettings::CARRIERS
        );
    }

    public function listen_injectSQL($parameters): string
    {
        $this->boot();

        $sql = (string) ($parameters['orders_sql'] ?? '');
        if ($sql === '' || strpos($sql, 'everysize_checkout_order') !== false) {
            return ''; // nicht unser SQL oder schon injiziert
        }

        // Verknüpfung für Kennzeichen und Suche (orders_id ist PRIMARY KEY
        // der Link-Tabelle — der LEFT JOIN kann keine Zeilen vervielfachen).
        $join = ' LEFT JOIN everysize_checkout_order e ON e.orders_id = o.orders_id';
        $whereAt = stripos($sql, ' WHERE ');
        $sql = $whereAt === false
            ? $sql.$join
            : substr($sql, 0, $whereAt).$join.substr($sql, $whereAt);

        // Kennzeichen: die Spalten der Übersicht sind hart kodiert
        // (admin/includes/actions/orders/views/default.php), daher reist die
        // Nummer als Suffix der Gesamtsumme — die View macht strip_tags
        // darüber, im Bestelldetail steht die Nummer in der Box.
        $sql = str_replace(
            'ot.text AS order_total',
            "CONCAT(ot.text, IF(e.everysize_order_id IS NULL, '', CONCAT(' — everysize ', e.everysize_order_id))) AS order_total",
            $sql
        );

        // Suche nach der everysize-Nummer (exakt).
        $search = trim((string) ($_GET[self::SEARCH_PARAM] ?? ''));
        if ($search !== '') {
            $clause = "e.everysize_order_id = '".$GLOBALS['db']->escape($search)."'";
            $sql = $whereAt === false
                ? $sql.' WHERE '.$clause
                : $sql.' AND '.$clause;
        }

        $parameters['orders_sql'] = $sql;

        return '';
    }

    public function listen_injectFilterForm(): string
    {
        $this->boot();

        $keywords = (string) ($_GET[self::SEARCH_PARAM] ?? '');
        $texts = OrderBox::loadTexts(OrderBox::languageDirectory($this->db(), (int) ($_SESSION['languages_id'] ?? 0)));
        $label = $texts['overview_search'] ?? 'everysize-Nummer';

        // Active filter: shown as its own hint with a reset link. The form
        // lives inside Phoenix' collapsed search panel — open the panel, so
        // the filter (and its reset link) is visible without a click.
        $active = $keywords !== ''
            ? '<div class="form-text mb-1 text-muted">'
                . htmlspecialchars(sprintf($texts['overview_search_active'] ?? 'Filter: %s', $keywords), ENT_QUOTES, 'UTF-8')
                . ' <a href="'.htmlspecialchars((string) $GLOBALS['Admin']->link('orders.php'), ENT_QUOTES, 'UTF-8').'" title="'.htmlspecialchars($texts['overview_search_reset'] ?? 'Filter zurücksetzen', ENT_QUOTES, 'UTF-8').'"><i class="fas fa-times-circle"></i></a>'
                . '</div>'
                . '<script>var eycPanel=document.getElementById("collapseSearch");'
                . 'if(eycPanel){eycPanel.classList.add("show");}'
                . 'var eycTrigger=document.querySelector(\'[data-bs-target="#collapseSearch"]\');'
                . 'if(eycTrigger){eycTrigger.setAttribute("aria-expanded","true");}</script>'
            : '';

        return '<div class="col-6 align-self-center">'
            . (new Form('everysize_search', $GLOBALS['Admin']->link('orders.php'), 'get'))->hide_session_id()
            . '<div class="input-group mb-1">'
            . '<span class="input-group-text">'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</span>'
            . new Input(self::SEARCH_PARAM, ['value' => htmlspecialchars($keywords, ENT_QUOTES, 'UTF-8'), 'autocomplete' => 'off'])
            . '</div>'
            . '</form>'
            . $active
            . '</div>';
    }

    // --- Helpers -----------------------------------------------------------------

    /** Das Add-on-Autoloader (Kern + Phoenix-Schicht) — mehrfach aufrufbar. */
    private function boot(): void
    {
        require_once DIR_FS_CATALOG.'ext/everysize_checkout/autoload.php';
    }

    /** Eine Verbindung pro Hook-Instanz (mehrere listen_-Aufrufe pro Seite). */
    private function db(): PhoenixDb
    {
        return $this->db ??= PhoenixFactory::db();
    }
}
