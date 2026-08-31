<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /** Any signed-in user files a report (message + up to 4 image screenshots). */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'reply_email' => ['nullable', 'email', 'max:255'],
            'url' => ['nullable', 'string', 'max:1000'],
            'screenshots' => ['nullable', 'array', 'max:4'],
            'screenshots.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'], // 5 MB each
        ]);

        // store images on the PRIVATE disk (not web-reachable); Laravel hashes the filenames
        $paths = [];
        foreach ((array) $request->file('screenshots', []) as $file) {
            $paths[] = $file->store('reports', 'local');
        }

        $report = Report::create([
            'user_id' => $request->user()->id,
            'reply_email' => $data['reply_email'] ?? null,
            'message' => $data['message'],
            'url' => $data['url'] ?? null,
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'attachments' => $paths,
        ]);

        $this->notifyOwners($request, $report, count($paths));

        return back()->with('success', 'Thanks — your report was sent to the team.');
    }

    /** In-app bell notification to every owner + a best-effort email. */
    private function notifyOwners(Request $request, Report $report, int $shots): void
    {
        $who = $request->user()->callsign ?? 'a user';
        User::where('is_owner', true)->get()->each(fn (User $o) => Notifier::send(
            $o, 'report', 'New problem report', 'From '.$who.': '.Str::limit($report->message, 80), '/admin/reports', tag: 'report'
        ));

        try {
            if ($to = config('services.toady.owner_email')) {
                $reply = $report->reply_email ?: null;
                $body = "Problem report from {$who}".($reply ? " (reply to {$reply})" : '')."\n"
                    ."Page: ".($report->url ?: '—')."\n"
                    .'Screenshots: '.$shots."\n\n"
                    .$report->message."\n\n"
                    .'View: '.url('/admin/reports');
                Mail::raw($body, function ($m) use ($to, $reply) {
                    $m->to($to)->subject('toady — problem report');
                    if ($reply) {
                        $m->replyTo($reply);
                    }
                });
            }
        } catch (\Throwable $e) {
            report($e); // never let a mail failure break the report
        }
    }

    /** Owner/admin: list of reports. */
    public function index(): Response
    {
        $reports = Report::with('user:id,callsign,faction')->latest()->paginate(20)
            ->through(fn (Report $r) => [
                'id' => $r->id,
                'message' => $r->message,
                'reply_email' => $r->reply_email,
                'url' => $r->url,
                'user_agent' => $r->user_agent,
                'callsign' => $r->user?->callsign,
                'faction' => $r->user?->faction,
                'shots' => count($r->attachments ?? []),
                'resolved' => $r->resolved_at !== null,
                'at' => $r->created_at->toIso8601String(),
            ]);

        return Inertia::render('Admin/Reports', ['reports' => $reports]);
    }

    /** Owner/admin: stream a screenshot from the private disk (never publicly served). */
    public function attachment(Report $report, int $index): StreamedResponse
    {
        $path = ($report->attachments ?? [])[$index] ?? null;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function resolve(Report $report): RedirectResponse
    {
        $report->forceFill(['resolved_at' => $report->resolved_at ? null : now()])->save();

        return back();
    }

    public function destroy(Report $report): RedirectResponse
    {
        foreach ((array) $report->attachments as $p) {
            Storage::disk('local')->delete($p);
        }
        $report->delete();

        return back()->with('success', 'Report deleted.');
    }
}
