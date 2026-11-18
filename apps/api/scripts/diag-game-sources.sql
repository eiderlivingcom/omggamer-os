-- Read-only: every supplier record of one game, its raw platform/region fields and the variant it landed in.
\pset pager off
SELECT s.code, sp.external_product_id AS ext, left(sp.original_title, 70) AS title,
       sp.raw_payload->>'platform' AS raw_platform,
       coalesce(sp.raw_payload->>'regionalLimitations', sp.raw_payload->>'region', sp.raw_payload#>>'{region,name}') AS raw_region,
       left(coalesce(sp.raw_payload->>'activationDetails', sp.raw_payload->>'type', ''), 40) AS raw_type,
       v.platform, v.region, v.activation_type AS act, v.sku,
       (SELECT count(*) FROM supplier_offers o WHERE o.supplier_product_id = sp.id AND o.availability_status = 'in_stock' AND o.available_quantity > 0) AS live_offers
  FROM supplier_products sp
  JOIN suppliers s ON s.id = sp.supplier_id
  LEFT JOIN product_variants v ON v.id = sp.variant_id
  JOIN products p ON p.id = sp.product_id
 WHERE p.sku = 'OMG-G022095'
 ORDER BY v.sku, s.code, sp.external_product_id;
SELECT DISTINCT jsonb_object_keys(sp.raw_payload) AS kinguin_keys
  FROM supplier_products sp JOIN products p ON p.id = sp.product_id JOIN suppliers s ON s.id = sp.supplier_id
 WHERE p.sku = 'OMG-G022095' AND s.code = 'kinguin';
