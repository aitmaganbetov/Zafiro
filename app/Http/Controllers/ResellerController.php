<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Reseller;
use App\Models\ResellerPayout;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\PlatformLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The reseller cabinet: signing up with a code of one's own choice, then the clubs that entered
 * the code and what they brought (a percent of each subscription payment, set by the superadmin).
 */
class ResellerController extends Controller
{
    public function __construct(private PlatformLogger $platformLog) {}

    public function showRegister(): View
    {
        return view('reseller.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $digits = preg_replace('/\D+/', '', (string) $request->input('phone', ''));
        $digits = strlen($digits) === 11 && str_starts_with($digits, '7') ? substr($digits, 1) : $digits;
        $request->merge(['phone' => $digits === '' ? '' : '+7'.$digits, 'code' => Reseller::normalizeCode($request->input('code'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\+7\d{10}$/', 'unique:users'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            ...$this->codeRules(),
        ], [
            'phone.regex' => __('Введите корректный номер телефона.'),
            'phone.unique' => __('Этот номер уже зарегистрирован: войдите и станьте реселлером в кабинете.'),
            'password.confirmed' => __('Пароли не совпадают.'),
            ...$this->codeMessages(),
        ]);

        $user = DB::transaction(function () use ($data): User {
            $user = User::query()->create(['name' => $data['name'], 'phone' => $data['phone'], 'password' => Hash::make($data['password'])]);
            $this->createReseller($user, $data['code'], $data['name']);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('reseller.dashboard')->with('status', __('Добро пожаловать! Сообщите клубам свой код.'));
    }

    /** A signed-in account becomes a reseller. */
    public function store(Request $request): RedirectResponse
    {
        abort_if(Reseller::query()->where('user_id', $request->user()->getKey())->exists(), 409);
        $request->merge(['code' => Reseller::normalizeCode($request->input('code'))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], ...$this->codeRules()], $this->codeMessages());

        DB::transaction(fn () => $this->createReseller($request->user(), $data['code'], $data['name']));

        return redirect()->route('reseller.dashboard')->with('status', __('Готово! Сообщите клубам свой код.'));
    }

    public function dashboard(Request $request): View
    {
        $reseller = Reseller::query()->where('user_id', $request->user()->getKey())->first();
        if ($reseller === null) {
            return view('reseller.join', ['user' => $request->user()]);
        }

        $paid = fn () => SubscriptionInvoice::query()->withoutGlobalScope('club')->where('reseller_id', $reseller->getKey())->where('status', 'paid');
        $earned = (float) $paid()->sum('reseller_amount');
        $paidOut = (float) ResellerPayout::query()->where('reseller_id', $reseller->getKey())->sum('amount');

        return view('reseller.dashboard', [
            'reseller' => $reseller,
            'clubs' => Club::query()->where('reseller_id', $reseller->getKey())
                ->with('subscription.plan:id,name')
                ->orderByDesc('reseller_attached_at')
                ->paginate(30, ['id', 'name', 'city', 'reseller_attached_at'], 'clubs_page'),
            'earnings' => $paid()->with('club:id,name')->latest('paid_at')->latest('id')
                ->paginate(20, ['id', 'club_id', 'months', 'amount', 'reseller_percent', 'reseller_amount', 'paid_at'], 'earnings_page'),
            'payouts' => ResellerPayout::query()->where('reseller_id', $reseller->getKey())->latest('id')->limit(20)->get(['amount', 'note', 'created_at']),
            'earned' => $earned,
            'paidOut' => $paidOut,
        ]);
    }

    private function createReseller(User $user, string $code, string $name): Reseller
    {
        $reseller = new Reseller(['code' => $code, 'name' => $name]);
        $reseller->forceFill(['user_id' => $user->getKey()])->save();
        $this->platformLog->record('reseller.registered', __('Новый реселлер: :name (код :code)', ['name' => $name, 'code' => $code]), $user, null, $reseller);

        return $reseller;
    }

    /** @return array<string, array<int, mixed>> */
    private function codeRules(): array
    {
        return ['code' => ['required', 'string', 'regex:'.Reseller::CODE_PATTERN, Rule::unique('resellers', 'code')]];
    }

    /** @return array<string, string> */
    private function codeMessages(): array
    {
        return [
            'code.regex' => __('Код — от 3 до 32 символов: латинские буквы, цифры и дефис, начинается с буквы или цифры.'),
            'code.unique' => __('Этот код уже занят — выберите другой.'),
        ];
    }
}
