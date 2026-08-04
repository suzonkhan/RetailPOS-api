<?php

namespace Tests\Feature\Checkout;

use App\Models\Coupon;
use App\Models\Store;
use App\Models\User;
use App\Services\Bkash\MockBkashGateway;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MockBkashGateway::reset();

        $this->seed([
            PlanSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    public function test_checkout_quote_without_coupon(): void
    {
        $owner = $this->registerOwner();

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/checkout/quote', $this->renewCheckoutPayload($owner, 'startup-plus', 'yearly'))
            ->assertOk()
            ->assertJsonPath('subtotal', 588)
            ->assertJsonPath('discount_amount', 0)
            ->assertJsonPath('total', 588)
            ->assertJsonPath('currency', 'BDT')
            ->assertJsonPath('plan.slug', 'startup-plus');
    }

    public function test_checkout_quote_with_percent_coupon(): void
    {
        $owner = $this->registerOwner('8801712345698');

        Coupon::query()->create([
            'code' => 'SAVE10',
            'type' => Coupon::TYPE_PERCENT,
            'value' => 10,
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/checkout/quote', array_merge(
            $this->renewCheckoutPayload($owner, 'startup', 'monthly'),
            ['coupon_code' => 'save10']
        ))
            ->assertOk()
            ->assertJsonPath('subtotal', 20)
            ->assertJsonPath('discount_amount', 2)
            ->assertJsonPath('total', 18)
            ->assertJsonPath('coupon.code', 'SAVE10');
    }

    public function test_mock_payment_flow_activates_subscription(): void
    {
        $owner = $this->registerOwner('8801712345697');
        $store = $this->expireDefaultBranch($owner);

        Sanctum::actingAs($owner);

        $create = $this->postJson('/api/v1/checkout/bkash/create', $this->renewCheckoutPayload($owner))
            ->assertOk()
            ->assertJsonPath('gateway', 'mock')
            ->assertJsonStructure(['bkashURL', 'payment_id', 'invoice_id']);

        $paymentId = $create->json('payment_id');

        $this->postJson('/api/v1/checkout/bkash/execute', [
            'payment_id' => $paymentId,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('subscription.status', Store::STATUS_ACTIVE);

        $store->refresh();

        $this->assertSame(Store::STATUS_ACTIVE, $store->status);
        $this->assertNotNull($store->subscribed_at);
        $this->assertNotNull($store->current_period_ends_at);
        $this->assertTrue($store->current_period_ends_at->isFuture());
        $this->assertSame('monthly', $store->billing_cycle);
    }

    public function test_expired_trial_returns_402_on_protected_route(): void
    {
        $owner = $this->registerOwner('8801712345696');
        $this->expireDefaultBranch($owner);

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/reports/sales-summary')
            ->assertStatus(402)
            ->assertJsonPath('trial_ended', true);

        $this->getJson('/api/v1/tenant/subscription')->assertOk();
        $this->postJson('/api/v1/checkout/quote', $this->renewCheckoutPayload($owner))->assertOk();
    }

    public function test_webhook_idempotency(): void
    {
        $owner = $this->registerOwner('8801712345695');

        Sanctum::actingAs($owner);

        $create = $this->postJson('/api/v1/checkout/bkash/create', $this->renewCheckoutPayload($owner))
            ->assertOk();

        $paymentId = $create->json('payment_id');

        $payload = [
            'paymentID' => $paymentId,
            'transactionStatus' => 'Completed',
            'statusCode' => '0000',
        ];

        $this->postJson('/api/v1/bkash/webhook', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $store = $this->defaultStore($owner)->fresh();
        $this->assertSame(Store::STATUS_ACTIVE, $store->status);

        $this->postJson('/api/v1/bkash/webhook', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'already_processed');
    }

    public function test_cashier_cannot_access_checkout(): void
    {
        $owner = $this->registerOwner('8801712345700');
        $this->upgradeTenantPlan($owner, 'startup-plus');
        $cashier = $this->createBranchUser($owner, 'cashier', '8801712345701');

        Sanctum::actingAs($cashier);

        $this->postJson('/api/v1/checkout/quote', $this->renewCheckoutPayload($owner))
            ->assertForbidden();
    }
}
