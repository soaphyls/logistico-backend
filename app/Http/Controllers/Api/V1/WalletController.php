<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\CodLedger;
use App\Models\Customer;
use App\Models\PartnerCustomer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    /**
     * Get the current user's wallet (Customer or Partner)
     */
    public function me(Request $request)
    {
        $user = Auth::user();
        $wallet = null;

        // Try to find wallet based on user role/type
        if ($user->role && $user->role->name === 'super_admin') {
            // Admin doesn't have a personal wallet in this context, 
            // but might want to see overall stats or system wallet
            return response()->json([
                'success' => true,
                'message' => 'Admin System Wallet',
                'data' => [
                    'balance' => Wallet::sum('balance'),
                    'currency' => 'NGN',
                    'is_system' => true
                ]
            ]);
        }

        // Check if user is a Partner (stored as User with partner role)
        if ($user->role->slug === 'partner') {
            // Find PartnerCustomer or just use the user as owner
            $wallet = Wallet::where('owner_type', 'App\Models\User')
                            ->where('owner_id', $user->id)
                            ->first();
        } else {
            // Check if user is a Customer
            $customer = Customer::where('email', $user->email)->first();
            if ($customer) {
                $wallet = $customer->wallet;
            }
        }

        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Wallet not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $wallet->load('transactions')
        ]);
    }

    /**
     * Get wallet transactions
     */
    public function transactions(Request $request)
    {
        $user = Auth::user();
        $wallet = null;

        if ($user->role->slug === 'partner') {
            $wallet = Wallet::where('owner_type', 'App\Models\User')
                            ->where('owner_id', $user->id)
                            ->first();
        } else {
            $customer = Customer::where('email', $user->email)->first();
            if ($customer) {
                $wallet = $customer->wallet;
            }
        }

        if (!$wallet) {
            return response()->json(['success' => false, 'message' => 'Wallet not found'], 404);
        }

        $transactions = $wallet->transactions()
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $transactions
        ]);
    }

    /**
     * Deposit funds (usually via payment gateway callback, but here for manual or testing)
     */
    public function deposit(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'reference' => 'required|string|unique:wallet_transactions,reference',
            'description' => 'nullable|string'
        ]);

        $user = Auth::user();
        $wallet = null;

        if ($user->role->slug === 'partner') {
            $wallet = Wallet::where('owner_type', 'App\Models\User')
                            ->where('owner_id', $user->id)
                            ->first();
        } else {
            $customer = Customer::where('email', $user->email)->first();
            if ($customer) {
                $wallet = $customer->wallet;
            }
        }

        if (!$wallet) {
            return response()->json(['success' => false, 'message' => 'Wallet not found'], 404);
        }

        $transaction = $wallet->deposit(
            $request->amount,
            $request->description,
            $request->reference,
            ['method' => $request->payment_method ?? 'manual']
        );

        return response()->json([
            'success' => true,
            'message' => 'Deposit successful',
            'data' => $transaction
        ]);
    }

    /**
     * Get COD Ledger for dispatchers/admin
     */
    public function codLedger(Request $request)
    {
        $user = Auth::user();
        $query = CodLedger::with(['shipment', 'dispatcher']);

        if ($user->role->slug === 'driver') {
            $query->where('dispatcher_id', $user->dispatcher->id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $ledger = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $ledger
        ]);
    }
}
