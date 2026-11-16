-- Read-only: how in-game currency products are titled, and current product types.
\pset pager off
SELECT product_type, count(*) FROM products WHERE product_status <> 'merged' GROUP BY 1 ORDER BY 2 DESC;
SELECT p.product_type, left(sp.original_title, 90) AS title, sp.raw_payload->'tags' AS tags
FROM supplier_products sp JOIN products p ON p.id = sp.product_id
WHERE sp.original_title ~* '(cash|bucks|robux|coins?|points|gems|diamonds|crystals|credits|shark card|top[ -]?up|\mUC\M|primogems|minecoins|currency)'
ORDER BY random() LIMIT 60;
SELECT count(*) AS currency_like FROM supplier_products
WHERE original_title ~* '(\m[0-9][0-9,.]*\s*k?\s*(gta\$|v-?bucks|robux|coins?|points|gems|diamonds|crystals|credits|uc|cash|bucks|minecoins|primogems)\M|shark card|cash card|game cash|in-?game currency)';
