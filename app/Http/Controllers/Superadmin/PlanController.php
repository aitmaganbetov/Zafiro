<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\ClubSubscription;
use App\Models\SaasPlan;
use App\Services\PlatformLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Platform subscription plans that clubs pay for. Plans are switched off, never deleted. */
class PlanController extends Controller
{
    public function __construct(private PlatformLogger $platformLog) {}

    public function index(): View
    {
        $clubs = ClubSubscription::query()->selectRaw('saas_plan_id, COUNT(*) AS aggregate')->groupBy('saas_plan_id')->pluck('aggregate', 'saas_plan_id');

        return view('superadmin.plans.index', [
            'plans' => SaasPlan::query()->orderBy('price_monthly')->orderBy('id')->get()
                ->each(fn (SaasPlan $plan) => $plan->setAttribute('clubs_count', (int) ($clubs[$plan->id] ?? 0))),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data, $request): void {
            $plan = SaasPlan::query()->create($data);
            $this->platformLog->record('superadmin.plan_created', __('Создан тариф платформы: :name', ['name' => $plan->name]), $request->user(), null, $plan);
        });

        return back()->with('status', __('Тариф создан.'));
    }

    public function update(Request $request, SaasPlan $plan): RedirectResponse
    {
        $data = $this->validated($request, $plan);

        DB::transaction(function () use ($data, $plan, $request): void {
            $plan->update($data);
            $changes = collect($plan->getChanges())->except('updated_at')->map(fn (mixed $value): string => is_array($value) ? implode(', ', $value) : (string) $value)->all();
            if ($changes !== []) {
                $this->platformLog->record('superadmin.plan_updated', __('Изменён тариф платформы: :name', ['name' => $plan->name]), $request->user(), null, $plan, $changes);
            }
        });

        return back()->with('status', __('Тариф сохранён.'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?SaasPlan $plan = null): array
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:80', 'alpha_dash:ascii', Rule::unique('saas_plans', 'slug')->ignore($plan)],
            'name' => ['required', 'string', 'max:120'],
            'price_monthly' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'features' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // One feature per line in the form.
        $data['features'] = collect(preg_split('/\R/', (string) ($data['features'] ?? '')))->map(fn (string $line): string => trim($line))->filter()->values()->all() ?: null;
        $data['is_active'] = (bool) ($data['is_active'] ?? false);

        return $data;
    }
}
