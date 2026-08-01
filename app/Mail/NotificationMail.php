<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $notifTitle;
    public $notifMessage;
    public $notifLink;
    public $recipientName;

    public function __construct($notifTitle, $notifMessage, $notifLink, $recipientName)
    {
        $this->notifTitle    = $notifTitle;
        $this->notifMessage  = $notifMessage;
        $this->notifLink     = $notifLink;
        $this->recipientName = $recipientName;
    }

    public function build()
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
        $fullLink = $this->notifLink ? rtrim($frontendUrl, '/') . $this->notifLink : $frontendUrl;

        return $this->subject($this->notifTitle . ' — TierHome')
            ->view('emails.notification')
            ->with([
                'notifTitle'    => $this->notifTitle,
                'notifMessage'  => $this->notifMessage,
                'fullLink'      => $fullLink,
                'recipientName' => $this->recipientName,
            ]);
    }
}
