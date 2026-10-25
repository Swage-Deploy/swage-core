<?php

namespace App\Providers;

use App\Actions\Users\Impersonation;
use App\Models\User;
use App\Servers\ServerIntegrations;
use App\Sites\SiteIntegrations;
use App\SourceControl\ProviderSettings;
use App\SourceControl\SourceControlProviders;
use App\Ssh\PhpseclibRemoteShell;
use App\Ssh\RemoteShell;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use WeakMap;

use function Livewire\on;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(RemoteShell::class, PhpseclibRemoteShell::class);

        $this->app->scoped(ProviderSettings::class);

        // Source control providers, server integrations and site integrations come from plugins, which register them
        // from their own service provider's boot method.
        $this->app->singleton(SourceControlProviders::class);
        $this->app->singleton(ServerIntegrations::class);
        $this->app->singleton(SiteIntegrations::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configurePageRoutes();
        $this->configureRefusedActions();
        $this->configureRateLimits();

        Gate::define('administer', fn (User $user): bool => (bool) $user->is_admin);

        // @impersonating and @unlessimpersonating hide what an administrator signed in as another user may not use.
        Blade::if('impersonating', fn (): bool => app(Impersonation::class)->isImpersonating());
    }

    /**
     * Tell people when an action they clicked is not theirs to take, such as a viewer pressing a button only managers
     * may use, instead of showing a bare 403.
     *
     * Only actions and property changes are handled this way. A component refused while mounting still fails, so a page
     * someone may not see is never rendered for them.
     */
    /**
     * API requests are limited per API key's user, so one busy script cannot crowd out everyone else.
     */
    protected function configureRateLimits(): void
    {
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
    }

    protected function configureRefusedActions(): void
    {
        /** @var WeakMap<Component, true> $calling */
        $calling = new WeakMap;

        // Both actions and property changes, such as flipping a switch, are something the person did.
        on('call', function (Component $component) use ($calling): void {
            $calling[$component] = true;
        });

        on('update', function (Component $component) use ($calling): void {
            $calling[$component] = true;
        });

        on('exception', function (Component $component, Throwable $exception, callable $stopPropagation) use ($calling): void {
            $refused = $exception instanceof AuthorizationException
                || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 403);

            if ($refused && isset($calling[$component])) {
                Flux::toast(variant: 'danger', text: __('You do not have permission to do that. Ask the server\'s owner or a manager.'));
                $stopPropagation();
            }
        });
    }

    /**
     * Let views check the route of the page being viewed.
     *
     * A Livewire update is a request to Livewire's own endpoint, so routeIs() stops matching the page after the
     * first interaction. pageRouteIs() resolves the route from the page's original URL instead.
     */
    protected function configurePageRoutes(): void
    {
        Request::macro('pageRouteIs', function (string ...$patterns): bool {
            /** @var Request $this */
            $route = Livewire::isLivewireRequest()
                ? rescue(fn (): Route => app('router')->getRoutes()->match(Request::create(Livewire::originalUrl())), report: false)
                : $this->route();

            return $route?->named(...$patterns) ?? false;
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
