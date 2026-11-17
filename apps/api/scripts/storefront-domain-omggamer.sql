-- The store at omggamer2-9tw2.1wp.site went live as omggamer.com (storefront code omggamer-staging is kept, because
-- the WordPress connector and AI Studio use it). Point OMGGamer OS at the new address.
update storefronts
   set domain = 'omggamer.com',
       name = 'OMGGamer (omggamer.com)',
       configuration = case
           when configuration->>'connector_url' like '%omggamer2-9tw2.1wp.site%'
           then jsonb_set(configuration, '{connector_url}', to_jsonb(replace(configuration->>'connector_url', 'omggamer2-9tw2.1wp.site', 'omggamer.com')))
           else configuration end,
       updated_at = now()
 where code = 'omggamer-staging';
select code, name, domain, status, configuration->>'connector_url' as connector_url from storefronts;
