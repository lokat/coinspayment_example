<?php

namespace App\Http\Controllers;

use App\Classes\CoinPaymentsAPI;
use App\Classes\IBFDClass;
use App\Classes\OttoAPIClass;
use App\CoinRates;
use App\CPTransaction;
use App\IBFixedDeposit;
use App\IBRequestWithdraw;
use App\IBWithdrawRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class IBController extends Controller
{

    public function __construct()
    {
        $this->middleware('member', ['except' => 'coinpayments_ipn']);
    }

    public static function getRates()
    {
        $cps = new CoinPaymentsAPI();

        $result = $cps->GetRates();
        if ($result['error'] == 'ok') {
            print 'Number of currencies: '.count($result['result'])."\n";
            foreach ($result['result'] as $coin => $rate) {
                $cr = CoinRates::updateOrCreate([
                    'code' => $coin
                    ]);
                $cr->is_fiat = $rate['is_fiat'];
                $cr->rate_to_btc = $rate['rate_btc'];
                $cr->save();
            }
            print 'done';
        } else {
            print 'Error: '.$result['error']."\n";
        }
    }

    public static function getConversionRate($code, $amount) {
        /*
         * 1) get usd rate in btc (btc_rate * amount_usd = amount_rate_in_btc)
         * 2) get coin per btc (1/coin rate = coin_per_btc)
         * 3) multiple with coin per btc (amount_rate_in_btc * coin_per_btc)
         */

        $cr = CoinRates::where('code', $code)->first();

        $rate = 0;
        if (count($cr)) {
            $amount_rate_in_btc = self::getUSDRate($amount);
            $coin_per_btc = 1/$cr->rate_to_btc;
            $rate = $coin_per_btc * $amount_rate_in_btc;
        }
        return $rate;
    }

    public static function getUSDRate($amount) {
        $cr = CoinRates::where('code', 'USD')->first();

        $rate = 0;
        if (count($cr)) {
            $rate = $cr->rate_to_btc * $amount;
        }

        return $rate;
    }

    public function ajax_send_fixed_deposit(Request $request) {
        $cps = new CoinPaymentsAPI();

        $code = $request->code;
        $amount_usd = $request->amount_usd;
        $amount = self::getConversionRate($code, $amount_usd);

        $user = Auth::user();

        $req = array(
            'amount' => $amount,
            'currency1' => $code,
            'currency2' => $code,
            'address' => '', // send to address in the Coin Acceptance Settings page
            'item_name' => 'Fixed Deposit',
            'buyer_email' => $user->email,
//            'ipn_url' => 'https://yourserver.com/ipn_handler.php',
        );
        // See https://www.coinpayments.net/apidoc-create-transaction for all of the available fields

        $result = $cps->CreateTransaction($req);
        if ($result['error'] == 'ok') {

            $cp_transaction = new CPTransaction();
            $cp_transaction->txn_id = $result['result']['txn_id'];
            $cp_transaction->user_id = $user->id;
            $cp_transaction->amount = $req['amount'];
            $cp_transaction->amount_in_usd = $request->amount_usd;
            $cp_transaction->currency1 = $req['currency1'];
            $cp_transaction->currency2 = $req['currency2'];
            $cp_transaction->address = $result['result']['address'];
            $cp_transaction->confirms_needed = $result['result']['confirms_needed'];
            $cp_transaction->timeout = $result['result']['timeout'];
            $cp_transaction->status_url = $result['result']['status_url'];
            $cp_transaction->qrcode_url = $result['result']['qrcode_url'];
            $cp_transaction->status = 0;
            $cp_transaction->save();

            return view('ajax.fixed_deposit')
                ->with('payment_detail', $result['result'])
                ->with('code', $req['currency2']);
        } else {
            print 'Error: '.$result['error']."\n";
        }
    }

    public function check_status(Request $request) {
        $result = CPTransaction::where('txn_id', '=', $request->txn_id)->first();


        $status = 0;
        if (count($result)) {
            $status = $result->status;
        }

        if ($status >= 100 || $status == 2) {
            if (!IBFDClass::checkTransaction($request->txn_id)) {
                $user = Auth::user();
                $user_id = $user->id;
                $txn_type = 'FD';
                $available_at = Carbon::now()->addDay('21');
                $amount = $result->amount_in_usd;
                IBFDClass::setIBFD($user_id, $txn_type, $request->txn_id, '', $available_at, $amount);

                //commision for upline
                $user_id = $user->upline_user_id;
                $txn_type = 'FDC';
                $available_at = Carbon::now()->addDay('30');
                $amount = $result->amount_in_usd * 0.05;
                IBFDClass::setIBFD($user_id, $txn_type, $request->txn_id, '', $available_at, $amount);
            }
        }

        echo $status;
    }

    public function coinpayments_ipn(Request $request) {
        $cp_merchant_id = env('CP_MERCHANT_ID', '');
        $cp_ipn_secret = env('CP_IPN_SECRET', '');
        $order_currency = 'DOGE';
        $order_total = 10.00;

        if (!isset($request->ipn_mode) || $request->ipn_mode != 'hmac') {
            self::errorAndDie('IPN Mode is not HMAC');
        }

        if (empty($request->server('HTTP_HMAC'))) {
            self::errorAndDie('No HMAC signature sent.');
        }

        if (!isset($request->merchant) || $request->merchant != trim($cp_merchant_id)) {
            self::errorAndDie('No or incorrect Merchant ID passed');
        }

        $hmac = hash_hmac("sha512", $request->getContent(), trim($cp_ipn_secret));
        if ($hmac != $request->server('HTTP_HMAC')) {
            self::errorAndDie('HMAC signature does not match');
        }

        $txn_id = $request->txn_id;
        $item_name = $request->item_name;
        $item_number = $request->item_number;
        $amount1 = floatval($request->amount1);
        $amount2 = floatval($request->amount2);
        $currency1 = $request->currency1;
        $currency2 = $request->currency2;
        $status = intval($request->status);
        $status_text = $request->status_text;

        if ($currency1 != $order_currency) {
            self::errorAndDie('Original currency mismatch!');
        }

        if ($amount1 < $order_total) {
            self::errorAndDie('Amount is less than order total!');
        }

        $cp_transaction = CPTransaction::where('txn_id', $txn_id)->first();

        if (count($cp_transaction)) {
            $cp_transaction->status = $status;
            $cp_transaction->save();
        }

        if ($status >= 100 || $status == 2) {
            if (!IBFDClass::checkTransaction($txn_id)) {
                $user = Auth::user();
                $user_id = $user->id;
                $txn_type = 'FD';
                $available_at = Carbon::now()->addDay('21');
                $amount = $cp_transaction->amount_in_usd;
                IBFDClass::setIBFD($user_id, $txn_type, $txn_id, '', $available_at, $amount);

                //commision for upload
                $user_id = $user->upline_user_id;
                $txn_type = 'FDC';
                $available_at = Carbon::now()->addDay('30');
                $amount = $cp_transaction->amount_in_usd * 0.05;
                IBFDClass::setIBFD($user_id, $txn_type, $txn_id, '', $available_at, $amount);
            }
        }

        die('IPN OK');
    }

    public function errorAndDie($error_msg) {
        $cp_debug_email = env('CP_DEBUG_EMAIL', 'email@zulhalimi.com');
        if (!empty($cp_debug_email)) {
            $report = 'Error: '.$error_msg."\n\n";
            $report .= "POST Data\n\n";
            foreach ($_POST as $k => $v) {
                $report .= "|$k| = |$v|\n";
            }
//            mail($cp_debug_email, 'CoinPayments IPN Error', $report);
            Log::error('CoinPayments IPN Error', ['context' => $report]);
        }
        die('IPN Error: '.$error_msg);
    }

    public function get_conversion_rate(Request $request) {
        $markup = 1.025;

        $rate = self::getConversionRate($request->code, $request->amount);

        $result = [
            'status' => 'failed',
            'final_rate' => 0
        ];
        if ($rate) {
            $final_rate = $rate * $markup;
            $result = [
                'status' => 'success',
                'final_rate' => $final_rate
            ];
        }

        return $result;

    }

    public function get_value_in_btc(Request $request) {

        $rate = IBFDClass::getUSDValueInBTC($request->amount);

        $result = [
            'status' => 'failed',
            'final_rate' => 0
        ];
        if ($rate) {
            $result = [
                'status' => 'success',
                'final_rate' => $rate
            ];
        }

        return $result;

    }

    public function coinpayments_confirm()
    {
        $user_id = Auth::user()->id;

        $pending_transactions = CPTransaction::where('user_id', '=', $user_id)
            ->orderBy('created_at', 'desc')
            ->first();

        $transaction = false;
        if (!empty($pending_transactions)) {
            $transaction['txn_id'] = $pending_transactions->txn_id;
            $transaction['user_id'] = $pending_transactions->user_id;
            $transaction['amount'] = $pending_transactions->amount;
            $transaction['currency1'] = $pending_transactions->currency1;
            $transaction['currency2'] = $pending_transactions->currency2;
            $transaction['address'] = $pending_transactions->address;
            $transaction['confirms_needed'] = $pending_transactions->confirms_needed;
            $transaction['timeout'] = $pending_transactions->timeout;
            $transaction['status_url'] = $pending_transactions->status_url;
            $transaction['qrcode_url'] = $pending_transactions->qrcode_url;
            $transaction['status'] = $pending_transactions->status;

        }

        if ($transaction) {
            return view('member.ib.payment_confirmation')->with('transaction', $transaction);
        } else {
            return Redirect::route('home');
        }
    }

    public function fixed_deposit()
    {
        $user_id = Auth::user()->id;
        $fixed_deposits = IBFixedDeposit::where('user_id', $user_id)->get();
        $total_active_dividen = IBFDClass::getTotalActiveDividen($user_id);
        return view('member.ib.fixed_deposit')
            ->with('fixed_deposits', $fixed_deposits)
            ->with('total_active_dividen', $total_active_dividen);
    }

    public function withdraw()
    {
        $user = Auth::user();
        $user_id = $user->id;
        $wallet_address = $user->wallet_address;
        $available_fund = IBFDClass::getAvailableFund($user_id);

        $withdraw_requests = IBWithdrawRequest::where('user_id', $user_id)->get();

        return view('member.ib.withdraw')
            ->with('available_fund', $available_fund)
            ->with('wallet_address', $wallet_address)
            ->with('withdraw_requests', $withdraw_requests);
    }

    public function withdraw_request(Request $request)
    {
        $user = Auth::user();
        $user_id = $user->id;
        $wallet_address = $user->wallet_address;

        $available_fund = IBFDClass::getAvailableFund($user_id);
        $pending_request = IBFDClass::getTotalPendingRequestAmount($user_id);
        $amount_request = $request->amount;
        $amount_request_in_btc = IBFDClass::getUSDValueInBTC($amount_request);

        $balance = $available_fund - $pending_request - $amount_request;

        if ($balance >= 0) {

            $ibRW = new IBRequestWithdraw();
            $ibRW->user_id = $user_id;
            $ibRW->amount = $amount_request;
            $ibRW->value_in_btc = $amount_request_in_btc;
            $ibRW->status = 0;
            $ibRW->save();

            $otto_address = OttoAPIClass::createNewAddress($user_id, $ibRW->id, 'withdraw_');

            if ($otto_address) {
                $ibRW->otto_address = $otto_address->data->address;
                $ibRW->save();

                $result['otto_address'] = $ibRW->otto_address;
                $result['otto_amount'] = 1;
                $result['timeout'] = 172800;
                $result['request_id'] = $ibRW->id;

                return view('ajax.ib-withdraw')
                    ->with('payment_detail', $result);
            }


        }

        return back()->withErrors('Insufficient available fund!');

    }

    public function check_status_wr(Request $request) {
        $result = IBRequestWithdraw::where('otto_address', $request->otto_address)->first();

        $status = 0;
        if (count($result)) {
            $status = $result->status;

            if (!IBFDClass::checkTransactionRequestWithdraw($request->otto_address)) {
                $ibWR = new IBWithdrawRequest();
                $ibWR->user_id = $result->user_id;
                $ibWR->amount = $result->amount;
                $ibWR->value_in_btc = $result->value_in_btc;
                $ibWR->status = 'Pending';
                $ibWR->otto_address = $request->otto_address;
                $ibWR->save();
            }

        }

        echo $status;
    }

}
