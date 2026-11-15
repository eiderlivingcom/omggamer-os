-- Copy every Kinguin supplier pricing rule to Foxreload with identical settings (safe to run again).
\pset pager off
INSERT INTO pricing_rules (id, name, scope, scope_value, storefront_id, priority, markup_percent, markup_fixed,
  min_margin_percent, min_margin_fixed, min_price, rounding, is_active, created_at, updated_at, mode,
  target_profit_percent, target_profit_fixed, gateway_fee_percent, gateway_fee_fixed, sale_percent,
  coupon_percent, pro_percent)
SELECT gen_random_uuid(), left(r.name || ' (Foxreload)', 120), r.scope, 'foxreload', r.storefront_id, r.priority,
  r.markup_percent, r.markup_fixed, r.min_margin_percent, r.min_margin_fixed, r.min_price, r.rounding, r.is_active,
  now(), now(), r.mode, r.target_profit_percent, r.target_profit_fixed, r.gateway_fee_percent, r.gateway_fee_fixed,
  r.sale_percent, r.coupon_percent, r.pro_percent
FROM pricing_rules r
WHERE r.scope = 'supplier' AND r.scope_value = 'kinguin'
  AND NOT EXISTS (SELECT 1 FROM pricing_rules f WHERE f.scope = 'supplier' AND f.scope_value = 'foxreload'
                  AND f.name = left(r.name || ' (Foxreload)', 120));
SELECT name, scope_value, priority, mode, is_active, markup_percent, markup_fixed, target_profit_percent,
       target_profit_fixed, gateway_fee_percent, gateway_fee_fixed, sale_percent, coupon_percent, pro_percent, rounding, min_price
FROM pricing_rules WHERE scope = 'supplier' ORDER BY scope_value, priority DESC;
