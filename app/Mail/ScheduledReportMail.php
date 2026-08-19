<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ScheduledReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public $recipientName;
    public $periodLabel;
    public $stats;

    public function __construct($recipientName, $periodLabel, $stats)
    {
        $this->recipientName = $recipientName;
        $this->periodLabel   = $periodLabel;
        $this->stats         = $stats;
    }

    public function build()
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');

        return $this->subject("Your {$this->periodLabel} TierHome Report")
            ->view('emails.scheduled-report')
            ->with([
                'recipientName' => $this->recipientName,
                'periodLabel'   => $this->periodLabel,
                'stats'         => $this->stats,
                'dashboardUrl'  => rtrim($frontendUrl, '/') . '/landlord/analytics',
                'reportsUrl'    => rtrim($frontendUrl, '/') . '/landlord/reports',
            ]);
    }
}