<?php

declare(strict_types=1);

namespace ControlPlane\Infrastructure\Outbox;

final class ControlEmailLayout
{
    public static function render(string $title, string $bodyHtml, string $locale = 'en'): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $content = self::inlineStyles($bodyHtml);
        $year = date('Y');
        $isRu = str_starts_with($locale, 'ru');
        $htmlLang = $isRu ? 'ru' : 'en';
        $footerMsg1 = $isRu ? 'Это автоматическое сообщение платформы Platform.' : 'This is an automated message from Platform.';
        $footerMsg2 = $isRu ? 'Если вы не ожидали этого письма, обратитесь к администратору вашей платформы.' : 'If you were not expecting this email, contact your platform administrator.';

        return <<<HTML
<!DOCTYPE html>
<html lang="{$htmlLang}">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$safeTitle}</title>
</head>
<body style="margin:0;padding:0;background:#f3f5f9;color:#111827;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{$safeTitle}</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;background:#f3f5f9;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e5e9f2;border-radius:16px;overflow:hidden;">
                    <tr>
                        <td style="background:#2563eb;padding:18px 28px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="width:36px;height:36px;border-radius:10px;background:rgba(255,255,255,0.18);color:#ffffff;text-align:center;font:700 18px/36px Arial,Helvetica,sans-serif;">P</td>
                                    <td style="padding-left:12px;color:#ffffff;font:700 18px/1.2 Arial,Helvetica,sans-serif;">Platform</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 28px 8px;">
                            <h1 style="margin:0 0 20px;color:#111827;font:700 24px/1.25 Arial,Helvetica,sans-serif;">{$safeTitle}</h1>
                            {$content}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 28px 28px;border-top:1px solid #eef1f6;color:#6b7280;font:400 12px/1.6 Arial,Helvetica,sans-serif;">
                            <p style="margin:0 0 6px;color:#6b7280;font:400 12px/1.6 Arial,Helvetica,sans-serif;">{$footerMsg1}</p>
                            <p style="margin:0;color:#6b7280;font:400 12px/1.6 Arial,Helvetica,sans-serif;">{$footerMsg2}</p>
                            <p style="margin:12px 0 0;color:#9ca3af;font:400 12px/1.6 Arial,Helvetica,sans-serif;">&copy; {$year} Platform</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }

    private static function inlineStyles(string $content): string
    {
        $content = preg_replace_callback(
            '/<p\b([^>]*)>/i',
            static fn(array $match): string => str_contains($match[1], 'style=')
                ? $match[0]
                : '<p' . $match[1] . ' style="margin:0 0 16px;color:#374151;font:400 15px/1.65 Arial,Helvetica,sans-serif;">',
            $content,
        ) ?? $content;
        $content = preg_replace_callback(
            '/<a\b([^>]*)>/i',
            static fn(array $match): string => str_contains($match[1], 'style=')
                ? $match[0]
                : '<a' . $match[1] . ' style="display:inline-block;padding:12px 20px;border-radius:9px;background:#2563eb;color:#ffffff;font:700 15px/1 Arial,Helvetica,sans-serif;text-decoration:none;">',
            $content,
        ) ?? $content;

        return $content;
    }
}
