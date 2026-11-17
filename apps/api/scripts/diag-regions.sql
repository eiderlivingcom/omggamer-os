-- Every region label in use, with how many variants and offers use it.
select coalesce(v.region, '(none)') as region, count(*) as variants
  from product_variants v group by 1 order by 2 desc;
