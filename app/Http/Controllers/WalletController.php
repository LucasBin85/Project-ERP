<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

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
            'name'     => [
                'required','string','max:255',
                // unique por usuário:
                Rule::unique('wallets')
                    ->where(fn($query) => $query->where('user_id', $request->user()->id))
            ],
            //'type'     => 'required|in:pf,pj,investimento',
            //'currency' => 'required|string|size:3',
        ]);

        $wallet = $request->user()->wallets()->create($data);

        // Define como carteira ativa
        session(['active_wallet' => $wallet->id]);

        //return redirect()->route('dashboard')->with('success', 'Carteira criada e ativada!');
        return back()->with('success', 'Carteira criada com sucesso!');
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
        //
        //$this->authorize('update', $wallet);

        $data = $request->validate([
            'name' => [
                'required','string','max:255',
                Rule::unique('wallets')
                    ->ignore($wallet->id)
                    ->where(fn($query) => $query->where('user_id', $request->user()->id)),
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
        //
        //$this->authorize('delete', $wallet);
        $user   = auth()->user();
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
        $request->validate([
            'wallet_id' => 'required|integer|exists:wallets,id',
        ]);

        // garante que pertence ao usuário
        $wallet = $request->user()->wallets()->findOrFail($request->wallet_id);

        // guarda na sessão
        session(['active_wallet' => $wallet->id]);

        // opcional: retornar json ou redirect simples
        return back(303);
    }
}
