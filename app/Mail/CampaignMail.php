<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * One branded broadcast email. format = 'html' sends a multipart (rich HTML +
 * plain-text alternative); 'text' sends plain text only. The rich body comes
 * from the compose editor as HTML; the text part is derived from it.
 */
class CampaignMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public ?string $header,
        public string $bodyHtml,
        public ?string $signature,
        public string $format,        // 'html' | 'text'
        public string $recipientName,
        public ?string $unsubscribeUrl,
    ) {}

    public function build(): self
    {
        $this->subject($this->subjectLine);
        $data = [
            'header' => $this->header,
            'bodyHtml' => $this->bodyHtml,
            'bodyText' => $this->toText($this->bodyHtml),
            'signature' => $this->signature,
            'recipientName' => $this->recipientName,
            'unsubscribeUrl' => $this->unsubscribeUrl,
        ];

        if ($this->format === 'text') {
            return $this->text('emails.campaign-text', $data);
        }

        // rich: HTML with a plain-text alternative part
        return $this->view('emails.campaign', $data)->text('emails.campaign-text', $data);
    }

    /** Flatten the rich HTML body to readable plain text. */
    private function toText(string $html): string
    {
        $t = preg_replace('/<(br|\/p|\/div|\/li)\b[^>]*>/i', "\n", $html);
        $t = preg_replace('/<li\b[^>]*>/i', '• ', (string) $t);
        $t = html_entity_decode(strip_tags((string) $t), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace("/\n{3,}/", "\n\n", (string) $t));
    }
}
