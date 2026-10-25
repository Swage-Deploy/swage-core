<?php

namespace Tests\Fakes;

use App\Models\SourceControlConnection;
use App\SourceControl\ConnectedAccount;
use App\SourceControl\InvalidWebhookException;
use App\SourceControl\Push;
use App\SourceControl\SourceControlClient;
use App\SourceControl\SourceControlProvider;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * A provider that is not GitHub, standing in for a plugin's. Its client records every call it receives.
 */
class FakeSourceControlProvider implements SourceControlProvider
{
    public FakeSourceControlClient $client;

    public function __construct(public string $providerId = 'provision-git', public string $providerName = 'Provision Git')
    {
        $this->client = new FakeSourceControlClient;
    }

    public function id(): string
    {
        return $this->providerId;
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function setupInstructions(): string
    {
        return 'Nothing to set up.';
    }

    public function redirect(): RedirectResponse
    {
        return new RedirectResponse('https://git.example.com/oauth/authorize');
    }

    public function connectedAccount(Request $request): ConnectedAccount
    {
        return new ConnectedAccount(id: '42', username: 'jane', token: 'fake-token', refreshToken: 'fake-refresh', expiresAt: now()->addHour());
    }

    public function client(SourceControlConnection $connection): SourceControlClient
    {
        $this->client->connection = $connection;

        return $this->client;
    }

    /**
     * Deliveries carry the secret in a header and the branch in the body.
     */
    public function push(Request $request, string $secret): ?Push
    {
        if (! hash_equals($secret, (string) $request->header('X-Fake-Token'))) {
            throw new InvalidWebhookException('Wrong token.');
        }

        return $request->input('event') === 'push' ? new Push($request->input('branch'), $request->input('user')) : null;
    }
}
