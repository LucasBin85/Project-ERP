<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ChartOfAccountController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $wallet = $user
            ->wallets()
            ->findOrFail(session('active_wallet', $user->wallets()->first()->id));


        $accounts = $wallet
            ->chartOfAccounts()
            ->orderBy('code')
            ->get()
            ->map(function($a) {
                return [
                    'id'           => $a->id,
                    'code'         => $a->code,
                    'name'         => $a->name,
                    'type'         => $a->type,
                    'is_protected' => $a->is_protected,
                    'parent_id'    => $a->parent_id,
                ];
            });

        // Converter em árvore:
        function buildTree($items, $parentId = null) {
            return collect($items)
                ->where('parent_id', $parentId)
                ->map(function($item) use ($items) {
                    $item['children'] = buildTree($items, $item['id']);
                    return $item;
                })
                ->values()
                ->all();
        }

        $tree = buildTree($accounts);
        return Inertia::render('ChartOfAccounts/Index', [
            'tree' => $tree,
            'activeWallet' => $wallet->id,
        ]);

    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'parent_id' => 'nullable|exists:chart_of_accounts,id',
        ]);

        $wallet = $request->user()
                          ->wallets()
                          ->findOrFail(session('active_wallet'));

        // gera código automático
        if ($data['parent_id']) {
            $parent = ChartOfAccount::findOrFail($data['parent_id']);
            $suffix = $parent->children()->count() + 1;
            $data['code'] = "{$parent->code}.{$suffix}";
            $data['type'] = $parent->type; // herda o tipo do pai
        } else {
            $suffix = $wallet->chartOfAccounts()->whereNull('parent_id')->count() + 1;
            $data['code'] = (string) $suffix;
        }

        $wallet->chartOfAccounts()->create([
            'parent_id'    => $data['parent_id'],
            'code'         => $data['code'],
            'name'         => $data['name'],
            'type'         => $data['type'],    
            'is_protected' => false,
        ]);

        return back(303);
    }

    /**
     * Display the specified resource.
     */
    public function show(ChartOfAccount $chartOfAccount)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ChartOfAccount $chartOfAccount)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ChartOfAccount $chartOfAccount)
    {
        //
        abort_if($chartOfAccount->is_protected, 403);

        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $chartOfAccount->update(['name' => $data['name']]);

        return back(303);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ChartOfAccount $chartOfAccount)
    {
        //
        abort_if($chartOfAccount->is_protected, 403);

        $chartOfAccount->delete();

        return back(303);
    }
}
