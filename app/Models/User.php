<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// is_owner / is_admin / suspended_at are NOT mass-assignable — they're privilege flags, set explicitly.
#[Fillable([
    'google_id', 'email', 'email_verified_at', 'password', 'callsign', 'faction', 'avatar',
    'phone', 'telegram', 'preferred_contact', 'emergency_contact', 'show_reference', 'dashboard_layout', 'notify_prefs', 'email_opt_out', 'mail_header', 'mail_signature',
])]
#[Hidden(['password', 'remember_token', 'ai_config'])]
class User extends Authenticatable implements MustVerifyEmail
{
    use Notifiable;

    // One canonical callsign rule for onboarding + profile rename, so a name you can create is one you can
    // also rename to. 3–15 letters/digits, no spaces or symbols.
    public const CALLSIGN_REGEX = '/^[A-Za-z0-9]{3,15}$/';
    public const CALLSIGN_MESSAGE = 'Codenames are 3–15 letters and numbers — no spaces or symbols.';

    /** Case-insensitive "is this codename already taken?" — matches Ingress (callsigns are unique
     *  regardless of case) and the NOCASE unique index. Pass $exceptId to ignore the user's own row. */
    public static function callsignTaken(string $callsign, ?int $exceptId = null): bool
    {
        return static::whereRaw('lower(callsign) = ?', [mb_strtolower(trim($callsign))])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }

    protected function casts(): array
    {
        return [
            'is_owner' => 'boolean',
            'is_admin' => 'boolean',
            'trusted' => 'boolean', // owner-granted; a trusted contributor's single catalog submission auto-verifies
            'suspended_at' => 'datetime',
            'show_reference' => 'boolean',
            'dashboard_layout' => 'array',
            'notify_prefs' => 'array',
            'ai_config' => 'encrypted:array', // BYOK AI config, encrypted at rest; never mass-assigned or serialized
            'email_opt_out' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** May this user contribute portal names to the shared catalog? Verified email + onboarded
     *  (has a callsign) + not suspended. The per-hour flood cap is enforced in CatalogContributor. */
    public function canContributeCatalog(): bool
    {
        return $this->email_verified_at !== null
            && $this->callsign !== null
            && $this->suspended_at === null;
    }

    /** Streaming URL for the profile photo (served from the private disk), with a cache-busting stamp. */
    public function avatarUrl(): ?string
    {
        return $this->avatar ? route('avatar', $this).'?v='.($this->updated_at?->timestamp ?? 0) : null;
    }

    /** Ops this user created (they're the op owner). */
    public function ownedOps(): HasMany
    {
        return $this->hasMany(Op::class, 'owner_id');
    }

    public function participations(): HasMany
    {
        return $this->hasMany(OpParticipant::class);
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /** Reusable directive templates this operator has saved — available across every op they run. */
    public function stepTemplates(): HasMany
    {
        return $this->hasMany(OpStepTemplate::class)->latest();
    }
}
