<?php

namespace Tests\Feature;

use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_files_a_report_with_a_screenshot_and_the_owner_is_notified(): void
    {
        Storage::fake('local');
        Mail::fake();
        $owner = $this->mkUser(['callsign' => 'Owner', 'faction' => 'ENL']);
        $owner->forceFill(['is_owner' => true])->save();
        $user = $this->mkUser(['callsign' => 'Vec', 'faction' => 'ENL']);

        $this->actingAs($user)->post('/reports', [
            'message' => 'The map froze when I dropped a portal.',
            'reply_email' => 'vec@example.com',
            'url' => 'https://toady.net/ops/4',
            'screenshots' => [UploadedFile::fake()->image('bug.png')],
        ])->assertRedirect();

        $report = Report::first();
        $this->assertSame('vec@example.com', $report->reply_email);
        $this->assertSame($user->id, $report->user_id);
        $this->assertCount(1, $report->attachments);
        Storage::disk('local')->assertExists($report->attachments[0]);
        // the file lands under the private disk, never the public web root
        $this->assertStringStartsWith('reports/', $report->attachments[0]);
        // owner gets an in-app notification
        $this->assertDatabaseHas('notifications', ['user_id' => $owner->id, 'type' => 'report']);
    }

    public function test_report_requires_a_message(): void
    {
        $user = $this->mkUser(['callsign' => 'Vec2', 'faction' => 'ENL']);
        $this->actingAs($user)->post('/reports', ['message' => ''])->assertSessionHasErrors('message');
        $this->assertSame(0, Report::count());
    }

    public function test_a_non_image_screenshot_is_rejected(): void
    {
        $user = $this->mkUser(['callsign' => 'Vec4', 'faction' => 'ENL']);
        $this->actingAs($user)->post('/reports', [
            'message' => 'hi',
            'screenshots' => [UploadedFile::fake()->create('evil.php', 10, 'application/x-php')],
        ])->assertSessionHasErrors('screenshots.0');
    }

    public function test_only_admins_can_view_reports_and_attachments(): void
    {
        Storage::fake('local');
        Mail::fake();
        $admin = $this->mkUser(['callsign' => 'Boss', 'faction' => 'ENL']);
        $admin->forceFill(['is_admin' => true])->save();
        $user = $this->mkUser(['callsign' => 'Vec3', 'faction' => 'ENL']);
        $this->actingAs($user)->post('/reports', ['message' => 'x', 'screenshots' => [UploadedFile::fake()->image('s.png')]]);
        $report = Report::first();

        // a normal user can't see the report list or the screenshot
        $this->actingAs($user)->get('/admin/reports')->assertForbidden();
        $this->actingAs($user)->get("/admin/reports/{$report->id}/file/0")->assertForbidden();
        // the admin can
        $this->actingAs($admin)->get('/admin/reports')->assertOk();
        $this->actingAs($admin)->get("/admin/reports/{$report->id}/file/0")->assertOk();
    }
}
