<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WalletController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Wallets/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                // unique por usuário:
                Rule::unique('wallets')
                    ->where(fn ($query) => $query->where('user_id', $request->user()->id)),
            ],
            // 'type'     => 'required|in:pf,pj,investimento',
            // 'currency' => 'required|string|size:3',
        ]);

        $wallet = DB::transaction(fn () => $request->user()->wallets()->create($data));
        $request->session()->put('active_wallet', $wallet->id);

        return redirect()->route('dashboard', status: 303)
            ->with('success', 'Carteira criada e ativada!');
    }

    /**
     * Display the specified resource.
     */
    public function show(Wallet $wallet)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Wallet $wallet)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Wallet $wallet): RedirectResponse
    {
        abort_unless((int) $wallet->user_id === (int) $request->user()->id, 404);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('wallets')
                    ->ignore($wallet->id)
                    ->where(fn ($query) => $query->where('user_id', $request->user()->id)),
            ],
        ]);

        $wallet->update($data);

        return back()->with('success', 'Carteira atualizada com sucesso!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Wallet $wallet): RedirectResponse
    {
        $user = auth()->user();
        abort_unless((int) $wallet->user_id === (int) $user->id, 404);
        $active = session('active_wallet', null);

        $wallet->delete();

        // Se era a ativa, define uma nova ou limpa
        if ($active === $wallet->id) {
            // Pega a primeira carteira restante do usuário (ou null, se não houver)
            $newActive = $user->wallets()->orderBy('id')->first();
            session([
                'active_wallet' => $newActive?->id,
            ]);
        }

        return back()->with('success', 'Carteira removida com sucesso!');
    }

    /**
     * Define a carteira ativa na sessão.
     */
    public function setActive(Request $request)
    {
        $request->validate(['wallet_id' => ['required', 'integer']]);

        // garante que pertence ao usuário
        $wallet = $request->user()->wallets()->findOrFail($request->wallet_id);

        // guarda na sessão
        session(['active_wallet' => $wallet->id]);

        return redirect()->route('dashboard', status: 303)
            ->with('success', 'Carteira ativa alterada!');
    }
}
