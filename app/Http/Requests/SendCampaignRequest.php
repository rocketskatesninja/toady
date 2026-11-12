<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Compose + send a broadcast email. Owner-only — the authorize() check is the gate. */
class SendCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_owner;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:200'],
            'header' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:50000'],
            'signature' => ['nullable', 'string', 'max:2000'],
            'format' => ['required', Rule::in(['html', 'text'])],
            'recipients' => ['sometimes', 'array'],
            'recipients.*' => ['integer'],
        ];
    }
}
