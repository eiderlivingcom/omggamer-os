-- Order automation settings and the latest orders received from storefronts.
select key, value from app_settings where key in ('orders', 'automation');
select o.created_at, o.external_order_id, o.status, o.total from orders o order by o.created_at desc limit 5;
