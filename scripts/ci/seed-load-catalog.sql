\set ON_ERROR_STOP on
-- Synthetic fixtures for the explicitly disposable Commercial database only.
BEGIN;
DO $$ BEGIN
  IF current_database() <> 'platform_commercial_test' THEN
    RAISE EXCEPTION 'Load fixtures require platform_commercial_test';
  END IF;
END $$;
INSERT INTO commercial.features(code, value_type, description)
VALUES ('load.seats', 'integer', 'Synthetic load fixture') ON CONFLICT (code) DO NOTHING;
INSERT INTO commercial.plans(code, version, name)
VALUES ('load.basic', 1, 'Load Basic'), ('load.team', 1, 'Load Team'), ('load.business', 1, 'Load Business')
ON CONFLICT (code, version) DO NOTHING;
INSERT INTO commercial.plan_prices(plan_id, currency, amount_minor, billing_period)
SELECT id, 'RUB', CASE code WHEN 'load.basic' THEN 90000 WHEN 'load.team' THEN 199000 ELSE 399000 END, 'month'
FROM commercial.plans WHERE code IN ('load.basic', 'load.team', 'load.business') AND status = 'draft'
ON CONFLICT (plan_id, currency, billing_period) DO NOTHING;
INSERT INTO commercial.plan_features(plan_id, feature_id, value)
SELECT p.id, f.id, to_jsonb(CASE p.code WHEN 'load.basic' THEN 5 WHEN 'load.team' THEN 20 ELSE 100 END)
FROM commercial.plans p CROSS JOIN commercial.features f
WHERE p.code IN ('load.basic', 'load.team', 'load.business') AND p.status = 'draft' AND f.code = 'load.seats'
ON CONFLICT (plan_id, feature_id) DO NOTHING;
UPDATE commercial.plans SET status = 'published', published_at = now()
WHERE code IN ('load.basic', 'load.team', 'load.business') AND status = 'draft';
COMMIT;
