\pset pager off
\echo '== foxreload supplier products'
SELECT sp.external_product_id, left(sp.original_title,40), sp.import_status, sp.variant_id IS NOT NULL AS has_variant, sp.raw_payload->>'price' AS price, sp.raw_payload->>'quantity' AS qty
FROM supplier_products sp JOIN suppliers s ON s.id = sp.supplier_id WHERE s.code = 'foxreload';
\echo '== foxreload offers'
SELECT o.external_offer_id, o.variant_id, o.currency, o.supplier_price, o.available_quantity, o.availability_status
FROM supplier_offers o JOIN supplier_products sp ON sp.id = o.supplier_product_id JOIN suppliers s ON s.id = sp.supplier_id WHERE s.code = 'foxreload';
\echo '== star wars outlaws variants'
SELECT v.sku, v.platform, v.edition, v.region, v.activation_type, (SELECT count(*) FROM supplier_offers o WHERE o.variant_id = v.id) AS offers
FROM product_variants v WHERE v.product_id = '80896b42-724d-426e-a67a-d11bb2766736' ORDER BY v.platform, v.region;
