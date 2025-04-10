<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * Initiate a payment request and return payment gateway information
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function request(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1000',
            'description' => 'required|string|max:255',
            'payable_type' => 'required|string',
            'payable_id' => 'required',
            'mobile' => 'nullable|ir_mobile:zero',
            'email' => 'nullable|email',
        ]);

        try {
            $payment = $request->user()->payments()->create([
                'amount' => $request->input('amount'),
                'description' => $request->input('description'),
                'status' => 'pending',
                'payable_type' => $request->input('payable_type'),
                'payable_id' => $request->input('payable_id'),
            ]);

            $response = zarinpal()
                ->merchantId(config('services.zarinpal.merchant_id'))
                ->amount($request->input('amount'))
                ->request()
                ->description($request->input('description'))
                ->callbackUrl(route('payment.verify'))
                ->send();

            if (!$response->success()) {
                $payment->update(['status' => 'failed']);

                return response()->json([
                    'status' => 'error',
                    'message' => $response->error()->message()
                ], 422);
            }

            $payment->update(['authority' => $response->authority()]);

            return response()->json([
                'status' => 'success',
                'payment_id' => $payment->id,
                'redirect_url' => $response->redirect(),
                'authority' => $response->authority(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Payment process failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify payment after customer returns from payment gateway
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verify(Request $request)
    {
        $authority = $request->query('Authority');
        $status = $request->query('Status');

        // Check if payment was canceled by user
        if ($status !== 'OK') {
            // Try to find payment by authority and update status
            $payment = Payment::where('authority', $authority)->first();
            if ($payment) {
                $payment->update(['status' => 'canceled']);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Payment was canceled by user',
            ], 422);
        }

        $payment = Payment::where('authority', $authority)->first();

        if (!$payment) {
            return response()->json([
                'status' => 'error',
                'message' => 'Payment information not found'
            ], 404);
        }

        try {
            $response = zarinpal()
                ->merchantId(config('services.zarinpal.merchant_id'))
                ->amount($payment->amount)
                ->verification()
                ->authority($authority)
                ->send();

            if (!$response->success()) {
                // Update payment status to failed
                $payment->update(['status' => 'failed']);

                return response()->json([
                    'status' => 'error',
                    'message' => $response->error()->message()
                ], 422);
            }

            // Update payment record with success information
            $payment->update([
                'status' => 'completed',
                'reference_id' => $response->referenceId(),
                'card_pan' => $response->cardPan(),
                'card_hash' => $response->cardHash(),
            ]);

            return response()->json([
                'status' => 'success',
                'payment' => $payment,
            ]);
        } catch (\Exception $e) {

            if ($payment) {
                $payment->update(['status' => 'failed']);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Payment verification failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
