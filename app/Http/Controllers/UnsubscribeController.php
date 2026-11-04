<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UnsubscribeController extends Controller
{
    /** Public one-click unsubscribe — the signed URL proves the link came from us. */
    public function __invoke(Request $request, User $user): Response
    {
        $user->update(['email_opt_out' => true]);

        return response()->view('unsubscribed', ['email' => $user->email]);
    }
}
