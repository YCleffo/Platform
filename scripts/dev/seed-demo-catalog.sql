\set ON_ERROR_STOP on
SET client_encoding = 'UTF8';

BEGIN;

INSERT INTO commercial.features(code, value_type, description, usage_mode) VALUES
  ('seats', 'integer', 'Included seats', 'none'),
  ('projects', 'integer', 'Included projects', 'none'),
  ('analytics', 'boolean', 'Advanced analytics', 'none'),
  ('support', 'string', 'Support level', 'none'),
  ('api_calls', 'integer', 'API calls per billing period', 'counter'),
  ('storage_gb', 'integer', 'Storage limit in GB', 'gauge'),
  ('sso', 'boolean', 'Single sign-on', 'none'),
  ('audit_retention_days', 'integer', 'Audit retention in days', 'none')
ON CONFLICT (code) DO NOTHING;

DO $$
DECLARE
  v_plan record;
  v_plan_id uuid;
  v_plan_status text;
  v_feature record;
  v_feature_id uuid;
BEGIN
  FOR v_plan IN
    SELECT *
    FROM jsonb_to_recordset($json$[
      {"code":"start","name":"Старт","month":99000,"year":990000,
       "features":{"seats":3,"projects":10,"analytics":false,"sso":false,"support":"email","api_calls":10000,"storage_gb":5,"audit_retention_days":30}},
      {"code":"business","name":"Бизнес","month":299000,"year":2990000,
       "features":{"seats":25,"projects":100,"analytics":true,"sso":true,"support":"priority","api_calls":100000,"storage_gb":50,"audit_retention_days":180}},
      {"code":"scale","name":"Масштаб","month":599000,"year":5990000,
       "features":{"seats":50,"projects":300,"analytics":true,"sso":true,"support":"priority","api_calls":300000,"storage_gb":200,"audit_retention_days":365}},
      {"code":"enterprise","name":"Корпоративный","month":999000,"year":9990000,
       "features":{"seats":100,"projects":1000,"analytics":true,"sso":true,"support":"dedicated","api_calls":1000000,"storage_gb":500,"audit_retention_days":365}}
    ]$json$::jsonb) AS x(code text, name text, month bigint, year bigint, features jsonb)
  LOOP
    INSERT INTO commercial.plans(code, version, status, name, published_at)
    VALUES (v_plan.code, 1, 'draft', v_plan.name, NULL)
    ON CONFLICT (code, version) DO NOTHING;

    SELECT id, status INTO v_plan_id, v_plan_status
    FROM commercial.plans WHERE code = v_plan.code AND version = 1;

    IF v_plan_status = 'draft' THEN
      INSERT INTO commercial.plan_prices(plan_id, currency, amount_minor, billing_period) VALUES
        (v_plan_id, 'RUB', v_plan.month, 'month'),
        (v_plan_id, 'RUB', v_plan.year, 'year')
      ON CONFLICT (plan_id, currency, billing_period) DO NOTHING;

      FOR v_feature IN SELECT key, value FROM jsonb_each(v_plan.features) LOOP
        SELECT id INTO v_feature_id FROM commercial.features WHERE code = v_feature.key;
        INSERT INTO commercial.plan_features(plan_id, feature_id, value)
        VALUES (v_plan_id, v_feature_id, v_feature.value)
        ON CONFLICT (plan_id, feature_id) DO NOTHING;
      END LOOP;
    END IF;
  END LOOP;

  UPDATE commercial.plans
  SET status = 'published', published_at = now()
  WHERE code IN ('start', 'business', 'scale', 'enterprise') AND version = 1 AND status = 'draft';
END $$;

COMMIT;
