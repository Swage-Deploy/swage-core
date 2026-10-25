<?php

namespace App\Http\Controllers;

use App\Actions\Servers\ApplyServerSpecs;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Provisioning\ServerSpecs;
use App\Provisioning\ServerUpgrades;
use App\Ssh\Script;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class ProvisionController extends Controller
{
    /**
     * Serve the bootstrap script the user pipes to bash on their server.
     */
    public function script(string $token): Response
    {
        $server = $this->awaitingProvisioning($token);

        return $this->shell(Script::render('provision.bootstrap', ['server' => $server]));
    }

    /**
     * Receive the server's hardware specs and respond with a provisioning script tuned for them.
     */
    public function specs(Request $request, string $token, ApplyServerSpecs $applySpecs): Response
    {
        $server = $this->awaitingProvisioning($token);

        $validated = $request->validate([
            'cpu_cores' => ['required', 'integer', 'min:1', 'max:1024'],
            'memory_mb' => ['required', 'integer', 'min:512', 'max:16777216'],
            'disk_gb' => ['required', 'integer', 'min:1', 'max:1048576'],
            'os_version' => ['required', 'string', 'max:20'],
            'host_key' => ['nullable', 'string', 'max:200', 'regex:/^ssh-ed25519 [A-Za-z0-9+\/]+={0,2}$/D'],
        ]);

        $tuning = $applySpecs->handle($server, new ServerSpecs(
            cpuCores: $validated['cpu_cores'],
            memoryMb: $validated['memory_mb'],
            diskGb: $validated['disk_gb'],
        ));

        // The script about to be served sets the server up the way every upgrade would, and pins its SSH host key
        // as reported over HTTPS, before Swage ever connects to it over SSH.
        $server->update([
            'status' => ServerStatus::Provisioning,
            'os_version' => $validated['os_version'],
            'provisioning_step' => 'Starting',
            'host_key' => $validated['host_key'] ?? null,
            'applied_upgrades' => ServerUpgrades::ids(),
            'redis_password' => $server->has_redis ? ($server->redis_password ?? Str::password(40, symbols: false)) : null,
        ]);

        return $this->shell(Script::render('provision.server', ['server' => $server, 'tuning' => $tuning]));
    }

    /**
     * Record the step the provisioning script is working on.
     */
    public function progress(Request $request, string $token): Response
    {
        $server = $this->provisioning($token);

        $server->update(['provisioning_step' => $request->string('step')->limit(250)->toString()]);

        return response()->noContent();
    }

    /**
     * Mark the server as ready.
     */
    public function finished(string $token): Response
    {
        $this->provisioning($token)->update([
            'status' => ServerStatus::Active,
            'provisioning_step' => null,
            'provisioned_at' => now(),
            'provision_token' => null,
        ]);

        return response()->noContent();
    }

    /**
     * Mark the server as failed so the user can retry with a new provisioning command.
     */
    public function failed(Request $request, string $token): Response
    {
        $server = $this->provisioning($token);

        $server->update([
            'status' => ServerStatus::Failed,
            'provisioning_step' => "{$server->provisioning_step} (failed at line {$request->integer('line')})",
            'provision_token' => Str::random(64),
        ]);

        return response()->noContent();
    }

    protected function awaitingProvisioning(string $token): Server
    {
        return Server::where('provision_token', $token)
            ->whereIn('status', [ServerStatus::Pending, ServerStatus::Failed])
            ->firstOrFail();
    }

    protected function provisioning(string $token): Server
    {
        return Server::where('provision_token', $token)
            ->where('status', ServerStatus::Provisioning)
            ->firstOrFail();
    }

    protected function shell(string $script): Response
    {
        return response($script, 200, ['Content-Type' => 'text/x-shellscript; charset=UTF-8']);
    }
}
