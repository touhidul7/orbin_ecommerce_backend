<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CourierController extends Controller
{
    public function createOrder(Request $request)
    {
        try {
            $response = Http::withOptions([
                'verify' => false, // Disable SSL verification
            ])->withHeaders([
                'Api-Key' => env('STEADFAST_API_KEY'),
                'Secret-Key' => env('STEADFAST_SECRET_KEY'),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post(env('STEADFAST_BASE_URL') . '/create_order', [
                'invoice' => $request->invoice,
                'recipient_name' => $request->recipient_name,
                'recipient_phone' => $request->recipient_phone,
                'recipient_address' => $request->recipient_address,
                'cod_amount' => $request->cod_amount,
                'note' => $request->note,
            ]);

            if ($response->successful()) {
                return response()->json($response->json(), $response->status());
            } else {
                return response()->json([
                    'error' => 'Steadfast API Error',
                    'status' => $response->status(),
                    'body' => $response->body()
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function checkStatus($consignment_id)
    {
        try {
            $response = Http::withOptions([
                'verify' => false, // Disable SSL verification
            ])->withHeaders([
                'Api-Key' => env('STEADFAST_API_KEY'),
                'Secret-Key' => env('STEADFAST_SECRET_KEY'),
                'Accept' => 'application/json',
            ])->get(env('STEADFAST_BASE_URL') . '/status_by_cid/' . $consignment_id);

            if ($response->successful()) {
                return response()->json($response->json(), $response->status());
            } else {
                return response()->json([
                    'error' => 'Steadfast API Error',
                    'status' => $response->status(),
                    'body' => $response->body()
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function createReturnRequest(Request $request)
    {
        try {
            $response = Http::withOptions([
                'verify' => false, // Disable SSL verification
            ])->withHeaders([
                'Api-Key' => env('STEADFAST_API_KEY'),
                'Secret-Key' => env('STEADFAST_SECRET_KEY'),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post(env('STEADFAST_BASE_URL') . '/create_return_request', [
                'consignment_id' => $request->consignment_id,
                'reason' => $request->reason,
            ]);

            if ($response->successful()) {
                return response()->json($response->json(), $response->status());
            } else {
                return response()->json([
                    'error' => 'Steadfast API Error',
                    'status' => $response->status(),
                    'body' => $response->body()
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}