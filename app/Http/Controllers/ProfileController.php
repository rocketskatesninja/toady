<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $u = $request->user();

        return Inertia::render('Profile', [
            'profile' => [
                'callsign' => $u->callsign,
                'email' => $u->email,
                'email_opt_out' => (bool) $u->email_opt_out,
                'faction' => $u->faction,
                'avatar' => $u->avatarUrl(),
                'is_owner' => (bool) $u->is_owner,
                'phone' => $u->phone,
                'telegram' => $u->telegram,
                'preferred_contact' => $u->preferred_contact,
                'emergency_contact' => $u->emergency_contact,
                'show_reference' => (bool) $u->show_reference,
                'notify_prefs' => $u->notify_prefs,
                // codename can only be changed when you're not in any op (it's referenced across them)
                'in_ops' => $u->participations()->exists(),
                // synced BYOK AI config (provider/key/model) when cross-device sync is on, else null
                'ai_config' => $u->ai_config,
            ],
        ]);
    }

    /** Change codename (e.g. after a Niantic rename) — blocked while the user is a member of any op. */
    public function updateCallsign(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->participations()->exists()) {
            return back()->withErrors(['callsign' => 'Leave or close all of your ops before changing your codename.']);
        }
        $data = $request->validate([
            'callsign' => ['required', 'string', 'regex:'.User::CALLSIGN_REGEX, function ($attr, $value, $fail) use ($user) {
                if (User::callsignTaken((string) $value, $user->id)) {
                    $fail('That codename is already taken.');
                }
            }],
        ], [
            'callsign.regex' => User::CALLSIGN_MESSAGE,
        ]);

        $user->callsign = $data['callsign'];
        $user->save();

        return back()->with('success', "Codename changed to {$data['callsign']}.");
    }

    public function update(Request $request): RedirectResponse
    {
        $request->user()->update($request->validate([
            'faction' => ['sometimes', Rule::in(['ENL', 'RES'])],
            'phone' => ['nullable', 'string', 'max:40'],
            'telegram' => ['nullable', 'string', 'max:64'],
            'preferred_contact' => ['nullable', 'string', 'max:64'],
            'emergency_contact' => ['nullable', 'string', 'max:120'],
            'show_reference' => ['sometimes', 'boolean'],
            'email_opt_out' => ['sometimes', 'boolean'],
            'notify_prefs' => ['sometimes', 'array'],
            'notify_prefs.*' => ['boolean'],
        ]));

        return back()->with('success', $request->has('notify_prefs') ? 'Notification settings updated.' : 'Profile updated.');
    }

    /** Opt-in: store the BYOK AI config encrypted on the account so it syncs across the user's devices. */
    public function saveAiConfig(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'provider' => ['required', Rule::in(['openai', 'anthropic'])],
            'key' => ['required', 'string', 'max:400'],
            'model' => ['nullable', 'string', 'max:120'],
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }

        $user = $request->user();
        $user->ai_config = $v->validated(); // not mass-assignable — set explicitly; encrypted by the cast
        $user->save();

        return response()->json(['ok' => true]);
    }

    /** Stop syncing — clear the stored AI config (the device's own localStorage copy is left untouched). */
    public function clearAiConfig(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->ai_config = null;
        $user->save();

        return response()->json(['ok' => true]);
    }

    /** Upload / replace the profile photo. Re-encoded to a 256px square (strips EXIF incl. GPS). */
    public function uploadAvatar(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120',
                'dimensions:max_width=8000,max_height=8000'], // guard against decompression bombs
        ]);

        $user = $request->user();
        $old = $user->avatar;
        $user->update(['avatar' => $this->processAvatar($request->file('avatar'))]);
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return back()->with('success', 'Photo updated.');
    }

    public function deleteAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->avatar) {
            Storage::disk('local')->delete($user->avatar);
            $user->update(['avatar' => null]);
        }

        return back()->with('success', 'Photo removed.');
    }

    /** Stream a user's avatar from the private disk (any signed-in agent may view it — it's a roster photo). */
    public function avatarFor(User $user): StreamedResponse
    {
        abort_unless($user->avatar && Storage::disk('local')->exists($user->avatar), 404);

        return Storage::disk('local')->response($user->avatar, null, ['Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Delete your account + everything tied to you (FK cascade). */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->avatar) {
            Storage::disk('local')->delete($user->avatar);
        }
        Auth::guard('web')->logout();
        $user->delete();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'Your account and data have been deleted.');
    }

    /**
     * Normalise an upload to a 256px square JPEG. Re-encoding via GD strips all metadata (notably EXIF
     * GPS) and caps the stored size; if GD is unavailable we fall back to the validated original.
     */
    private function processAvatar(UploadedFile $file): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return $file->store('avatars', 'local');
        }

        $size = 256;
        $path = $file->getRealPath();
        $info = getimagesize($path);
        abort_if($info === false, 422, 'Invalid image.');
        [$w, $h] = $info;

        $src = match ($info['mime']) {
            'image/png' => imagecreatefrompng($path),
            'image/webp' => imagecreatefromwebp($path),
            default => imagecreatefromjpeg($path),
        };
        abort_if(! $src, 422, 'Unreadable image.');

        $side = min($w, $h);
        $sx = (int) (($w - $side) / 2);
        $sy = (int) (($h - $side) / 2);
        $dst = imagecreatetruecolor($size, $size);
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $size, $size, $side, $side);

        ob_start();
        imagejpeg($dst, null, 82);
        $data = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        $newPath = 'avatars/'.Str::random(40).'.jpg';
        Storage::disk('local')->put($newPath, $data);

        return $newPath;
    }
}
