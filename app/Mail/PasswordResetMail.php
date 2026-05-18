<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $resetUrl;
    public string $name;

    public function __construct(string $resetUrl, string $name)
    {
        $this->resetUrl = $resetUrl;
        $this->name     = $name;
    }

    public function build(): self
    {
        return $this
            ->subject('TierHome — Reset Your Password')
            ->html($this->getHtml());
    }

    private function getHtml(): string
    {
        return '
        <!DOCTYPE html>
        <html>
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="margin:0;padding:0;background:#f9fafb;font-family:Arial,sans-serif;">
          <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;padding:40px 20px;">
            <tr>
              <td align="center">
                <table width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">
                  
                  <tr>
                    <td style="background:#3d6b35;padding:32px 40px;text-align:center;">
                      <h1 style="color:#ffffff;margin:0;font-size:28px;font-weight:800;">TierHome</h1>
                      <p style="color:#a7d4a1;margin:6px 0 0;font-size:13px;">Angeles City · Verified Rentals</p>
                    </td>
                  </tr>

                  <tr>
                    <td style="padding:40px;">
                      <p style="color:#111827;font-size:16px;font-weight:600;margin:0 0 8px;">Hi ' . htmlspecialchars($this->name) . ',</p>
                      <p style="color:#6b7280;font-size:14px;line-height:1.6;margin:0 0 32px;">
                        We received a request to reset your TierHome password. Click the button below to set a new password.
                      </p>

                      <div style="text-align:center;margin-bottom:32px;">
                        <a href="' . $this->resetUrl . '" 
                           style="display:inline-block;background:#3d6b35;color:#ffffff;text-decoration:none;padding:14px 40px;border-radius:12px;font-size:15px;font-weight:700;">
                          Reset My Password
                        </a>
                      </div>

                      <p style="color:#9ca3af;font-size:12px;line-height:1.6;margin:0 0 8px;">
                        This link expires in <strong>60 minutes</strong>. If you did not request a password reset, you can safely ignore this email.
                      </p>
                      <p style="color:#9ca3af;font-size:11px;word-break:break-all;">
                        Or copy this link: ' . $this->resetUrl . '
                      </p>
                    </td>
                  </tr>

                  <tr>
                    <td style="background:#f9fafb;padding:20px 40px;border-top:1px solid #f3f4f6;">
                      <p style="color:#9ca3af;font-size:11px;margin:0;text-align:center;">
                        © 2026 TierHome. Angeles City, Pampanga, Philippines.
                      </p>
                    </td>
                  </tr>

                </table>
              </td>
            </tr>
          </table>
        </body>
        </html>
        ';
    }
}