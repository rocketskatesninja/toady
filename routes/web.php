<?php

use App\Http\Controllers\Admin\CycleController as AdminCycleController;
use App\Http\Controllers\Admin\ShowcaseController as AdminShowcaseController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DirectMessageController;
use App\Http\Controllers\MapTileController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardController;
use App\Http\Controllers\OpController;
use App\Http\Controllers\OpKeyController;
use App\Http\Controllers\OpPlanController;
use App\Http\Controllers\OpStepController;
use App\Http\Controllers\OpStepTemplateController;
use App\Http\Controllers\OpUndoController;
use App\Http\Controllers\OpWaypointController;
use App\Http\Controllers\ParticipantController;
use App\Http\Controllers\PresenceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\ShowcaseController;
use App\Http\Controllers\UnsubscribeController;
use App\Http\Controllers\WeatherController;
use App\Http\Middleware\CapturesOpUndo;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureOnboarded;
use App\Support\Mechanics;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => auth()->check()
    ? redirect()->route('dashboard')
    : Inertia::render('Welcome'))->name('home');

// public one-click unsubscribe from broadcast emails (signed link, no login)
Route::get('/unsubscribe/{user}', UnsubscribeController::class)->middleware('signed')->name('unsubscribe');

Route::get('/privacy', fn () => Inertia::render('Privacy'))->name('privacy');
Route::get('/terms', fn () => Inertia::render('Terms'))->name('terms');
Route::get('/guide', fn () => Inertia::render('Guide'))->name('guide');
Route::get('/donate', fn () => Inertia::render('Donate'))->name('donate');
Route::get('/beta', fn () => Inertia::render('Beta'))->name('beta');

// public showcase gallery of ops built with toady (curated via the admin panel)
Route::get('/showcase', [ShowcaseController::class, 'index'])->name('showcase');
Route::get('/showcase/{showcase}/img/{index}', [ShowcaseController::class, 'image'])->whereNumber('index')->name('showcase.image');

// ---- Google OAuth ----
Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');

// ---- Email + password (guests) ----
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:10,1');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:10,1')->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:10,1')->name('password.store');
});

// ---- Join link (public; guests are sent to sign in, then bounced back to join) ----
Route::get('/j/{token}', [OpController::class, 'join'])->middleware('throttle:10,1')->name('ops.join');

// ---- Authenticated ----
Route::middleware('auth')->group(function () {
    // email verification — reachable while unverified
    Route::get('/verify-email', [VerifyEmailController::class, 'notice'])->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [VerifyEmailController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [VerifyEmailController::class, 'resend'])->middleware('throttle:6,1')->name('verification.send');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

    // live-traffic map tiles (TomTom key proxied server-side) — verified users only (the map is post-onboarding)
    Route::get('/map/traffic/{z}/{x}/{y}', [MapTileController::class, 'traffic'])
        ->middleware('verified')
        ->where(['z' => '[0-9]+', 'x' => '[0-9]+', 'y' => '[0-9]+'])->name('map.traffic');

    // verified
    Route::middleware('verified')->group(function () {
        Route::get('/onboard', [OnboardController::class, 'show'])->name('onboard');
        Route::post('/onboard', [OnboardController::class, 'store'])->name('onboard.store');

        // report a problem — any signed-in user; rate-limited
        Route::post('/reports', [ReportController::class, 'store'])->middleware('throttle:5,1')->name('reports.store');

        // verified + onboarded (callsign chosen)
        Route::middleware(EnsureOnboarded::class)->group(function () {
            Route::get('/dashboard', [OpController::class, 'dashboard'])->name('dashboard');
            Route::put('/dashboard/layout', [DashboardController::class, 'saveLayout'])->name('dashboard.layout');
            Route::put('/dashboard/order', [DashboardController::class, 'saveOrder'])->name('dashboard.order');

            // notification feed (light JSON for the bell badge / op widget)
            Route::get('/notifications/feed', [NotificationController::class, 'feed'])->name('notifications.feed');
            Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
            Route::post('/notifications/clear', [NotificationController::class, 'clear'])->name('notifications.clear'); // delete (op-scoped)
            Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
            Route::post('/ops', [OpController::class, 'store'])->name('ops.store');
            Route::post('/ops/import', [OpController::class, 'import'])->middleware('throttle:20,1')->name('ops.import');
            Route::get('/ops/{op}', [OpController::class, 'show'])->name('ops.show');
            Route::get('/ops/{op}/export', [OpController::class, 'export'])->name('ops.export');
            Route::put('/ops/{op}', [OpController::class, 'update'])->name('ops.update');
            Route::delete('/ops/{op}', [OpController::class, 'close'])->name('ops.close');

            // roster: invite by callsign · kick · ban
            Route::get('/api/agents/search', [ParticipantController::class, 'search'])->middleware('throttle:30,1')->name('agents.search');
            Route::post('/ops/{op}/participants', [ParticipantController::class, 'store'])->name('ops.participants.store');
            Route::put('/ops/{op}/notes', [OpController::class, 'saveNotes'])->middleware('throttle:120,1')->name('ops.notes');
            Route::delete('/ops/{op}/participants/{user}', [ParticipantController::class, 'destroy'])->name('ops.participants.destroy');
            Route::post('/ops/{op}/participants/{user}/ban', [ParticipantController::class, 'ban'])->name('ops.participants.ban');
            Route::delete('/ops/{op}/participants/{user}/ban', [ParticipantController::class, 'unban'])->name('ops.participants.unban');
            Route::post('/ops/{op}/participants/{user}/promote', [ParticipantController::class, 'promote'])->name('ops.participants.promote');
            Route::post('/ops/{op}/participants/{user}/demote', [ParticipantController::class, 'demote'])->name('ops.participants.demote');
            Route::put('/ops/{op}/participants/{user}/color', [ParticipantController::class, 'setColor'])->name('ops.participants.color');
            Route::delete('/ops/{op}/leave', [ParticipantController::class, 'leave'])->name('ops.leave'); // self-leave (non-owner participant)

            // Waypoints — CapturesOpUndo pushes a pre-edit snapshot onto the op's undo stack (planning only)
            Route::post('/ops/{op}/waypoints', [OpWaypointController::class, 'store'])->middleware(CapturesOpUndo::class)->name('ops.waypoints.store');
            Route::put('/ops/{op}/waypoints/{waypoint}', [OpWaypointController::class, 'update'])->middleware(CapturesOpUndo::class)->name('ops.waypoints.update');
            Route::put('/ops/{op}/waypoints/{waypoint}/intel', [OpWaypointController::class, 'intel'])->middleware(CapturesOpUndo::class)->name('ops.waypoints.intel');
            Route::post('/ops/{op}/waypoints/reorder', [OpWaypointController::class, 'reorder'])->middleware(CapturesOpUndo::class)->name('ops.waypoints.reorder');
            Route::post('/ops/{op}/waypoints/clear', [OpWaypointController::class, 'clearAll'])->middleware(CapturesOpUndo::class)->name('ops.waypoints.clear'); // delete every portal + its directives
            Route::delete('/ops/{op}/waypoints/{waypoint}', [OpWaypointController::class, 'destroy'])->middleware(CapturesOpUndo::class)->name('ops.waypoints.destroy');
            Route::post('/ops/{op}/waypoints/{waypoint}/flag', [OpWaypointController::class, 'flag'])->middleware('throttle:30,1')->name('ops.waypoints.flag');

            // key locker — agents report holdings (any status, NOT undoable); operative sets the plan's need (undoable)
            Route::put('/ops/{op}/keys/{waypoint}', [OpKeyController::class, 'update'])->name('ops.keys.update');
            Route::put('/ops/{op}/keys/{waypoint}/needed', [OpKeyController::class, 'setNeeded'])->middleware(CapturesOpUndo::class)->name('ops.keys.needed');
            // auto-fan: one action, mode=links|keys|both → fan link directives and/or per-location farm-keys
            Route::post('/ops/{op}/plan/fan', [OpStepController::class, 'autoFan'])->middleware(CapturesOpUndo::class)->name('ops.steps.autofan');

            // undo: pop the newest plan snapshot (operative + planning; deliberately NOT wrapped in CapturesOpUndo)
            Route::post('/ops/{op}/undo', [OpUndoController::class, 'undo'])->name('ops.undo');

            // import an IITC field plan (Draw Tools / Bookmarks) → waypoints + link directives + key needs
            Route::post('/ops/{op}/plan/import', [OpPlanController::class, 'import'])->middleware(['throttle:20,1', CapturesOpUndo::class])->name('ops.plan.import');

            // super-admin: platform user management
            Route::middleware(EnsureAdmin::class)->prefix('admin')->name('admin.')->group(function () {
                Route::get('/users', [AdminUserController::class, 'index'])->name('users');
                Route::post('/users/bulk', [AdminUserController::class, 'bulk'])->name('users.bulk');
                Route::post('/users/email', [AdminUserController::class, 'email'])->middleware('throttle:10,1')->name('users.email');

                // problem reports
                Route::get('/reports', [ReportController::class, 'index'])->name('reports');
                Route::get('/reports/{report}/file/{index}', [ReportController::class, 'attachment'])->whereNumber('index')->name('reports.file');
                Route::put('/reports/{report}/resolve', [ReportController::class, 'resolve'])->name('reports.resolve');
                Route::delete('/reports/{report}', [ReportController::class, 'destroy'])->name('reports.destroy');

                // global Ingress cycle-timing anchor (no game data fetched — clock math off a set anchor)
                Route::get('/cycle', [AdminCycleController::class, 'index'])->name('cycle');
                Route::put('/cycle', [AdminCycleController::class, 'update'])->name('cycle.update');
                Route::put('/cycle/mu', [AdminCycleController::class, 'updateMu'])->name('cycle.mu.update');

                // showcase gallery — curate the public "ops built with toady" page
                Route::get('/showcase', [AdminShowcaseController::class, 'index'])->name('showcase');
                Route::post('/showcase', [AdminShowcaseController::class, 'store'])->name('showcase.store');
                Route::post('/showcase/{showcase}', [AdminShowcaseController::class, 'update'])->name('showcase.update');
                Route::delete('/showcase/{showcase}', [AdminShowcaseController::class, 'destroy'])->name('showcase.destroy');
                Route::put('/showcase/enabled', [AdminShowcaseController::class, 'updateEnabled'])->name('showcase.enabled');
            });

            // Steps — structural directive edits are undoable; the per-agent done/undone toggle is not
            Route::post('/ops/{op}/steps', [OpStepController::class, 'store'])->middleware(CapturesOpUndo::class)->name('ops.steps.store');
            Route::post('/ops/{op}/steps/bulk', [OpStepController::class, 'bulk'])->middleware(CapturesOpUndo::class)->name('ops.steps.bulk'); // one action → every portal
            Route::put('/ops/{op}/steps/{step}', [OpStepController::class, 'update'])->middleware(CapturesOpUndo::class)->name('ops.steps.update');
            Route::put('/ops/{op}/steps/{step}/toggle', [OpStepController::class, 'toggle'])->name('ops.steps.toggle');
            Route::post('/ops/{op}/steps/reorder', [OpStepController::class, 'reorder'])->middleware(CapturesOpUndo::class)->name('ops.steps.reorder');
            Route::delete('/ops/{op}/steps/{step}', [OpStepController::class, 'destroy'])->middleware(CapturesOpUndo::class)->name('ops.steps.destroy');
            Route::post('/ops/{op}/steps/clear', [OpStepController::class, 'clearAll'])->middleware(CapturesOpUndo::class)->name('ops.steps.clear');

            // Step templates (save a location's directives, reuse them on another)
            Route::post('/ops/{op}/step-templates', [OpStepTemplateController::class, 'store'])->name('ops.step-templates.store');
            Route::post('/ops/{op}/step-templates/{template}/apply', [OpStepTemplateController::class, 'apply'])->middleware(CapturesOpUndo::class)->name('ops.step-templates.apply');
            Route::delete('/ops/{op}/step-templates/{template}', [OpStepTemplateController::class, 'destroy'])->name('ops.step-templates.destroy');

            // Live
            Route::post('/ops/{op}/presence', [PresenceController::class, 'update'])->name('ops.presence');
            Route::get('/ops/{op}/weather', [WeatherController::class, 'show'])->middleware('throttle:30,1')->name('ops.weather');
            Route::post('/ops/{op}/route', [RouteController::class, 'show'])->middleware('throttle:60,1')->name('ops.route');
            Route::get('/ops/{op}/chat', [ChatController::class, 'index'])->name('ops.chat.index');
            Route::post('/ops/{op}/chat', [ChatController::class, 'store'])->middleware('throttle:30,1')->name('ops.chat.store');
            Route::delete('/ops/{op}/chat/{message}', [ChatController::class, 'destroy'])->name('ops.chat.destroy');

            // BYOK AI concierge — proxy to OpenAI/Anthropic (key per-request, never stored; host-pinned)
            Route::post('/ops/{op}/ai/models', [AiController::class, 'models'])->middleware('throttle:30,1')->name('ops.ai.models');
            Route::post('/ops/{op}/ai', [AiController::class, 'chat'])->middleware('throttle:30,1')->name('ops.ai');
            Route::get('/ops/{op}/dm/{user}', [DirectMessageController::class, 'index'])->name('ops.dm.index');
            Route::post('/ops/{op}/dm/{user}', [DirectMessageController::class, 'store'])->middleware('throttle:30,1')->name('ops.dm.store');

            // Master catalog
            Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog');
            Route::post('/catalog/portals', [CatalogController::class, 'store'])->name('catalog.store');
            Route::put('/catalog/portals/{portal}', [CatalogController::class, 'update'])->name('catalog.update');
            Route::delete('/catalog/portals/{portal}', [CatalogController::class, 'destroy'])->name('catalog.destroy');
            Route::post('/catalog/portals/{portal}/lock', [CatalogController::class, 'lock'])->name('catalog.lock');
            Route::post('/catalog/portals/{portal}/restore', [CatalogController::class, 'restore'])->name('catalog.restore');
            Route::get('/api/catalog/search', [CatalogController::class, 'search'])->middleware('throttle:60,1')->name('catalog.search');
            Route::get('/api/catalog/in-view', [CatalogController::class, 'inView'])->middleware('throttle:120,1')->name('catalog.in-view'); // map overlay: verified portals in the viewport

            // Web Push
            Route::post('/push/subscribe', [PushController::class, 'subscribe'])->name('push.subscribe');
            Route::post('/push/unsubscribe', [PushController::class, 'unsubscribe'])->name('push.unsubscribe');

            // Profile
            Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
            Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
            Route::put('/profile/callsign', [ProfileController::class, 'updateCallsign'])->name('profile.callsign');
            Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
            Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar'])->name('profile.avatar.store');
            Route::delete('/profile/avatar', [ProfileController::class, 'deleteAvatar'])->name('profile.avatar.destroy');
            // opt-in cross-device sync of the BYOK AI config (encrypted at rest)
            Route::put('/profile/ai-config', [ProfileController::class, 'saveAiConfig'])->name('profile.ai.save');
            Route::delete('/profile/ai-config', [ProfileController::class, 'clearAiConfig'])->name('profile.ai.clear');
            // list a provider's models from the profile page (no op context — auth only)
            Route::post('/ai/models', [AiController::class, 'userModels'])->middleware('throttle:30,1')->name('ai.models');
            Route::get('/users/{user}/avatar', [ProfileController::class, 'avatarFor'])->name('avatar');

            // Mechanics reference (optional per user)
            Route::get('/reference', function () {
                $levels = array_map(fn ($l) => ['level' => $l, 'km' => round(Mechanics::linkRangeMeters($l) / 1000, 1)], [1, 2, 3, 4, 5, 6, 7, 8]);
                $resonators = array_map(fn ($lvl, $cnt) => ['level' => $lvl, 'count' => $cnt], array_keys(Mechanics::RESONATOR_LIMITS), Mechanics::RESONATOR_LIMITS);
                $agentLevels = array_map(fn ($l) => ['level' => $l, 'ap' => Mechanics::LEVEL_AP[$l], 'badges' => Mechanics::LEVEL_BADGES[$l] ?? null], range(1, 16));

                return Inertia::render('Reference', [
                    'linkLevels' => $levels,
                    'soloMaxKm' => round(Mechanics::soloMaxLinkMeters() / 1000, 1),
                    'resonators' => $resonators,
                    'ap' => Mechanics::AP,
                    'agentLevels' => $agentLevels,
                    'mechanics' => [
                        'hacks_before_burnout' => Mechanics::HACKS_BEFORE_BURNOUT,
                        'burnout_hours' => Mechanics::BURNOUT_HOURS,
                        'cooldown_own_min' => Mechanics::COOLDOWN_OWN_MIN,
                        'cooldown_enemy_min' => Mechanics::COOLDOWN_ENEMY_MIN,
                        'key_drop_pct' => (int) (Mechanics::KEY_DROP_RATE * 100),
                        'heat_sink' => Mechanics::HEAT_SINK,
                        'multi_hack' => Mechanics::MULTI_HACK,
                        'shield' => Mechanics::SHIELD_MITIGATION,
                        'deploy_range_m' => Mechanics::DEPLOY_RANGE_M,
                        'portal_slots' => Mechanics::PORTAL_SLOTS,
                        'mod_slots' => Mechanics::MOD_SLOTS,
                        'mods_per_agent' => Mechanics::MODS_PER_AGENT,
                    ],
                ]);
            })->name('reference');
        });
    });
});
