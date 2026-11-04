<?php

namespace App\Jobs;

use App\Mail\CampaignMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/** Deliver one broadcast email. Retries with backoff so a flaky SMTP relay never gets hammered. */
class SendCampaignEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public string $email,
        public string $recipientName,
        public string $subject,
        public ?string $header,
        public string $body,
        public ?string $signature,
        public string $format,
        public ?string $unsubscribeUrl,
    ) {}

    public function handle(): void
    {
        Mail::to($this->email)->send(new CampaignMail(
            $this->subject, $this->header, $this->body, $this->signature, $this->format, $this->recipientName, $this->unsubscribeUrl,
        ));
    }
}
