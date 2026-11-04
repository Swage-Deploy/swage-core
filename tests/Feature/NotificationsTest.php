<?php

use App\Enums\DeploymentStatus;
use App\Enums\MemberRole;
use App\Enums\NotificationType;
use App\Enums\WebhookKind;
use App\Jobs\CheckRebootRequired;
use App\Jobs\DeploySite;
use App\Jobs\RefreshCertificateExpiry;
use App\Models\Certificate;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\ServerWebhook;
use App\Models\Site;
use App\Models\User;
use App\Notifications\CertificateRenewalOverdue;
use App\Notifications\DeploymentFailed;
use App\Notifications\Notifier;
use App\Notifications\ProvisioningFailed;
use App\Notifications\RebootRequired;
use App\Notifications\ServerAlert;
use App\Notifications\TestNotification;
use App\Plugins\Attention;
use App\Ssh\ScriptResult;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

function withMembers(Server $server, Site $site): array
{
    $serverManager = User::factory()->create();
    $serverViewer = User::factory()->create();
    $siteManager = User::factory()->create();
    $server->members()->attach($serverManager->id, ['role' => MemberRole::Manager->value]);
    $server->members()->attach($serverViewer->id, ['role' => MemberRole::Viewer->value]);
    $site->members()->attach($siteManager->id, ['role' => MemberRole::Manager->value]);

    return [$serverManager, $serverViewer, $siteManager];
}

test('a failed deployment notifies the server\'s owner and managers and the site\'s managers, not viewers', function () {
    Notification::fake();
    $shell = fakeShell()->respondTo('', new ScriptResult(1, 'composer install failed'));
    $site = Site::factory()->withRepository()->create();
    [$serverManager, $serverViewer, $siteManager] = withMembers($site->server, $site);
    $otherSite = Site::factory()->for($site->server)->create();
    $otherSiteManager = User::factory()->create();
    $otherSite->members()->attach($otherSiteManager->id, ['role' => MemberRole::Manager->value]);
    $deployment = Deployment::factory()->for($site)->create();

    (new DeploySite($deployment))->handle($shell);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed);
    Notification::assertSentTo([$site->server->user, $serverManager, $siteManager], DeploymentFailed::class);
    Notification::assertNotSentTo([$serverViewer, $otherSiteManager], DeploymentFailed::class);
});

test('a successful deployment notifies no one', function () {
    Notification::fake();
    $shell = fakeShell();
    $deployment = Deployment::factory()->for(Site::factory()->withRepository())->create();

    (new DeploySite($deployment))->handle($shell);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Finished);
    Notification::assertNothingSent();
});

test('failed provisioning notifies the server\'s owner', function () {
    Notification::fake();
    $server = Server::factory()->provisioning()->create();

    $this->post(route('provision.failed', $server->provision_token), ['line' => 42])->assertNoContent();

    Notification::assertSentTo($server->user, ProvisioningFailed::class, fn (ProvisioningFailed $notification) => str_contains($notification->message(), 'failed at line 42'));
});

test('a server needing a reboot is notified once, until it has rebooted', function () {
    Notification::fake();
    fakeShell()->respondTo('/var/run/reboot-required', new ScriptResult(0, "SWAGE_REBOOT_REQUIRED\nlibc6\n"));
    $server = Server::factory()->create();

    CheckRebootRequired::dispatchSync($server);
    CheckRebootRequired::dispatchSync($server->fresh());

    Notification::assertSentToTimes($server->user, RebootRequired::class, 1);
});

test('a certificate that misses its renewal is notified once per missed renewal', function () {
    Notification::fake();
    $certificate = Certificate::factory()->create(['expires_at' => now()->addDays(10)]);
    $domain = $certificate->site->domain;
    $server = $certificate->site->server;
    $shell = fakeShell()->respondTo('cert.pem', new ScriptResult(0, "SWAGE_CERT {$domain} ".now()->addDays(9)->toRfc7231String()."\n"));

    RefreshCertificateExpiry::dispatchSync($server, $certificate->newCollection([$certificate]));
    RefreshCertificateExpiry::dispatchSync($server, $certificate->newCollection([$certificate->fresh()]));

    Notification::assertSentToTimes($server->user, CertificateRenewalOverdue::class, 1);
    expect($certificate->fresh()->overdue_notified_at)->not->toBeNull();

    // Renewed, then overdue again at the next renewal.
    $shell->respondTo('cert.pem', new ScriptResult(0, "SWAGE_CERT {$domain} ".now()->addDays(90)->toRfc7231String()."\n"));
    RefreshCertificateExpiry::dispatchSync($server, $certificate->newCollection([$certificate->fresh()]));
    expect($certificate->fresh()->overdue_notified_at)->toBeNull();

    $shell->respondTo('cert.pem', new ScriptResult(0, "SWAGE_CERT {$domain} ".now()->addDays(5)->toRfc7231String()."\n"));
    RefreshCertificateExpiry::dispatchSync($server, $certificate->newCollection([$certificate->fresh()]));
    Notification::assertSentToTimes($server->user, CertificateRenewalOverdue::class, 2);
});

test('people choose which notifications reach them by email', function () {
    $user = User::factory()->create();
    $server = Server::factory()->for($user)->create();

    expect((new RebootRequired($server))->via($user))->toBe(['database', 'mail']);

    Livewire::actingAs($user)->test('pages::settings.notifications')
        ->call('toggle', NotificationType::RebootRequired->value);

    expect($user->fresh()->wantsEmail(NotificationType::RebootRequired))->toBeFalse()
        ->and($user->fresh()->wantsEmail(NotificationType::DeploymentFailed))->toBeTrue()
        ->and((new RebootRequired($server))->via($user->fresh()))->toBe(['database']);
});

test('only administrators can choose panel update notifications', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.notifications')
        ->assertDontSee(NotificationType::UpdateFailed->label())
        ->call('toggle', NotificationType::UpdateFailed->value)
        ->assertNotFound();

    Livewire::actingAs(User::factory()->create(['is_admin' => true]))->test('pages::settings.notifications')
        ->assertSee(NotificationType::UpdateFailed->label());
});

test('a test notification reaches the person in the panel and by email', function () {
    Notification::fake();
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.notifications')->call('sendTest');

    Notification::assertSentTo($user, TestNotification::class, fn (TestNotification $notification, array $channels) => $channels === ['database', 'mail']);
});

test('notifications are emailed with a link to the page to look at', function () {
    $server = Server::factory()->create(['name' => 'production-1']);

    $mail = (new RebootRequired($server))->toMail($server->user);

    expect($mail)->toBeInstanceOf(MailMessage::class)
        ->and($mail->subject)->toBe('production-1 needs a reboot')
        ->and($mail->actionUrl)->toBe(route('servers.show', $server));
});

test('the notifications page lists a person\'s notifications and opening one marks it read', function () {
    $server = Server::factory()->create(['name' => 'production-1']);
    $server->user->notifyNow(new RebootRequired($server));
    $notification = $server->user->notifications()->sole();

    $this->actingAs($server->user)->get(route('notifications'))->assertOk()->assertSee('production-1 needs a reboot');
    $this->actingAs($server->user)->get(route('dashboard'))->assertOk()->assertSeeInOrder(['Notifications', '1']);

    Livewire::actingAs($server->user)->test('pages::notifications')
        ->call('open', $notification->id)
        ->assertRedirect(route('servers.show', $server));

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('people can only open their own notifications', function () {
    $server = Server::factory()->create();
    $server->user->notifyNow(new RebootRequired($server));

    Livewire::actingAs(User::factory()->create())->test('pages::notifications')
        ->call('open', $server->user->notifications()->sole()->id)
        ->assertNotFound();
});

test('marking everything read clears the unread count', function () {
    $server = Server::factory()->create();
    $server->user->notifyNow(new RebootRequired($server));
    $server->user->notifyNow(new RebootRequired($server));

    Livewire::actingAs($server->user)->test('pages::notifications')->call('markAllRead');

    expect($server->user->unreadNotifications()->count())->toBe(0);
});

test('plugins raise alerts about a server', function () {
    Notification::fake();
    $server = Server::factory()->create(['name' => 'production-1']);

    ServerAlert::send($server, 'Security scanner', 'Brute-force attack on SSH', '120 failed sign-ins from 203.0.113.9.', Attention::HIGH, 'https://swage.test/scanner');

    Notification::assertSentTo($server->user, ServerAlert::class, function (ServerAlert $alert) {
        return $alert->title() === 'Security scanner: Brute-force attack on SSH on production-1'
            && $alert->url() === 'https://swage.test/scanner'
            && $alert->type() === NotificationType::ServerAlert;
    });
});

test('alerts must have a severity swage shows', function () {
    new ServerAlert(Server::factory()->create(), 'Plugin', 'Title', 'Message', 'urgent');
})->throws(InvalidArgumentException::class);

test('a server\'s notifications post to its webhooks in each one\'s format', function () {
    Http::fake();
    $server = Server::factory()->create(['name' => 'production-1']);
    $server->webhooks()->create(['name' => 'ops', 'kind' => WebhookKind::Slack, 'url' => 'https://93.184.216.34/slack']);
    $server->webhooks()->create(['name' => 'team', 'kind' => WebhookKind::Discord, 'url' => 'https://93.184.216.35/discord']);
    $server->webhooks()->create(['name' => 'pager', 'kind' => WebhookKind::Json, 'url' => 'https://93.184.216.36/json']);

    app(Notifier::class)->send(new RebootRequired($server));

    Http::assertSent(fn (Request $request) => $request->url() === 'https://93.184.216.34/slack' && str_contains($request['text'], '*production-1 needs a reboot*'));
    Http::assertSent(fn (Request $request) => $request->url() === 'https://93.184.216.35/discord' && $request['embeds'][0]['title'] === 'production-1 needs a reboot');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://93.184.216.36/json' && $request['type'] === 'reboot_required' && $request['server']['name'] === 'production-1');
    expect($server->webhooks()->whereNull('last_sent_at')->count())->toBe(0);
});

test('a webhook that fails records why', function () {
    Http::fake(['*' => Http::response('nope', 404)]);
    $server = Server::factory()->create();
    $webhook = $server->webhooks()->create(['name' => 'ops', 'kind' => WebhookKind::Slack, 'url' => 'https://93.184.216.34/slack']);

    app(Notifier::class)->send(new RebootRequired($server));

    expect($webhook->fresh()->last_error)->toBe('The webhook answered with HTTP 404.');
});

test('webhooks never post to the panel\'s own network', function () {
    Http::fake();
    $server = Server::factory()->create();
    $webhook = $server->webhooks()->create(['name' => 'ops', 'kind' => WebhookKind::Json, 'url' => 'https://127.0.0.1/hook']);

    app(Notifier::class)->send(new RebootRequired($server));

    Http::assertNothingSent();
    expect($webhook->fresh()->last_error)->toContain('private network');
});

test('managers add, test and remove a server\'s webhooks, which are kept encrypted', function () {
    Http::fake();
    $server = Server::factory()->create();
    $manager = User::factory()->create();
    $server->members()->attach($manager->id, ['role' => MemberRole::Manager->value]);

    $page = Livewire::actingAs($manager)->test('pages::servers.notifications', ['server' => $server])
        ->set('name', 'ops')
        ->set('kind', 'slack')
        ->set('url', 'https://10.0.0.5/hook')
        ->call('add')
        ->assertHasErrors('url')
        ->set('url', 'https://93.184.216.34/services/T000/B000/secret')
        ->call('add')
        ->assertHasNoErrors()
        ->assertSee('93.184.216.34')
        ->assertDontSee('secret');

    $webhook = ServerWebhook::sole();
    expect($webhook->getRawOriginal('url'))->not->toContain('secret');

    $page->call('test', $webhook->id);
    Http::assertSent(fn (Request $request) => str_contains($request['text'], 'Test notification from Swage'));

    $page->call('remove', $webhook->id);
    expect(ServerWebhook::count())->toBe(0);
});

test('viewers see a server\'s webhooks but cannot change them', function () {
    $server = Server::factory()->create();
    $viewer = User::factory()->create();
    $server->members()->attach($viewer->id, ['role' => MemberRole::Viewer->value]);
    $webhook = $server->webhooks()->create(['name' => 'ops', 'kind' => WebhookKind::Slack, 'url' => 'https://93.184.216.34/slack']);

    Livewire::actingAs($viewer)->test('pages::servers.notifications', ['server' => $server])
        ->assertSee('ops')
        ->assertDontSee(__('Add a webhook'))
        ->set('name', 'mine')->set('url', 'https://93.184.216.35/x')->call('add')
        ->assertDispatched('toast-show');

    Livewire::actingAs($viewer)->test('pages::servers.notifications', ['server' => $server])
        ->call('remove', $webhook->id)
        ->assertDispatched('toast-show');

    expect(ServerWebhook::count())->toBe(1);
});
