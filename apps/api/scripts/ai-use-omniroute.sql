-- Switch AI Studio to OmniRoute (free-stack combo) and show the result.
insert into app_settings (key, value) values ('ai', '{"provider": "omniroute", "model": "free-stack", "base_url": ""}')
on conflict (key) do update
  set value = app_settings.value || '{"provider": "omniroute", "model": "free-stack", "base_url": ""}', updated_at = now();
select value->>'provider', value->>'model' from app_settings where key = 'ai';
