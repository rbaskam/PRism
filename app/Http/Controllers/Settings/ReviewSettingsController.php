<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReviewSettingsController extends Controller
{
    public function edit(AppSettings $settings): Response
    {
        return Inertia::render('settings/Review', [
            'settings' => [
                'prompt' => $settings->reviewPrompt(),
                'allow_posting_findings' => $settings->allowsPostingFindings(),
                'allow_replying_to_comments' => $settings->allowsReplyingToComments(),
                'auto_review_on_request' => $settings->reviewsAutomaticallyWhenRequested(),
                'allow_code_changes' => $settings->allowsCodeChanges(),
                'allow_pushing' => $settings->allowsPushing(),
            ],
            'defaultPrompt' => AppSettings::DEFAULT_REVIEW_PROMPT,
            'placeholders' => AppSettings::REVIEW_PROMPT_PLACEHOLDERS,
        ]);
    }

    public function update(Request $request, AppSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:20000'],
            'allow_posting_findings' => ['required', 'boolean'],
            'allow_replying_to_comments' => ['required', 'boolean'],
            'auto_review_on_request' => ['required', 'boolean'],
            'allow_code_changes' => ['sometimes', 'boolean'],
            'allow_pushing' => ['sometimes', 'boolean'],
        ]);

        $settings->setMany([
            AppSettings::REVIEW_PROMPT => trim($validated['prompt']) === trim(AppSettings::DEFAULT_REVIEW_PROMPT) ? null : $validated['prompt'],
            AppSettings::ALLOW_POSTING_FINDINGS => $validated['allow_posting_findings'],
            AppSettings::ALLOW_REPLYING_TO_COMMENTS => $validated['allow_replying_to_comments'],
            AppSettings::AUTO_REVIEW_ON_REQUEST => $validated['auto_review_on_request'],
            AppSettings::ALLOW_CODE_CHANGES => $validated['allow_code_changes'] ?? $settings->allowsCodeChanges(),
            AppSettings::ALLOW_PUSHING => $validated['allow_pushing'] ?? $settings->allowsPushing(),
        ]);

        return back()->with('success', 'Review settings saved.');
    }
}
