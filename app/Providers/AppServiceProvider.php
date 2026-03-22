<?php

namespace App\Providers;

use App\Support\TenantContext;
use App\Services\Widget\WidgetAbuseLogService;
use App\Services\Widget\WidgetService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerWidgetRateLimiters();
    }

    private function registerWidgetRateLimiters(): void
    {
        RateLimiter::for('widget-config', fn (Request $request): array => $this->widgetLimits($request, 'config', 120, 240));
        RateLimiter::for('widget-session-start', fn (Request $request): array => $this->widgetLimits($request, 'session_start', 20, 80));
        RateLimiter::for('widget-message', fn (Request $request): array => $this->widgetLimits($request, 'message', 40, 120));
        RateLimiter::for('widget-checkout', fn (Request $request): array => $this->widgetLimits($request, 'checkout', 24, 100));
        RateLimiter::for('widget-lead', fn (Request $request): array => $this->widgetLimits($request, 'lead', 30, 120));
        RateLimiter::for('widget-event', fn (Request $request): array => $this->widgetLimits($request, 'event', 90, 240));
    }

    /**
     * @return array<int, Limit>
     */
    private function widgetLimits(Request $request, string $scope, int $perMinute, int $perMinuteIp): array
    {
        $configuredScope = max(10, (int) config("services.widget.rate_limit.{$scope}_per_minute", $perMinute));
        $configuredIp = max(20, (int) config('services.widget.rate_limit.ip_per_minute', $perMinuteIp));

        if (app()->environment('testing')) {
            $configuredScope = max($configuredScope, 20000);
            $configuredIp = max($configuredIp, 30000);
        }

        return [
            $this->widgetScopedLimit($request, $scope, $configuredScope),
            $this->widgetIpLimit($request, $scope, $configuredIp),
        ];
    }

    private function widgetScopedLimit(Request $request, string $scope, int $perMinute): Limit
    {
        $key = 'widget:'.sha1(implode('|', [
            $scope,
            (string) $request->ip(),
            trim((string) $request->input('public_key', (string) $request->route('publicKey', ''))),
            trim((string) $request->input('visitor_uuid', '')),
            trim((string) $request->input('session_id', (string) $request->input('conversation_id', ''))),
        ]));

        return Limit::perMinute($perMinute)
            ->by($key)
            ->response(function (Request $request, array $headers) use ($scope) {
                $this->logRateLimitBreach($request, $scope, 'scoped');

                return response()->json([
                    'message' => 'Too many widget requests. Please slow down and try again.',
                ], 429, $headers);
            });
    }

    private function widgetIpLimit(Request $request, string $scope, int $perMinute): Limit
    {
        $key = 'widget-ip:'.sha1($scope.'|'.(string) $request->ip());

        return Limit::perMinute($perMinute)
            ->by($key)
            ->response(function (Request $request, array $headers) use ($scope) {
                $this->logRateLimitBreach($request, $scope, 'ip');

                return response()->json([
                    'message' => 'Too many widget requests. Please slow down and try again.',
                ], 429, $headers);
            });
    }

    private function logRateLimitBreach(Request $request, string $scope, string $type): void
    {
        $publicKey = trim((string) $request->input('public_key', (string) $request->route('publicKey', '')));
        $widget = $publicKey !== '' ? app(WidgetService::class)->resolveByPublicKey($publicKey) : null;

        app(WidgetAbuseLogService::class)->log(
            $request,
            'rate_limited',
            $widget,
            $publicKey !== '' ? $publicKey : null,
            [
                'scope' => $scope,
                'limit_type' => $type,
            ],
        );
    }
}
