<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    /** Persist the acting user's customized op-dashboard layout for one mode (desktop|mobile). */
    public function saveLayout(Request $request): Response
    {
        // Manual validation → JSON 422 (this is a background axios PUT, not an Inertia visit, so a
        // redirect-on-error would be wrong).
        $v = Validator::make($request->all(), [
            'op_id' => ['required', 'string', 'max:16'],
            'mode' => ['required', Rule::in(['desktop', 'mobile'])],
            'layout' => ['present', 'array'],
            'layout.*.i' => ['required', 'string', 'max:32'],
            'layout.*.x' => ['required', 'integer', 'min:0'],
            'layout.*.y' => ['required', 'integer', 'min:0'],
            'layout.*.w' => ['required', 'integer', 'min:1', 'max:12'],
            'layout.*.h' => ['required', 'integer', 'min:1', 'max:40'],
            'layout.*.collapsed' => ['sometimes', 'boolean'],
            'layout.*.fullH' => ['sometimes', 'integer', 'min:1', 'max:40'],
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }
        $data = $v->validated();

        // Layouts are stored per-op (dashboard_layout['ops']['<id>']), so each op keeps its own arrangement.
        $user = $request->user();
        $layout = $user->dashboard_layout ?? [];
        if (! isset($layout['ops']) || ! is_array($layout['ops'])) {
            $layout['ops'] = [];
        }
        $layout['ops'][(string) $data['op_id']][$data['mode']] = $data['layout'];
        $user->update(['dashboard_layout' => $layout]);

        // 204 so the JS layout save is a quiet background request (no Inertia visit / progress bar).
        return response()->noContent();
    }

    /** Persist the acting user's manual op-card order for their dashboard (a list of op ids). */
    public function saveOrder(Request $request): Response
    {
        $v = Validator::make($request->all(), [
            'order' => ['present', 'array'],
            'order.*' => ['string', 'max:16'],
        ]);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first()], 422);
        }

        // Stored alongside the widget layouts in the same per-user prefs blob. Ids the user no longer
        // participates in are harmless — the dashboard just ignores unknown ids when it applies the order.
        $user = $request->user();
        $layout = $user->dashboard_layout ?? [];
        $layout['op_order'] = array_values($v->validated()['order']);
        $user->update(['dashboard_layout' => $layout]);

        return response()->noContent();
    }
}
