<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    // Both saves are quiet background axios PUTs, not Inertia visits: success is a 204 (no progress bar), and a
    // bad payload is a JSON 422 (validJson).

    /** Persist the acting user's customized op-dashboard layout for one mode (desktop|mobile), per op — each op
     *  keeps its own arrangement under dashboard_layout['ops']['<public id>']. */
    public function saveLayout(Request $request): Response
    {
        $data = $this->validJson($request, [
            'op_id' => ['required', 'string', 'max:16'],
            'mode' => ['required', Rule::in(['desktop', 'mobile'])],
            'layout' => ['present', 'array'],
            'layout.*.i' => ['required', 'string', 'max:32'],
            'layout.*.x' => ['required', 'integer', 'min:0'],
            'layout.*.y' => ['required', 'integer', 'min:0'],
            'layout.*.w' => ['required', 'integer', 'min:1', 'max:12'],
            'layout.*.h' => ['required', 'integer', 'min:1', 'max:200'], // mobile plan auto-fits to content — a big op's expanded card can be tall
            'layout.*.collapsed' => ['sometimes', 'boolean'],
            'layout.*.fullH' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        if (! is_array($data)) {
            return $data;
        }

        return $this->savePref($request->user(), "ops.{$data['op_id']}.{$data['mode']}", $data['layout']);
    }

    /** Persist the acting user's manual op-card order for their dashboard (a list of op ids). */
    public function saveOrder(Request $request): Response
    {
        $data = $this->validJson($request, [
            'order' => ['present', 'array'],
            'order.*' => ['string', 'max:16'],
        ]);
        if (! is_array($data)) {
            return $data;
        }

        // Ids the user no longer participates in are harmless — the dashboard ignores unknown ids when it applies the order.
        return $this->savePref($request->user(), 'op_order', array_values($data['order']));
    }

    /** Set one entry in the user's dashboard prefs blob (widget layouts + card order live together). */
    private function savePref(User $user, string $path, array $value): Response
    {
        $prefs = $user->dashboard_layout ?? [];
        data_set($prefs, $path, $value);
        $user->update(['dashboard_layout' => $prefs]);

        return response()->noContent();
    }
}
