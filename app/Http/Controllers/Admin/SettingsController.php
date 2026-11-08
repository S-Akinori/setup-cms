<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        return view('admin.settings.edit', [
            'previewUrl' => Setting::valueOf('preview_url'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'preview_url' => ['nullable', 'url:http,https', 'max:2048'],
        ]);

        $previewUrl = filled($data['preview_url'] ?? null)
            ? rtrim((string) $data['preview_url'])
            : null;
        Setting::setValue('preview_url', $previewUrl);
        Audit::record($request, 'settings.updated', metadata: [
            'preview_url_configured' => $previewUrl !== null,
        ]);

        return back()->with('success', '設定を保存しました。');
    }
}
