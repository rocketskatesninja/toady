<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['created_by', 'subject', 'header', 'body', 'signature', 'format', 'recipient_count', 'sent_at'])]
class MailCampaign extends Model
{
    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
