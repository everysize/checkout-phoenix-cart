# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0

- Order import from the everysize checkout into CE Phoenix Cart 1.1
  (guest order, line items with the net unit price Phoenix stores, size
  attributes with zero surcharge, order totals in Phoenix' layout, stock
  handling, REVOKED orders straight to the cancellation status).
- Order number, fulfilment with tracking code and carrier, cancellation,
  return and automatic refund reported back to everysize (idempotent,
  tracking codes corrected for up to 14 days).
- Refund form in the order detail box (full or partial, via the checkout
  to PayPal) with per-request idempotency key and known-reason messages;
  a "goods came back" checkbox reports the return first and then refunds
  the entered amount (e.g. without shipping), or reports it alone.
- Admin pages: settings (credentials never written back, connection test,
  status mapping, order confirmation, auto-refund, manual sync) and
  module log (31 days, filter, 50 per page).
- Order list: everysize marker next to the order total and a search field
  for the everysize order number.
- CLI sync entry point for system crons (`--json`, `--force`).
