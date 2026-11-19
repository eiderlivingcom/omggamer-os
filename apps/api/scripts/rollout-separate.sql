-- Separate listings for every game (one store product per platform + edition + region + type).
UPDATE app_settings SET value = value || '{"split_by_platform": "all"}'::jsonb, updated_at = now()
 WHERE key = 'publishing' RETURNING key, value->>'split_by_platform' AS mode;
INSERT INTO app_settings (key, value) SELECT 'publishing', '{"split_by_platform": "all", "split_products": []}'::jsonb
 WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE key = 'publishing');
SELECT product_status, count(*) FROM products GROUP BY 1 ORDER BY 1;
SELECT count(DISTINCT product_id) AS live_games FROM storefront_product_mappings WHERE sync_status = 'synced' AND listing = '';
