<?php

namespace Laravel\Cashier\Tests;

use Carbon\Carbon;
use Conekta\Conekta;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Http\Request;
use Laravel\Cashier\Tests\Fixtures\CashierTestControllerStub;
use Laravel\Cashier\Tests\Fixtures\User;
use PHPUnit_Framework_TestCase;

class CashierTest extends PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
        if (file_exists(__DIR__.'/../.env')) {
            $dotenv = new \Dotenv\Dotenv(__DIR__.'/../');
            $dotenv->load();
        }
    }

    public function setUp()
    {
        Eloquent::unguard();

        $db = new DB;
        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $db->bootEloquent();
        $db->setAsGlobal();

        $this->schema()->create('users', function ($table) {
            $table->increments('id');
            $table->string('email');
            $table->string('name');
            $table->string('conekta_id')->nullable();
            $table->string('card_brand')->nullable();
            $table->string('card_last_four')->nullable();
            $table->timestamps();
        });

        $this->schema()->create('subscriptions', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('name');
            $table->string('conekta_id');
            $table->string('conekta_plan');
            $table->integer('quantity');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        $this->schema()->create('plans', function ($table) {
            $table->increments('id');
            $table->string('conekta_id');
            // $table->string('name');
            // $table->integer('amount');
            // $table->string('currency', 3)->default('MXN');
            // $table->string('interval')->nullable();
            // $table->string('frequency')->nullable();
            // $table->integer('trial_period_days');
            // $table->integer('expiry_count');
            $table->timestamps();
        });

        $this->setApiKey();
    }

    public function tearDown()
    {
        $this->schema()->drop('users');
        $this->schema()->drop('subscriptions');
        $this->schema()->drop('plans');
    }

    /**
     * Tests.
     */
    public function testSubscriptionsCanBeCreated()
    {
        $user = User::create([
            'email' => 'alfonso@vexilo.com',
            'name' => 'Alfonso Bribiesca',
        ]);

        $plan = $this->createPlan('monthly-10-1', 'Mensual Plan');

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')
            ->create($this->getTestToken());

        $this->assertEquals(1, count($user->subscriptions));
        $this->assertNotNull($user->subscription('main')->conekta_id);

        $this->assertTrue($user->subscribed('main'));
        $this->assertTrue($user->subscribedToPlan('monthly-10-1', 'main'));
        $this->assertFalse($user->subscribedToPlan('monthly-10-1', 'something'));
        $this->assertFalse($user->subscribedToPlan('monthly-10-2', 'main'));
        $this->assertTrue($user->subscribed('main', 'monthly-10-1'));
        $this->assertFalse($user->subscribed('main', 'monthly-10-2'));
        $this->assertTrue($user->subscription('main')->active());
        $this->assertFalse($user->subscription('main')->cancelled());
        $this->assertFalse($user->subscription('main')->onGracePeriod());
        $this->assertTrue($user->subscription('main')->recurring());
        $this->assertFalse($user->subscription('main')->ended());

        // Cancel Subscription
        $subscription = $user->subscription('main');
        $subscription->cancel();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->cancelled());
        $this->assertTrue($subscription->onGracePeriod());
        $this->assertFalse($subscription->recurring());
        $this->assertFalse($subscription->ended());

        // Modify Ends Date To Past
        $oldGracePeriod = $subscription->ends_at;
        $subscription->fill(['ends_at' => Carbon::now()->subDays(5)])->save();

        $this->assertFalse($subscription->active());
        $this->assertTrue($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertFalse($subscription->recurring());
        $this->assertTrue($subscription->ended());

        $subscription->fill(['ends_at' => $oldGracePeriod])->save();

        // Resume Subscription
        $subscription->resume();

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertTrue($subscription->recurring());
        $this->assertFalse($subscription->ended());

        // Swap Plan
        $plan = $this->createPlan('monthly-10-2', 'Mensual Plan 2');

        $subscription->swap('monthly-10-2');

        $this->assertEquals('monthly-10-2', $subscription->conekta_plan);
    }

    // public function test_creating_subscription_with_coupons()
    // {
    //     $user = User::create([
    //         'email' => 'alfonso@vexilo.com',
    //         'name' => 'Alfonso Bribiesca',
    //     ]);

    //     // Create Subscription
    //     $user->newSubscription('main', 'monthly-10-1')
    //             ->withCoupon('coupon-1')->create($this->getTestToken());

    //     $subscription = $user->subscription('main');

    //     $this->assertTrue($user->subscribed('main'));
    //     $this->assertTrue($user->subscribed('main', 'monthly-10-1'));
    //     $this->assertFalse($user->subscribed('main', 'monthly-10-2'));
    //     $this->assertTrue($subscription->active());
    //     $this->assertFalse($subscription->cancelled());
    //     $this->assertFalse($subscription->onGracePeriod());
    //     $this->assertTrue($subscription->recurring());
    //     $this->assertFalse($subscription->ended());

    //     // Invoice Tests
    //     $invoice = $user->invoices()[0];

    //     $this->assertTrue($invoice->hasDiscount());
    //     $this->assertEquals('$5.00', $invoice->total());
    //     $this->assertEquals('$5.00', $invoice->amountOff());
    //     $this->assertFalse($invoice->discountIsPercentage());
    // }

    public function test_creating_subscription_with_an_anchored_billing_cycle()
    {
        $user = User::create([
            'email' => 'alfonso@vexilo.com',
            'name' => 'Alfonso Bribiesca',
        ]);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')
            ->anchorBillingCycleOn(new \DateTime('first day of next month'))
            ->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $this->assertTrue($user->subscribed('main'));
        $this->assertTrue($user->subscribed('main', 'monthly-10-1'));
        $this->assertFalse($user->subscribed('main', 'monthly-10-2'));
        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertTrue($subscription->recurring());
        $this->assertFalse($subscription->ended());

        // Invoice Tests
        $invoice = $user->invoices()[0];
        $invoicePeriod = $invoice->invoiceItems()[0]->period;

        $this->assertEquals(
            (new \DateTime('now'))->format('Y-m-d'),
            date('Y-m-d', $invoicePeriod->start)
        );
        $this->assertEquals(
            (new \DateTime('first day of next month'))->format('Y-m-d'),
            date('Y-m-d', $invoicePeriod->end)
        );
    }

    public function test_generic_trials()
    {
        $user = new User;
        $this->assertFalse($user->onGenericTrial());
        $user->trial_ends_at = Carbon::tomorrow();
        $this->assertTrue($user->onGenericTrial());
        $user->trial_ends_at = Carbon::today()->subDays(5);
        $this->assertFalse($user->onGenericTrial());
    }

    public function test_creating_subscription_with_trial()
    {
        $user = User::create([
            'email' => 'alfonso@vexilo.com',
            'name' => 'Alfonso Bribiesca',
        ]);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')
                ->trialDays(7)->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onTrial());
        $this->assertFalse($subscription->recurring());
        $this->assertFalse($subscription->ended());
        $this->assertEquals(Carbon::today()->addDays(7)->day, $subscription->trial_ends_at->day);

        // Cancel Subscription
        $subscription->cancel();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onGracePeriod());
        $this->assertFalse($subscription->recurring());
        $this->assertFalse($subscription->ended());

        // Resume Subscription
        $subscription->resume();

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertTrue($subscription->onTrial());
        $this->assertFalse($subscription->recurring());
        $this->assertFalse($subscription->ended());
        $this->assertEquals(Carbon::today()->addDays(7)->day, $subscription->trial_ends_at->day);
    }

    public function test_creating_subscription_with_explicit_trial()
    {
        $user = User::create([
             'email' => 'alfonso@vexilo.com',
             'name' => 'Alfonso Bribiesca',
        ]);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')
             ->trialUntil(Carbon::tomorrow()->hour(3)->minute(15))->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onTrial());
        $this->assertFalse($subscription->recurring());
        $this->assertFalse($subscription->ended());
        $this->assertEquals(Carbon::tomorrow()->hour(3)->minute(15), $subscription->trial_ends_at);

        // Cancel Subscription
        $subscription->cancel();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onGracePeriod());
        $this->assertFalse($subscription->recurring());
        $this->assertFalse($subscription->ended());

        // Resume Subscription
        $subscription->resume();

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertTrue($subscription->onTrial());
        $this->assertFalse($subscription->recurring());
        $this->assertFalse($subscription->ended());
        $this->assertEquals(Carbon::tomorrow()->hour(3)->minute(15), $subscription->trial_ends_at);
    }

    public function test_applying_coupons_to_existing_customers()
    {
        $user = User::create([
            'email' => 'alfonso@vexilo.com',
            'name' => 'Alfonso Bribiesca',
        ]);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')
                ->create($this->getTestToken());

        $user->applyCoupon('coupon-1');

        $customer = $user->asStripeCustomer();

        $this->assertEquals('coupon-1', $customer->discount->coupon->id);
    }

    /**
     * @group foo
     */
    public function test_marking_as_cancelled_from_webhook()
    {
        $user = User::create([
            'email' => 'alfonso@vexilo.com',
            'name' => 'Alfonso Bribiesca',
        ]);

        $user->newSubscription('main', 'monthly-10-1')
                ->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $request = Request::create('/', 'POST', [], [], [], [], json_encode(['id' => 'foo', 'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => $subscription->conekta_id,
                    'customer' => $user->conekta_id,
                ],
            ],
        ]));

        $controller = new CashierTestControllerStub;
        $response = $controller->handleWebhook($request);
        $this->assertEquals(200, $response->getStatusCode());

        $user = $user->fresh();
        $subscription = $user->subscription('main');

        $this->assertTrue($subscription->cancelled());
    }

    public function testCreatingOneOffInvoices()
    {
        $user = User::create([
            'email' => 'alfonso@vexilo.com',
            'name' => 'Alfonso Bribiesca',
        ]);

        // Create Invoice
        $user->createAsStripeCustomer($this->getTestToken());
        $user->invoiceFor('Laravel Cashier', 1000);

        // Invoice Tests
        $invoice = $user->invoices()[0];
        $this->assertEquals('$10.00', $invoice->total());
        $this->assertEquals('Laravel Cashier', $invoice->invoiceItems()[0]->asStripeInvoiceItem()->description);
    }

    public function testRefunds()
    {
        $user = User::create([
            'email' => 'alfonso@vexilo.com',
            'name' => 'Alfonso Bribiesca',
        ]);

        // Create Invoice
        $user->createAsStripeCustomer($this->getTestToken());
        $invoice = $user->invoiceFor('Laravel Cashier', 1000);

        // Create the refund
        $refund = $user->refund($invoice->charge);

        // Refund Tests
        $this->assertEquals(1000, $refund->amount);
    }

    /**
     * Creates a new Conekta\Plan
     *
     * @return \Conekta\Plan
     */
    protected function createPlan($id, $name)
    {
        // @TODO: Revisar estos datos
        // ORiginalmente eran parate de la suscripción
        // if ($this->skipTrial) {
        //     $trialEndsAt = null;
        // } else {
        //     $trialEndsAt = $this->trialExpires;
        // }

        $attributes = array_filter([
            'id' => $id,
            'name' => $name,
            'amount' => 1000, // @TODO
            'currency' => 'MXN', // @TODO
            'interval' => 'month', // @TODO
            'frequency' => 1, // @TODO
            'trial_period_days' => null, // @TODO
            'expiry_count' => 12, // @TODO

            // 'billing_cycle_anchor' => $this->billingCycleAnchor,
            // 'coupon' => $this->coupon,
            // 'metadata' => $this->metadata,
            // 'plan' => $this->plan,
            // 'quantity' => $this->quantity,
            // 'tax_percent' => $this->getTaxPercentageForPayload(),
            // 'trial_end' => $this->getTrialEndForPayload(),
        ]);

        $conekta_plan = \Laravel\Cashier\Plan::createAsConektaPlan(
            $id,
            $attributes
        );
        
        return $conekta_plan;
    }

    protected function getTestToken()
    {
        return 'tok_test_visa_4242';
    }

    public function setApiKey()
    {
        $apiEnvKey = getenv('CONEKTA_SECRET');

        if (!$apiEnvKey) {
            $apiEnvKey = 'key_ZLy4aP2szht1HqzkCezDEA';
        }
        
        Conekta::setApiKey($apiEnvKey);
    }

    public function setApiVersion($version)
    {
        Conekta::setApiVersion($version);
    }

    /**
     * Schema Helpers.
     */
    protected function schema()
    {
        return $this->connection()->getSchemaBuilder();
    }

    protected function connection()
    {
        return Eloquent::getConnectionResolver()->connection();
    }
}
