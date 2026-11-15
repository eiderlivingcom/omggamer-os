-- Read-only: pricing rules and how many variants each supplier's offers price.
\pset pager off
SELECT r.id, r.name, r.scope, r.scope_value, s.code AS storefront, r.priority, r.mode, r.is_active,
       r.markup_percent, r.markup_fixed, r.target_profit_percent, r.target_profit_fixed,
       r.gateway_fee_percent, r.gateway_fee_fixed, r.sale_percent, r.coupon_percent, r.rounding, r.min_price
FROM pricing_rules r LEFT JOIN storefronts s ON s.id = r.storefront_id ORDER BY r.priority DESC, r.created_at;
SELECT column_name FROM information_schema.columns WHERE table_name = 'pricing_rules' ORDER BY ordinal_position;
SELECT sup.code, count(DISTINCT sp.id) AS supplier_products, count(o.id) AS offers
FROM suppliers sup LEFT JOIN supplier_products sp ON sp.supplier_id = sup.id LEFT JOIN supplier_offers o ON o.supplier_product_id = sp.id
GROUP BY sup.code;
