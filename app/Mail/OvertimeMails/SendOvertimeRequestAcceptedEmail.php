<?php

namespace App\Mail\OvertimeMails;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SendOvertimeRequestAcceptedEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject("Demande d'heures supplémentaires acceptée")
            ->view('emails.overtimes.accepted-overtime-request');
    }
}
