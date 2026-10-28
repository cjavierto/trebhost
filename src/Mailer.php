<?php
declare(strict_types=1);

namespace App;

/** Plain-text email via PHP mail() (works on Namecheap shared hosting). */
final class Mailer
{
    public static function send(string $to, string $subject, string $body): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $s = settings();
        $from = Env::get('MAIL_FROM', $s['billing_email']);
        $name = preg_replace('/[\r\n"]/', '', $s['business_name']);
        $headers = [
            'From: "' . $name . '" <' . $from . '>',
            'Reply-To: ' . $s['billing_email'],
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: TrebHostPortal',
        ];
        $subject = '=?UTF-8?B?' . base64_encode(preg_replace('/[\r\n]/', ' ', $subject)) . '?=';
        if (Env::get('MAIL_DISABLED') === '1') {
            file_put_contents(APP_ROOT . '/storage/mail.log', "TO: $to\nSUBJECT: $subject\n$body\n---\n", FILE_APPEND);
            return true;
        }
        $ok = @mail($to, $subject, $body, implode("\r\n", $headers), '-f' . $from);
        if (!$ok) {
            error_log('Mail to ' . $to . ' failed');
        }
        return $ok;
    }
}
