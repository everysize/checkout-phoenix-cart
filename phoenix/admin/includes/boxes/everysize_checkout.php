<?php
/*
  $Id$

  everysize Checkout — Menü-Eintrag im Phoenix-Admin. Phoenix baut das
  Menü aus admin/includes/boxes/*.php (siehe includes/hooks/admin/siteWide/
  hMenu.php) — der Eintrag zeigt immer auf die Add-on-Seite; diese bietet
  bei Bedarf zuerst die Installation an.
*/

  $cl_box_groups[] = ['sort' => 40, 'heading' => BOX_HEADING_EVERYSIZE, 'apps' => [
    [
      'code' => 'everysize_checkout.php',
      'title' => MODULES_ADMIN_MENU_EVERYSIZE_CHECKOUT,
      'link' => $GLOBALS['Admin']->link('everysize_checkout.php'),
    ],
    [
      'code' => 'everysize_checkout_protocol.php',
      'title' => MODULES_ADMIN_MENU_EVERYSIZE_CHECKOUT_PROTOCOL,
      'link' => $GLOBALS['Admin']->link('everysize_checkout_protocol.php'),
    ],
  ]];
