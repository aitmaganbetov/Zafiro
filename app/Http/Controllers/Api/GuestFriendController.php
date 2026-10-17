<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Friendship;
use App\Services\Friends;
use App\Services\GuestFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Friends and balance transfers of the signed-in customer (PC agent). */
class GuestFriendController extends Controller
{
    public function __construct(private Friends $friends, private GuestFeatures $features) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'features' => $this->features->all(),
            ...$this->friends->overview($request->user()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->features->ensure('friends');
        $digits = preg_replace('/\D+/', '', (string) $request->input('phone', ''));
        if (strlen($digits) === 11 && in_array($digits[0], ['7', '8'], true)) {
            $digits = substr($digits, 1);
        }
        $request->merge(['phone' => '+7'.$digits]);
        $phone = $request->validate(['phone' => ['required', 'regex:/^\+7\d{10}$/']], ['phone.regex' => __('Введите 10 цифр номера после +7.')])['phone'];

        $friendship = $this->friends->request($request->user(), $phone);

        return response()->json([
            'message' => $friendship->accepted_at === null ? __('Заявка отправлена.') : __('Вы теперь друзья.'),
            ...$this->friends->overview($request->user()),
        ], 201);
    }

    public function accept(Request $request, Friendship $friendship): JsonResponse
    {
        $this->features->ensure('friends');
        $this->friends->accept($friendship, $request->user());

        return response()->json(['message' => __('Вы теперь друзья.'), ...$this->friends->overview($request->user())]);
    }

    public function remove(Request $request, Friendship $friendship): JsonResponse
    {
        $this->friends->remove($friendship, $request->user());

        return response()->json(['message' => __('Готово.'), ...$this->friends->overview($request->user())]);
    }

    public function transfer(Request $request, Friendship $friendship): JsonResponse
    {
        $this->features->ensure('balance_transfer');
        $data = $request->validate([
            'amount' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'max:256'],
        ], ['password.required' => __('Введите пароль от аккаунта, чтобы подтвердить перевод.')]);
        // Money leaves the account only with its password: not by someone at a PC left signed in.
        if (! Hash::check($data['password'], (string) $request->user()->password)) {
            throw ValidationException::withMessages(['password' => __('Неверный пароль. Перевод не выполнен.')]);
        }
        $amount = (string) $data['amount'];
        $result = $this->friends->transfer($friendship, $request->user(), $amount);

        return response()->json([
            'message' => __('Переведено :amount.', ['amount' => $result['amount']]),
            'balance' => $result['balance'],
        ]);
    }
}
