-- Orders from storefronts arrive in OMGGamer OS but nothing is bought from a supplier until staff click Fulfil.
insert into app_settings (key, value) values ('orders', '{"auto_fulfil": false}')
on conflict (key) do update set value = app_settings.value || '{"auto_fulfil": false}', updated_at = now();
select key, value from app_settings where key = 'orders';
