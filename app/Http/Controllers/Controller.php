<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

abstract class Controller
{
    /** Validate a background (axios) JSON request: the validated payload, or a JSON 422 carrying the first error.
     *  Done by hand because this app only renders JSON errors for api/* routes — $request->validate() would
     *  answer an axios call with a redirect. */
    protected function validJson(Request $request, array $rules): array|JsonResponse
    {
        $v = Validator::make($request->all(), $rules);

        return $v->fails() ? response()->json(['error' => $v->errors()->first()], 422) : $v->validated();
    }

    /** Continue an op join that was waiting on sign-in / onboarding (OpController::join stashes the token),
     *  or null when there isn't one. */
    protected function pendingJoin(Request $request): ?RedirectResponse
    {
        $token = $request->session()->pull('join_token');

        return $token ? redirect()->route('ops.join', $token) : null;
    }
}
