<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppAccountController extends Controller
{
    public function connect(Request $request)
    {
        $validated = $request->validate([
            'phone_number_id' => ['required', 'string', 'max:50', 'unique:whatsapp_accounts,phone_number_id'],
            'access_token' => ['required', 'string'],
            'business_account_id' => ['nullable', 'string', 'max:50'],
            'verify_token' => ['required', 'string', 'max:255'],
            'api_version' => ['nullable', 'string', 'max:10'],
        ]);

        try {
            $account = DB::transaction(function () use ($validated) {
                return WhatsAppAccount::create([
                    'user_id' => auth()->id(),
                    'phone_number_id' => $validated['phone_number_id'],
                    'access_token' => $validated['access_token'],
                    'business_account_id' => $validated['business_account_id'] ?? null,
                    'verify_token' => $validated['verify_token'],
                    'api_version' => $validated['api_version'] ?? 'v21.0',
                    'is_active' => true,
                ]);
            });

            Log::info('WhatsApp account connected manually.', [
                'user_id' => auth()->id(),
                'phone_number_id' => $account->phone_number_id,
            ]);

            return response()->json(['status' => true, 'message' => 'WhatsApp account connected successfully', 'data' => $account], 201);
        } catch (Throwable $e) {
            Log::error('Manual WhatsApp connection failed.', ['user_id' => auth()->id(), 'error' => $e->getMessage()]);
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request)
    {
        $account = WhatsAppAccount::where('user_id', auth()->id())->first();
        if (!$account) return response()->json(['status' => false, 'message' => 'No WhatsApp account connected'], 404);

        $validated = $request->validate([
            'phone_number_id' => ['sometimes', 'string', 'max:50', 'unique:whatsapp_accounts,phone_number_id,' . $account->id],
            'access_token' => ['sometimes', 'string'],
            'business_account_id' => ['nullable', 'string', 'max:50'],
            'verify_token' => ['sometimes', 'string', 'max:255'],
            'api_version' => ['nullable', 'string', 'max:10'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $account->update($validated);
        return response()->json(['status' => true, 'message' => 'WhatsApp account updated successfully', 'data' => $account->fresh()]);
    }

    public function show()
    {
        $account = WhatsAppAccount::where('user_id', auth()->id())->first();
        if (!$account) return response()->json(['status' => false, 'message' => 'No WhatsApp account connected'], 404);
        return response()->json(['status' => true, 'data' => $account]);
    }

    public function disconnect()
    {
        $account = WhatsAppAccount::where('user_id', auth()->id())->first();
        if (!$account) return response()->json(['status' => false, 'message' => 'No WhatsApp account connected'], 404);
        $account->delete();
        return response()->json(['status' => true, 'message' => 'WhatsApp account disconnected successfully']);
    }
}
