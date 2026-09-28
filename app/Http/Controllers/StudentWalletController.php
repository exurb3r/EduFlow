<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentWalletController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // Placeholder shape validation until a real chain validator
            // (e.g. web3 address checksum) is wired in.
            'wallet_address' => [
                'required',
                'string',
                'min:16',
                'max:64',
                'regex:/^0x[a-fA-F0-9]{16,62}$/',
                Rule::unique('users', 'wallet_address')->ignore($request->user()->id),
            ],
        ], [
            'wallet_address.regex' => 'Enter a valid wallet address (0x followed by hexadecimal characters).',
        ]);

        $request->user()->update([
            'wallet_address' => strtolower((string) $validated['wallet_address']),
        ]);

        return back()->with('status', 'wallet-updated');
    }
}
