<?php

namespace App\Mail;

use App\Models\VerificationCode;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $code;
    public string $type;

    public function __construct(string $code, string $type)
    {
        $this->code = $code;
        $this->type = $type;
    }

    public function envelope(): Envelope
    {
        $subject = $this->type === VerificationCode::TYPE_RESET_PASSWORD
            ? 'Your password reset code'
            : 'Verify your email address';
        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.verification_code');
    }
}
