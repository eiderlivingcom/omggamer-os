-- AI Studio diagnostics: recent jobs, provider settings, which keys are stored (never the keys themselves).
select created_at, status, outcome, attempts, model, left(error_message, 300) from ai_jobs order by created_at desc limit 6;
select value->>'provider' provider, value->>'model' model, value->>'base_url' base_url, value->>'json_mode' json_mode
  from app_settings where key = 'ai';
select provider, status, credentials_enc is not null as key_stored, left(last_error, 200) from integration_credentials;
