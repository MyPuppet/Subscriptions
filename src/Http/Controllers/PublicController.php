<?php

namespace TypiCMS\Modules\Subscriptions\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Laravel\Cashier\Order\Order;
use Laravel\Cashier\SubscriptionBuilder\RedirectToCheckoutResponse;
use Mollie\Api\Types\PaymentStatus;
use Mollie\Laravel\Facades\Mollie;
use TypiCMS\Modules\Core\Http\Controllers\BasePublicController;
use TypiCMS\Modules\Subscriptions\Http\Requests\SubscriptionsPlan;
use TypiCMS\Modules\Subscriptions\Http\Requests\SubscriptionsProfileUpdate;

class PublicController extends BasePublicController
{
    public function profileIndex(): View
    {
//        $models = CashierSubscription::published()->order()->with('image')->get();
        $models = collect([]);

        return view('subscriptions::public.index')
            ->with(compact('models'));
    }

    public function profileEdit()
    {
        $models = collect([]);

        return view('subscriptions::public.edit')
            ->with(compact('models'));
    }

    public function profileUpdate(SubscriptionsProfileUpdate $request)
    {
        Auth::user()->update($request->validated());

        return back()->with('success', __('Your profile has been successfully updated'));
    }

    public function paymentMethod()
    {
        $models = collect([]);

        $customer = Mollie::api()->customers()->get(Auth::user()->mollie_customer_id);
        $mandates = $customer->mandates();

        $activeMandates = collect([]);
        foreach ($mandates as $mandate) {
            if ($mandate->status == 'valid') {
                $activeMandates->push($mandate);
            }
        }

        return view('subscriptions::public.paymentmethods')
            ->with(compact('models', 'activeMandates'));
    }

    public function paymentMethodRevoke(Request $request, $id)
    {
        try {
            $customer = Mollie::api()->customers()->get(Auth::user()->mollie_customer_id);
            $customer->getMandate($id)->revoke();

            return redirect()->route(app()->getLocale().'::subscriptions-paymentmethod')->with('success', __('Your payment method was sucesfully revoked.'));
        } catch (Exception $e) {
            return redirect()->route(app()->getLocale().'::subscriptions-paymentmethod')->with('error', __('Your payment method could not be revoked.'));
        }
    }

    public function paymentMethodUpdate()
    {
        // Not now.
        return redirect()->route(app()->getLocale().'::subscriptions-paymentmethod');
    }

    public function plans()
    {
        $models = collect();
        $plans = collect(config('cashier_plans.plans'));

        return view('subscriptions::public.plans')
            ->with(compact('models', 'plans'));
    }

    public function subscribe(SubscriptionsPlan $request)
    {
        $plan = $request->input('plan');

        $user = Auth::user();
        $name = 'main';

        if (!$user->subscribed($name, $plan)) {
            $result = $user->newSubscription($name, $plan)->create();

            if (is_a($result, RedirectToCheckoutResponse::class)) {
                return $result; // Redirect to Mollie checkout
            }

            return back()->with('success', __('You are now successfully subscribed.'));
        }

        return back()->with('error', __('You are already on the '.$plan.' plan'));
    }

    public function cancel(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user->subscription('main')->cancelled() && !$user->subscription('main')->onGracePeriod()) {
                $user->subscription('main')->cancel();

                return redirect()->route(app()->getLocale().'::subscriptions-plans')->with('success', __('Your subscription was sucessfully cancelled.'));
            }

            return redirect()->route(app()->getLocale().'::subscriptions-plans')->with('error', __('Your subscription could not be cancelled.'));
        } catch (Exception $e) {
            return redirect()->route(app()->getLocale().'::subscriptions-plans')->with('error', __('Your subscription could not be cancelled.'));
        }
    }

    public function resume()
    {
        try {
            Auth::user()->subscription('main')->resume();

            return redirect()->route(app()->getLocale().'::subscriptions-plans')->with('success', __('Your subscription was sucessfully resumed.'));
        } catch (Exception $e) {
            return redirect()->route(app()->getLocale().'::subscriptions-plans')->with('error', __('Your subscription could not be resumed.'));
        }
    }

    public function upgrade()
    {
        $models = collect();
        $plans = collect(config('cashier_plans.plans'));

        $plans->forget(Auth::user()->subscription('main')->plan);

        return view('subscriptions::public.upgrade')
            ->with(compact('models', 'plans'));
    }

    public function upgradePost(SubscriptionsPlan $request)
    {
        try {
            Auth::user()->subscription('main')->swap($request->input('plan'));
        } catch (Exception $e) {
            return redirect()->route(app()->getLocale().'::subscriptions-plans')->with('error', __('Your subscription could not be upgraded.'));
        }

        return redirect()->route(app()->getLocale().'::subscriptions-plans')->with('success', __('Your subscription was sucessfully upgraded.'));
    }

    public function invoices()
    {
        $models = collect();
        $invoices = Auth::user()->orders->invoices();

        return view('subscriptions::public.invoices')
            ->with(compact('models', 'invoices'));
    }

    public function invoice(Request $request, $id)
    {
        $order = Order::where('number', $id)->firstOrFail();

        if ($order->owner_id !== Auth::user()->id) {
            abort(403);
        }

        return $order->invoice()->view();
    }

    public function checkPayment(Request $request, $payment_id)
    {
        try {
            $payment = Mollie::api()->payments()->get($payment_id);

            if ($payment->status == PaymentStatus::STATUS_PAID) {
                return redirect()->route(app()->getLocale().'::subscriptions-plans')->with('success', __('You are now successfully subscribed.'));
            }

            return redirect()->route(app()->getLocale().'::subscriptions-plans')->with('error', __('Your subscription could not be perfomed. Please retry'));
        } catch (Exception $e) {
            return redirect()->route(app()->getLocale().'::subscriptions-plans')->with('error', __('Your subscription could not be perfomed. Please retry'));
        }
    }
}
