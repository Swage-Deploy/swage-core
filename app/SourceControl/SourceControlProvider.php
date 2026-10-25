<?php

namespace App\SourceControl;

use App\Models\SourceControlConnection;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * A git host that sites can install repositories from, such as GitHub. Built-in providers and plugins register their
 * implementations with SourceControlProviders.
 *
 * Plugins depend on this contract, so changing it is a breaking change for them: add methods to a new interface
 * instead, and bump SourceControlProviders::CONTRACT_VERSION when an existing method must change.
 */
interface SourceControlProvider
{
    /**
     * The short identifier stored on sites and used in URLs, such as "github". Lowercase letters, numbers and dashes.
     */
    public function id(): string;

    /**
     * The name shown to users, such as "GitHub".
     */
    public function name(): string;

    /**
     * Determine if the provider has the settings it needs to connect accounts, such as OAuth credentials.
     */
    public function isConfigured(): bool;

    /**
     * Explain how to configure the provider, shown while it is not configured. The callback URL accounts return to is
     * route('source-control.callback', $this->id()).
     */
    public function setupInstructions(): string;

    /**
     * Send the user to the provider to authorize access to their repositories.
     */
    public function redirect(): RedirectResponse;

    /**
     * Finish connecting an account once the provider sends the user back.
     *
     * @throws SourceControlException when the authorization failed or was denied.
     */
    public function connectedAccount(Request $request): ConnectedAccount;

    /**
     * Create an API client acting as a connected account. Providers whose tokens expire refresh them here and save the
     * new ones to the connection.
     */
    public function client(SourceControlConnection $connection): SourceControlClient;

    /**
     * Read a webhook delivery for a site, after checking it came from the provider using the secret Swage gave the
     * webhook. Returns null for deliveries that are not pushes, such as the provider's test ping.
     *
     * @throws InvalidWebhookException when the delivery cannot be verified.
     */
    public function push(Request $request, string $secret): ?Push;
}
