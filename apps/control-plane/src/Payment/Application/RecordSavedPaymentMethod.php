<?php
declare(strict_types=1);
namespace ControlPlane\Payment\Application;
use ControlPlane\Payment\Provider\ProviderAccount;
use ControlPlane\Payment\Provider\ProviderPayment;
use Doctrine\DBAL\Connection;
final readonly class RecordSavedPaymentMethod
{
 public function __construct(private Connection $connection) {}
 public function record(ProviderAccount $account, ProviderPayment $payment, string $attemptId): void
 {
  $method=$payment->paymentMethod; if($method===null||!$method->saved||$method->type!=='bank_card')return;
  $this->connection->transactional(function(Connection $db)use($account,$payment,$attemptId,$method):void{
   $db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$account->id]);
   $row=$db->fetchAssociative('SELECT p.commercial_account_id FROM commercial.payment_attempts a JOIN commercial.payments p ON p.id=a.payment_id WHERE a.id=? AND a.provider_account_id=? FOR UPDATE',[$attemptId,$account->id]); if($row===false)throw new \DomainException('Payment attempt was not found.');
   $db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$row['commercial_account_id']]);
   $existing=$db->fetchAssociative('SELECT id FROM commercial.saved_payment_methods WHERE provider_account_id=? AND provider_method_reference=? FOR UPDATE',[$account->id,$method->id]);
   if($existing!==false){$db->executeStatement('UPDATE commercial.saved_payment_methods SET updated_at=now(), last_used_at=now() WHERE id=?',[$existing['id']]);return;}
   $default=$db->fetchOne("SELECT 1 FROM commercial.saved_payment_methods WHERE commercial_account_id=? AND status='active' AND is_default LIMIT 1",[$row['commercial_account_id']])===false;
   $db->executeStatement("INSERT INTO commercial.saved_payment_methods (commercial_account_id,provider_account_id,provider_method_reference,method_type,status,display_title,card_brand,last4,expiry_month,expiry_year,is_default,created_from_payment_attempt_id) VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?)",[$row['commercial_account_id'],$account->id,$method->id,$method->type,$method->title,$method->brand,$method->last4,$method->expiryMonth,$method->expiryYear,$default,$attemptId]);
   $db->executeStatement("INSERT INTO commercial.audit_events (actor_type,action,commercial_account_id,resource_type,resource_id,correlation_id,outcome) VALUES ('system','payment_method.saved',?,'payment_attempt',?,uuidv7(),'success')",[$row['commercial_account_id'],$attemptId]);
  });
 }
}
