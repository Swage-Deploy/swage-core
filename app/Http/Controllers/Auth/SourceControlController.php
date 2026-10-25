<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\SourceControl\SourceControlException;
use App\SourceControl\SourceControlProvider;
use App\SourceControl\SourceControlProviders;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class SourceControlController extends Controller
{
    public function __construct(protected SourceControlProviders $providers) {}

    /**
     * Send the user to a provider to authorize access to their repositories.
     */
    public function redirect(string $provider): SymfonyRedirectResponse
    {
        $provider = $this->providers->find($provider) ?? abort(404);

        abort_unless($provider->isConfigured(), 404);

        return $provider->redirect();
    }

    /**
     * Store the account the user connected, replacing any account they connected to the provider before.
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $provider = $this->providers->find($provider) ?? abort(404);

        try {
            $account = $provider->connectedAccount($request);
        } catch (SourceControlException $exception) {
            report($exception->getPrevious() ?? $exception);

            return to_route('source-control.edit')->with('source-control-error', $this->explain($request, $provider, $exception));
        }

        $request->user()->sourceControlConnections()->updateOrCreate(['provider' => $provider->id()], [
            'account_id' => $account->id,
            'username' => $account->username,
            'token' => $account->token,
            'refresh_token' => $account->refreshToken,
            'expires_at' => $account->expiresAt,
        ]);

        return to_route('source-control.edit')->with('source-control-connected', $provider->name());
    }

    /**
     * Say why connecting failed. Providers send OAuth errors back as error and error_description, and Socialite fails
     * the state check when the reply reaches a session other than the one that started, such as on another address.
     */
    protected function explain(Request $request, SourceControlProvider $provider, SourceControlException $exception): string
    {
        $name = $provider->name();
        $callbackUrl = route('source-control.callback', $provider->id());

        if ($request->filled('error')) {
            return match ($request->string('error')->toString()) {
                'access_denied' => __('The connection was cancelled on :provider.', ['provider' => $name]),
                'redirect_uri_mismatch' => __(':provider does not accept this callback URL. Set the OAuth app\'s callback URL to :url.', ['provider' => $name, 'url' => $callbackUrl]),
                default => __(':provider refused the connection: :reason', ['provider' => $name, 'reason' => $request->string('error_description')->toString() ?: $request->string('error')->toString()]),
            };
        }

        if ($exception->getPrevious() instanceof InvalidStateException) {
            return __(':provider sent you back to :url, but your sign-in was not found there. Open Swage at that address, the one in APP_URL, and connect again.', ['provider' => $name, 'url' => url('/')]);
        }

        if ($exception->getPrevious() instanceof RequestException && in_array($exception->getPrevious()->getResponse()?->getStatusCode(), [400, 401], true)) {
            return __(':provider did not accept Swage\'s client ID and secret. Check them on the provider\'s configuration page against the OAuth app.', ['provider' => $name]);
        }

        return $exception->getMessage().($exception->getPrevious() ? ' ('.$exception->getPrevious()->getMessage().')' : '');
    }
}
