-- Synthetic financial history in the restored 25-migration schema only.
-- No trigger bypass, session_replication_role, real provider or user is used.
BEGIN;
DO $$ BEGIN
 IF current_database() <> 'platform_commercial_upgrade'
 OR current_setting('cluster_name') <> 'platform-upgrade-rehearsal-commercial' THEN
   RAISE EXCEPTION 'Legacy fixture requires isolated rehearsal Commercial';
 END IF;
 IF (SELECT count(*) FROM public.doctrine_migration_versions) <> 25 THEN
   RAISE EXCEPTION 'Legacy fixture requires the 25-migration source schema';
 END IF;
END $$;
INSERT INTO commercial.control_users(email,email_normalized,password_hash,status,email_verified_at,password_changed_at)
VALUES ('legacy-fixture@example.test','legacy-fixture@example.test','!synthetic-no-login!','active',now(),now()) RETURNING id AS owner_user \gset
INSERT INTO commercial.commercial_accounts(code,display_name)
VALUES ('rehearsal-legacy','Synthetic upgrade history') RETURNING id AS account \gset
INSERT INTO commercial.commercial_account_memberships(control_user_id,commercial_account_id,role)
VALUES (:'owner_user',:'account','owner');
INSERT INTO commercial.billing_profiles(commercial_account_id,legal_name,country_code,contact_email,address,is_default)
VALUES (:'account','Synthetic Fixture LLC','RU','fixture@example.test','{}',true) RETURNING id AS profile \gset
INSERT INTO commercial.plans(code,version,name)
VALUES ('rehearsal-legacy',1,'Synthetic immutable legacy plan') RETURNING id AS plan \gset
INSERT INTO commercial.plan_prices(plan_id,currency,amount_minor,billing_period)
VALUES (:'plan','RUB',5000,'month') RETURNING id AS price \gset
UPDATE commercial.plans SET status='published',published_at=now() WHERE id=:'plan';
INSERT INTO commercial.subscriptions(commercial_account_id,billing_profile_id,status)
VALUES (:'account',:'profile','draft') RETURNING id AS subscription \gset
INSERT INTO commercial.subscription_items(subscription_id,plan_price_id,quantity,valid_from)
VALUES (:'subscription',:'price',2,'2026-09-01T00:00:00Z') RETURNING id AS item \gset
INSERT INTO commercial.invoices(commercial_account_id,subscription_id,billing_profile_id,billing_reason,currency,total_minor,bill_to_legal_name,bill_to_country_code,bill_to_address,bill_to_contact_email)
VALUES (:'account',:'subscription',:'profile','subscription_create','RUB',10000,'Synthetic Fixture LLC','RU','{}','fixture@example.test') RETURNING id AS invoice \gset
INSERT INTO commercial.invoice_lines(invoice_id,subscription_item_id,plan_price_id,plan_code,plan_version,plan_name,billing_period,description,quantity,unit_amount_minor,total_minor)
VALUES (:'invoice',:'item',:'price','rehearsal-legacy',1,'Synthetic immutable legacy plan','month','Synthetic monthly subscription',2,5000,10000) RETURNING id AS line \gset
UPDATE commercial.invoices SET status='issued',issued_at=now() WHERE id=:'invoice';
INSERT INTO commercial.payments(invoice_id,commercial_account_id,amount_minor,currency)
VALUES (:'invoice',:'account',10000,'RUB') RETURNING id AS payment \gset
INSERT INTO commercial.payment_provider_accounts(provider_code,external_account_id,secret_ref,receipt_mode,receipt_vat_code,receipt_payment_subject,receipt_payment_mode)
VALUES ('synthetic-rehearsal','synthetic-no-external-provider','payments/synthetic-rehearsal','yookassa',1,'service','full_payment') RETURNING id AS provider \gset
INSERT INTO commercial.payment_attempts(payment_id,provider_account_id,method_type,provider_request_key)
VALUES (:'payment',:'provider','sbp','synthetic-rehearsal-attempt') RETURNING id AS attempt \gset
UPDATE commercial.payment_attempts SET status='succeeded',provider_payment_id='synthetic-provider-payment',completed_at=now() WHERE id=:'attempt';
UPDATE commercial.payments SET status='succeeded',succeeded_at=now() WHERE id=:'payment';
UPDATE commercial.invoices SET status='paid',paid_at=now() WHERE id=:'invoice';
INSERT INTO commercial.refunds(payment_id,commercial_account_id,provider_account_id,amount_minor,currency,client_request_key,request_sha256,provider_request_key)
VALUES (:'payment',:'account',:'provider',5000,'RUB','synthetic-rehearsal-refund',sha256('synthetic-fixture'::bytea),'synthetic-rehearsal-refund') RETURNING id AS refund \gset
INSERT INTO commercial.refund_items(refund_id,invoice_line_id,description,quantity,unit_amount_minor,total_minor,currency,vat_code,payment_subject,payment_mode)
VALUES (:'refund',:'line','Synthetic monthly subscription',1,5000,5000,'RUB',1,'service','full_payment');
UPDATE commercial.refunds SET status='succeeded',provider_refund_id='synthetic-provider-refund',completed_at=now() WHERE id=:'refund';
INSERT INTO commercial.receipts(payment_id,invoice_id,commercial_account_id,payment_attempt_id,provider_account_id,receipt_type,provider_request_key,amount_minor,currency,customer_email,vat_code,payment_subject,payment_mode)
VALUES (:'payment',:'invoice',:'account',:'attempt',:'provider','payment','synthetic-payment-receipt',10000,'RUB','fixture@example.test',1,'service','full_payment') RETURNING id AS receipt \gset
INSERT INTO commercial.receipt_items(receipt_id,invoice_line_id,description,quantity,unit_amount_minor,total_minor,currency,vat_code,payment_subject,payment_mode)
VALUES (:'receipt',:'line','Synthetic monthly subscription',2,5000,10000,'RUB',1,'service','full_payment');
UPDATE commercial.receipts SET status='succeeded',external_receipt_id='synthetic-payment-receipt',completed_at=now() WHERE id=:'receipt';
INSERT INTO commercial.receipts(payment_id,invoice_id,commercial_account_id,refund_id,provider_account_id,receipt_type,provider_request_key,amount_minor,currency,customer_email,vat_code,payment_subject,payment_mode)
VALUES (:'payment',:'invoice',:'account',:'refund',:'provider','refund','synthetic-refund-receipt',5000,'RUB','fixture@example.test',1,'service','full_payment') RETURNING id AS refund_receipt \gset
INSERT INTO commercial.receipt_items(receipt_id,invoice_line_id,description,quantity,unit_amount_minor,total_minor,currency,vat_code,payment_subject,payment_mode)
VALUES (:'refund_receipt',:'line','Synthetic monthly subscription',1,5000,5000,'RUB',1,'service','full_payment');
UPDATE commercial.receipts SET status='succeeded',external_receipt_id='synthetic-refund-receipt',completed_at=now() WHERE id=:'refund_receipt';
-- Force deferred invoice/refund/receipt snapshot validation before commit.
SET CONSTRAINTS ALL IMMEDIATE;
COMMIT;
