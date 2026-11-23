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
    /**
     * @param array<string,mixed> $row
     * @return array{0:string,1:string,2:string,3:string,4:string}
     */
    private function message(array $row): array
    {
        $payload=json_decode((string)$row['payload'],true); if(!is_array($payload)||!is_string($payload['user_id']??null)||!Uuid::isValid($payload['user_id']))throw new \DomainException('Invalid security email event.');
        $email=$this->connection->fetchOne('SELECT email FROM commercial.control_users WHERE id=? AND status=?',[$payload['user_id'],'active']); if(!is_string($email)||filter_var($email,FILTER_VALIDATE_EMAIL)===false)throw new \DomainException('No active recipient.');
        $locale = (isset($payload['locale']) && is_string($payload['locale']) && str_starts_with($payload['locale'], 'ru')) ? 'ru' : 'en';
        $type = (string) $row['event_type'];
        if (!in_array($type, self::EVENTS, true)) throw new \DomainException('Unsupported security email event.');
        $body = '';

        if ($locale === 'ru') {
            $subject = 'Уведомление безопасности Platform';
            $text = 'Настройки безопасности для Platform customer account были изменены.';
            if ($type === 'control_user.password_reset_requested') {
                if (!is_string($payload['reset_id'] ?? null) || !Uuid::isValid($payload['reset_id'])) throw new \DomainException('Invalid reset event.');
                $url = $this->passwordResetUrl . (str_contains($this->passwordResetUrl, '?') ? '&' : '?') . 'token=' . rawurlencode($this->tokens->passwordResetToken($payload['reset_id']));
                $subject = '[Platform Cabinet] Сброс вашего пароля';
                $text = "[Platform Cabinet] Сброс вашего пароля для Platform customer account: $url";
                $body = '<p>Мы получили запрос на сброс пароля для вашего Platform customer account.</p>'
                    . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Выбрать новый пароль</a></p>'
                    . '<p>Если вы не запрашивали сброс пароля, вы можете проигнорировать это письмо.</p>';
            } elseif ($type === 'control_user.password_changed') {
                $subject = '[Platform Cabinet] Пароль изменён';
                $text = 'Пароль для вашего Platform customer account был успешно изменён. Если это были не вы, немедленно свяжитесь со службой поддержки.';
            } elseif ($type === 'control_user.mfa_enabled') {
                $subject = 'Двухфакторная аутентификация включена';
                $text = 'Двухфакторная аутентификация была включена для вашего Platform customer account.';
            } elseif ($type === 'control_user.mfa_disabled') {
                $subject = 'Двухфакторная аутентификация отключена';
                $text = 'Двухфакторная аутентификация была отключена для вашего Platform customer account.';
            } elseif ($type === 'control_user.recovery_codes_regenerated') {
                $subject = 'Резервные коды созданы заново';
                $text = 'Резервные коды для вашего Platform customer account были успешно обновлены.';
            }
        } else {
            $subject = 'Platform security notice';
            $text = 'A security setting for your Platform account changed.';
            if ($type === 'control_user.password_reset_requested') {
                if (!is_string($payload['reset_id'] ?? null) || !Uuid::isValid($payload['reset_id'])) throw new \DomainException('Invalid reset event.');
                $url = $this->passwordResetUrl . (str_contains($this->passwordResetUrl, '?') ? '&' : '?') . 'token=' . rawurlencode($this->tokens->passwordResetToken($payload['reset_id']));
                $subject = '[Platform Cabinet] Reset your password';
                $text = "[Platform Cabinet] Reset your password: $url";
                $body = '<p>We received a request to reset the password for your Platform customer account.</p>'
                    . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Choose a new password</a></p>'
                    . '<p>If you did not request this, you can ignore this email.</p>';
            } elseif ($type === 'control_user.password_changed') {
                $subject = '[Platform Cabinet] Your password changed';
                $text = 'Your Platform customer account password was changed. If this was not you, contact support immediately.';
            } elseif ($type === 'control_user.mfa_enabled') {
                $subject = 'Two-factor authentication enabled';
                $text = 'Two-factor authentication was enabled for your Platform customer account.';
            } elseif ($type === 'control_user.mfa_disabled') {
                $subject = 'Two-factor authentication disabled';
                $text = 'Two-factor authentication was disabled for your Platform customer account.';
            } elseif ($type === 'control_user.recovery_codes_regenerated') {
                $subject = 'Recovery codes regenerated';
                $text = 'Your Platform recovery codes were regenerated.';
            }
        }
        if ($body === '') {
            $body = '<p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        return [$email, $subject, $text, ControlEmailLayout::render($subject, $body, $locale), (string)$row['event_id'] . '@' . $this->messageIdDomain];
    }
}
