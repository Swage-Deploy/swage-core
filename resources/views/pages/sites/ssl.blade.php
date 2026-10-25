<?php

use App\Actions\Sites\ObtainSiteCertificate;
use App\Actions\Sites\RemoveSiteCertificate;
use App\Enums\ResourceStatus;
use App\Models\Server;
use App\Models\Site;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component {
    public Server $server;

    public Site $site;

    /** @var list<string> */
    public array $domains = [];

    public function mount(): void
    {
        $this->authorize('view', $this->site);

        $this->domains = $this->eligibleDomains();
    }

    public function render(): mixed
    {
        return $this->view()->title(__('SSL').' · '.$this->site->domain);
    }

    /**
     * Domains that can be validated over HTTP. Wildcards need DNS validation.
     *
     * @return list<string>
     */
    public function eligibleDomains(): array
    {
        return ObtainSiteCertificate::eligibleDomains($this->site);
    }

    public function obtain(ObtainSiteCertificate $obtainSiteCertificate): void
    {
        $this->authorize('update', $this->site);

        $obtainSiteCertificate->handle($this->site, $this->domains);

        Flux::toast(variant: 'success', text: __('Requesting certificate from Let\'s Encrypt.'));
    }

    public function remove(RemoveSiteCertificate $removeSiteCertificate): void
    {
        $this->authorize('update', $this->site);

        $removeSiteCertificate->handle($this->site);
    }
}; ?>

<div @if ($site->certificate?->status->isBusy()) wire:poll.3s @endif>
    @php($certificate = $site->certificate)

    <x-pages::sites.layout :site="$site">
        <div class="flex flex-col gap-6">
            @if ($certificate)
                <section @class([
                    'flex flex-col gap-3 rounded-xl border p-5',
                    'border-emerald-500/30 bg-emerald-500/5' => $certificate->isActive(),
                    'border-zinc-200 bg-white dark:border-white/8 dark:bg-zinc-900/70' => ! $certificate->isActive(),
                ])>
                    <div class="flex flex-wrap items-center gap-3">
                        <span @class([
                            'flex size-10 items-center justify-center rounded-lg',
                            'bg-emerald-500/15 text-emerald-500' => $certificate->isActive(),
                            'bg-zinc-100 text-zinc-400 dark:bg-white/5' => ! $certificate->isActive(),
                        ])>
                            <flux:icon.shield-check class="size-5" />
                        </span>
                        <flux:heading class="font-mono">{{ implode(', ', $certificate->domains) }}</flux:heading>
                        <x-status :color="$certificate->status->color()" :label="$certificate->status->label()" />
                    </div>

                    @if ($certificate->expires_at)
                        <flux:text>{{ __('Expires :date. Certbot renews it automatically before then.', ['date' => $certificate->expires_at->toFormattedDateString()]) }}</flux:text>
                    @endif

                    @if ($certificate->renewalIsOverdue())
                        <flux:callout icon="exclamation-triangle" color="amber">
                            <flux:callout.heading>{{ __('This certificate has not renewed') }}</flux:callout.heading>
                            <flux:callout.text>{{ __('Certbot renews certificates 30 days before they expire, so its renewal is failing. Check that every domain still reaches this server over HTTP, including anything in Cloudflare that challenges or blocks bots on /.well-known/acme-challenge/. Run "certbot renew" on the server to see why. Obtaining the certificate again also renews it.') }}</flux:callout.text>
                        </flux:callout>
                    @endif

                    @if ($certificate->status === ResourceStatus::Failed)
                        <flux:text>{{ __('Make sure every domain points at :ip, then try again. The full output is in the server\'s activity log.', ['ip' => $server->ip_address]) }}</flux:text>
                    @endif

                    @unless ($certificate->status->isBusy())
                        <div>
                            <flux:button size="sm" variant="danger" wire:click="remove" wire:confirm.danger="{{ __('Remove the certificate and serve the site over HTTP?') }}">{{ __('Remove certificate') }}</flux:button>
                        </div>
                    @endunless
                </section>
            @endif

            @if (! $certificate || $certificate->status === ResourceStatus::Failed)
                <x-panel icon="lock-closed" :heading="__('Obtain a certificate')" :description="__('Free certificates from Let\'s Encrypt, renewed automatically on the server.')">
                <form wire:submit="obtain" class="flex flex-col gap-4">
                    <flux:checkbox.group wire:model="domains" :label="__('Domains')" :description="__('Each domain must already point at this server.')">
                        @foreach ($this->eligibleDomains() as $domain)
                            <flux:checkbox :value="$domain" :label="$domain" />
                        @endforeach
                    </flux:checkbox.group>

                    <div>
                        <flux:button type="submit" variant="primary" icon="shield-check">{{ __('Obtain certificate') }}</flux:button>
                    </div>
                </form>
                </x-panel>
            @endif

            <flux:callout icon="cloud" color="zinc">
                <flux:callout.heading>{{ __('Behind Cloudflare\'s proxy') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Set Cloudflare\'s SSL/TLS mode to Full (strict) once the certificate is installed. Flexible connects to this server over HTTP, which redirects to HTTPS, so visitors get stuck in a redirect loop. Full and Full (strict) need the certificate, so obtain it first with the domain set to DNS only (the grey cloud) or SSL/TLS on Flexible.') }}
                </flux:callout.text>
            </flux:callout>
        </div>
    </x-pages::sites.layout>
</div>
