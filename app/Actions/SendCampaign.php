<?php

namespace App\Actions;

use App\Jobs\SendCampaignEmail;
use App\Models\MailCampaign;
use App\Models\User;
use App\Support\HtmlSanitizer;
use Illuminate\Support\Facades\URL;

/**
 * Resolve a broadcast's audience and queue a branded email to each recipient.
 * Recipients are explicit user ids (selected from the People list) or — when
 * none are given — every emailable, opted-in user. Opt-outs are always honored.
 *
 * Jobs are dispatched with a staggered delay (config mail.campaign_per_minute)
 * so we never burst the SMTP relay past its provider limit, even with hundreds
 * of recipients. The queue is drained serially by the scheduled queue:work.
 */
class SendCampaign
{
    /**
     * @param  array{subject:string,header:?string,body:string,signature:?string,format:string,recipients?:array<int,int>}  $data
     */
    public function handle(array $data, User $sender): MailCampaign
    {
        $query = User::query()->whereNotNull('email')->where('email_opt_out', false);
        if (! empty($data['recipients']) && is_array($data['recipients'])) {
            $query->whereKey($data['recipients']);
        }
        $recipients = $query->get(['id', 'email', 'callsign'])->values();

        $format = ($data['format'] ?? 'html') === 'text' ? 'text' : 'html';
        $campaign = MailCampaign::create([
            'created_by' => $sender->id,
            'subject' => $data['subject'],
            'header' => $data['header'] ?? null,
            // sanitise the owner-authored HTML before it's stored + emailed (the body is rendered raw via {!! !!})
            'body' => $format === 'html' ? HtmlSanitizer::clean($data['body']) : $data['body'],
            'signature' => $data['signature'] ?? null,
            'format' => $format,
            'recipient_count' => $recipients->count(),
            'sent_at' => now(),
        ]);

        $rate = max(1, (int) config('mail.campaign_per_minute', 30));
        foreach ($recipients as $i => $u) {
            $unsubscribe = URL::signedRoute('unsubscribe', ['user' => $u->id]);
            SendCampaignEmail::dispatch(
                (string) $u->email, $u->callsign ?: 'agent',
                $campaign->subject, $campaign->header, $campaign->body, $campaign->signature, $campaign->format, $unsubscribe,
            )->delay(now()->addSeconds(intdiv($i, $rate) * 60));
        }

        return $campaign;
    }
}
