<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Outbox;

use ControlPlane\Identity\Security\TokenCodec;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Delivers security events without placing tokens or addresses in outbox payloads. */
final readonly class SecurityEmailProcessor implements EmailEventProcessor
{
    private const EVENTS = ['control_user.password_reset_requested', 'control_user.password_changed', 'control_user.mfa_enabled', 'control_user.mfa_disabled', 'control_user.recovery_codes_regenerated'];
    public function __construct(private Connection $connection, private TokenCodec $tokens, private EmailTransport $transport, private string $passwordResetUrl, private string $messageIdDomain) {}
    public function processOne(): bool
    {
        $row = $this->connection->transactional(function (Connection $db): ?array {
            $row=$db->fetchAssociative("SELECT id,event_id,payload,event_type FROM commercial.outbox_events WHERE event_type IN ('control_user.password_reset_requested','control_user.password_changed','control_user.mfa_enabled','control_user.mfa_disabled','control_user.recovery_codes_regenerated') AND attempts < 5 AND ((status IN ('pending','failed') AND available_at<=now()) OR (status='processing' AND locked_at<now()-interval '60 seconds')) ORDER BY available_at,id FOR UPDATE SKIP LOCKED LIMIT 1");
            if($row===false)return null; $lock=Uuid::v7()->toRfc4122(); $db->executeStatement("UPDATE commercial.outbox_events SET status='processing',attempts=attempts+1,locked_at=now(),lock_token=?,last_error=NULL WHERE id=?",[$lock,$row['id']]); $row['lock_token']=$lock; return $row;
        });
        if($row===null)return false;
        try { $message=$this->message($row); $this->transport->send(...$message); $this->connection->executeStatement("UPDATE commercial.outbox_events SET status='published',published_at=now(),locked_at=NULL,lock_token=NULL,last_error=NULL,payload='{}'::jsonb WHERE id=? AND lock_token=?",[$row['id'],$row['lock_token']]); }
        catch(\Throwable){$this->connection->executeStatement("UPDATE commercial.outbox_events SET status=CASE WHEN attempts>=5 THEN 'dead' ELSE 'failed' END,available_at=now()+interval '30 seconds',locked_at=NULL,lock_token=NULL,last_error='security_email_failure' WHERE id=? AND lock_token=?",[$row['id'],$row['lock_token']]);}
        return true;
    }
    /** @param array<string,mixed> $row @return array{0:string,1:string,2:string,3:string,4:string} */
    private function message(array $row): array
    {
        $payload=json_decode((string)$row['payload'],true); if(!is_array($payload)||!is_string($payload['user_id']??null)||!Uuid::isValid($payload['user_id']))throw new \DomainException('Invalid security email event.');
        $email=$this->connection->fetchOne('SELECT email FROM commercial.control_users WHERE id=? AND status=?',[$payload['user_id'],'active']); if(!is_string($email)||filter_var($email,FILTER_VALIDATE_EMAIL)===false)throw new \DomainException('No active recipient.');
        $type=(string)$row['event_type']; $subject='Platform security notice'; $text='A security setting for your Platform account changed.';
        if($type==='control_user.password_reset_requested'){if(!is_string($payload['reset_id']??null)||!Uuid::isValid($payload['reset_id']))throw new \DomainException('Invalid reset event.');$url=$this->passwordResetUrl.(str_contains($this->passwordResetUrl,'?')?'&':'?').'token='.rawurlencode($this->tokens->passwordResetToken($payload['reset_id']));$subject='Reset your Platform password';$text="Reset your Platform password: $url";}
        elseif($type==='control_user.password_changed'){$subject='Your Platform password changed';$text='Your Platform password was changed. If this was not you, contact support immediately.';}
        elseif($type==='control_user.mfa_enabled'){$subject='Two-factor authentication enabled';$text='Two-factor authentication was enabled for your Platform account.';}
        elseif($type==='control_user.mfa_disabled'){$subject='Two-factor authentication disabled';$text='Two-factor authentication was disabled for your Platform account.';}
        elseif($type==='control_user.recovery_codes_regenerated'){$subject='Recovery codes regenerated';$text='Your Platform recovery codes were regenerated.';}
        return [$email,$subject,$text,'<p>'.htmlspecialchars($text,ENT_QUOTES,'UTF-8').'</p>',(string)$row['event_id'].'@'.$this->messageIdDomain];
    }
}
