<?php

namespace App\Services\Widget;

use App\Models\Widget;

class WidgetService
{
    public function __construct(
        private readonly WidgetChallengeService $widgetChallengeService,
    ) {
    }

    public function resolveByPublicKey(string $publicKey): ?Widget
    {
        return Widget::query()->where('public_key', $publicKey)->where('is_active', true)->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function publicConfig(Widget $widget): array
    {
        return [
            'name' => $widget->name,
            'public_key' => $widget->public_key,
            'theme' => $widget->theme_json ?? [
                'primary_color' => '#0E9F6E',
                'accent_color' => '#063F2B',
                'position' => 'bottom-right',
            ],
            'default_locale' => $widget->default_locale,
            'challenge' => $this->widgetChallengeService->publicConfig(),
        ];
    }
}
