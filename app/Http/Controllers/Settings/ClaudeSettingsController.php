<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ClaudeAuthMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateClaudeSettingsRequest;
use App\Services\AppSettings;
use App\Services\Claude\ClaudeCode;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ClaudeSettingsController extends Controller
{
    public function edit(ClaudeCode $claude, AppSettings $settings): Response
    {
        return Inertia::render('settings/Claude', [
            'status' => $claude->status(),
            'settings' => $settings->claudeConnectionSettings(),
            'authModes' => collect(ClaudeAuthMode::cases())->map(fn (ClaudeAuthMode $mode): array => [
                'value' => $mode->value,
                'label' => $mode->label(),
            ]),
        ]);
    }

    public function update(UpdateClaudeSettingsRequest $request, AppSettings $settings): RedirectResponse
    {
        $validated = $request->validated();
        $mode = ClaudeAuthMode::tryFrom((string) ($validated['auth_mode'] ?? ''));
        $previousMode = $settings->claudeAuthMode();

        $settings->setMany([
            AppSettings::CLAUDE_AUTH_MODE => $mode?->value,
            AppSettings::CLAUDE_MODEL => $validated['model'] ?? null,
            AppSettings::CLAUDE_BINARY_PATH => $validated['binary_path'] ?? null,
            AppSettings::CLAUDE_MAX_BUDGET_USD => $validated['max_budget_usd'] ?? null,
        ]);

        if ($mode === null || $mode === ClaudeAuthMode::CliLogin) {
            $settings->setSecret(AppSettings::CLAUDE_CREDENTIAL, null);
        } elseif (filled($validated['credential'] ?? null)) {
            $settings->setSecret(AppSettings::CLAUDE_CREDENTIAL, trim($validated['credential']));
        } elseif ($previousMode !== $mode) {
            $settings->setSecret(AppSettings::CLAUDE_CREDENTIAL, null);
        }

        return back()->with('success', $mode === null ? 'Claude disconnected.' : 'Claude settings saved.');
    }
}
