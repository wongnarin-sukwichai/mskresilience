<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ตั้งค่าทั่วไป (ตั้งค่าระบบ module, admin). The keys, labels and defaults live in App\Support\Settings; each change
 * is in the audit trail (AppSetting logs activity).
 */
class SystemSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/SystemSettings', [
            'settings' => Settings::all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (Settings::definitions() as $key => $definition) {
            $rules[$key] = match ($definition['type']) {
                'bool' => ['sometimes', 'boolean'],
                'text' => ['sometimes', 'nullable', 'string', 'max:500'],
                'number' => ['sometimes', 'numeric', 'min:'.$definition['min'], 'max:'.$definition['max']],
                'select' => ['sometimes', 'string', 'in:'.implode(',', array_keys($definition['options']))],
            };
        }
        $values = $request->validate($rules);

        foreach ($values as $key => $value) {
            $definition = Settings::definitions()[$key];
            $value = match ($definition['type']) {
                'bool' => (bool) $value,
                // An emptied text goes back to the default wording.
                'text' => trim((string) $value) === '' ? $definition['default'] : trim((string) $value),
                'number' => $value + 0,
                'select' => (string) $value,
            };
            if ($value !== Settings::get($key)) {
                Settings::set($key, $value);
            }
        }

        return back()->with('status', 'บันทึกการตั้งค่าเรียบร้อยแล้ว');
    }
}
