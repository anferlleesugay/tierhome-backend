<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $otp;
    public string $name;

    public function __construct(string $otp, string $name)
    {
        $this->otp  = $otp;
        $this->name = $name;
    }

    public function build(): self
    {
        return $this
            ->subject('TierHome — Your Verification Code')
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
                  
                  <!-- HEADER -->
                  <tr>
                    <td style="background:#3d6b35;padding:32px 40px;text-align:center;">
                      <h1 style="color:#ffffff;margin:0;font-size:28px;font-weight:800;letter-spacing:-0.5px;">TierHome</h1>
                      <p style="color:#a7d4a1;margin:6px 0 0;font-size:13px;">Angeles City · Verified Rentals</p>
                    </td>
                  </tr>

                  <!-- BODY -->
                  <tr>
                    <td style="padding:40px;">
                      <p style="color:#111827;font-size:16px;font-weight:600;margin:0 0 8px;">Hi ' . htmlspecialchars($this->name) . ',</p>
                      <p style="color:#6b7280;font-size:14px;line-height:1.6;margin:0 0 32px;">
                        Thank you for registering with TierHome! Use the verification code below to activate your account.
                      </p>

                      <!-- OTP BOX -->
                      <div style="background:#f0fdf4;border:2px dashed #3d6b35;border-radius:12px;padding:24px;text-align:center;margin-bottom:32px;">
                        <p style="color:#6b7280;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:1px;margin:0 0 12px;">Your Verification Code</p>
                        <p style="color:#3d6b35;font-size:48px;font-weight:800;letter-spacing:12px;margin:0;">' . $this->otp . '</p>
                        <p style="color:#9ca3af;font-size:12px;margin:12px 0 0;">Expires in <strong>10 minutes</strong></p>
                      </div>

                      <p style="color:#9ca3af;font-size:12px;line-height:1.6;margin:0;">
                        If you did not create a TierHome account, you can safely ignore this email.
                      </p>
                    </td>
                  </tr>

                  <!-- FOOTER -->
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