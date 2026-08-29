# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project aims to follow
semantic versioning once it reaches 1.0.

## [Unreleased]

### Added

- Initial release: orders + checkout for NimbusCMS.
  - Order lifecycle `pending → paid → fulfilled` (or `cancelled`) on the plugin's
    own `commerce_order` / `commerce_order_line` tables.
  - Stock choreography through Inventory's reservation port (ADR 0019): place
    reserves, fulfil issues (ships), cancel releases — atomic across the plugin
    boundary (a multi-line order reserves everything or nothing), and refused
    cleanly when no inventory plugin is installed.
  - MCP tools `shop_place_order` / `pay_order` / `fulfil_order` / `cancel_order` /
    `get_order` / `list_orders`, gated on `commerce:read` / `commerce:write`.
  - `commerce.placed` / `paid` / `fulfilled` / `cancelled` events; an agent guide.
