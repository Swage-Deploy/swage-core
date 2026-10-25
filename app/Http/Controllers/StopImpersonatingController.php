<?php

namespace App\Http\Controllers;

use App\Actions\Users\Impersonation;
use Illuminate\Http\RedirectResponse;

class StopImpersonatingController extends Controller
{
    /**
     * Sign the administrator back in as themselves and return them to the users page.
     */
    public function __invoke(Impersonation $impersonation): RedirectResponse
    {
        abort_unless($impersonation->isImpersonating(), 404);

        return $impersonation->stop() ? to_route('users') : redirect('/');
    }
}
