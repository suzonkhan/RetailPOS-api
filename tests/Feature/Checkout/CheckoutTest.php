<?php

namespace Tests\Feature\Checkout;

use App\Models\Coupon;
use App\Models\Tenant;
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

        $this->postJson('/api/v1/checkout/quote', [
            'plan_slug' => 'startup-plus',
            'billing_cycle' => 'yearly',
        ])
            ->assertOk()
            ->assertJsonPath('subtotal', 588)
            ->assertJsonPath('discount_amount', 0)
            ->assertJsonPath('total', 588)
            ->assertJsonPath('currency', 'BDT')
            ->assertJsonPath('plan.slug', 'startup-plus');
    }

    public function test_checkout_quote_with_percent_coupon(): void
    {
        $owner = $this->registerOwner();

        Coupon::query()->create([
            'code' => 'SAVE10',
            'type' => Coupon::TYPE_PERCENT,
            'value' => 10,
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/checkout/quote', [
            'plan_slug' => 'startup',
            'billing_cycle' => 'monthly',
            'coupon_code' => 'save10',
        ])
            ->assertOk()
            ->assertJsonPath('subtotal', 20)
            ->assertJsonPath('discount_amount', 2)
            ->assertJsonPath('total', 18)
            ->assertJsonPath('coupon.code', 'SAVE10');
    }

    public function test_mock_payment_flow_activates_subscription(): void
    {
        $owner = $this->registerOwner();
        $tenant = $owner->tenant;

        $tenant->update([
            'trial_ends_at' => now()->subDay(),
            'status' => Tenant::STATUS_EXPIRED,
        ]);

        Sanctum::actingAs($owner);

        $create = $this->postJson('/api/v1/checkout/bkash/create', [
            'plan_slug' => 'startup',
            'billing_cycle' => 'monthly',
        ])->assertOk()
            ->assertJsonPath('gateway', 'mock')
            ->assertJsonStructure(['bkashURL', 'payment_id', 'invoice_id']);

        $paymentId = $create->json('payment_id');

        $this->postJson('/api/v1/checkout/bkash/execute', [
            'payment_id' => $paymentId,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('subscription.status', Tenant::STATUS_ACTIVE);

        $tenant->refresh();

        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);
        $this->assertNotNull($tenant->subscribed_at);
        $this->assertNotNull($tenant->current_period_ends_at);
        $this->assertTrue($tenant->current_period_ends_at->isFuture());
        $this->assertSame('monthly', $tenant->billing_cycle);
    }

    public function test_expired_trial_returns_402_on_protected_route(): void
    {
        $owner = $this->registerOwner();
        $owner->tenant->update([
            'trial_ends_at' => now()->subDay(),
            'status' => Tenant::STATUS_EXPIRED,
        ]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/reports/sales-summary')
            ->assertStatus(402)
            ->assertJsonPath('trial_ended', true)
            ->assertJsonPath('subscribe_url', '/subscription/pay');

        $this->getJson('/api/v1/tenant/subscription')->assertOk();
        $this->postJson('/api/v1/checkout/quote', [
            'plan_slug' => 'startup',
            'billing_cycle' => 'monthly',
        ])->assertOk();
    }

    public function test_webhook_idempotency(): void
    {
        $owner = $this->registerOwner();

        Sanctum::actingAs($owner);

        $create = $this->postJson('/api/v1/checkout/bkash/create', [
            'plan_slug' => 'startup',
            'billing_cycle' => 'monthly',
        ])->assertOk();

        $paymentId = $create->json('payment_id');

        $payload = [
            'paymentID' => $paymentId,
            'transactionStatus' => 'Completed',
            'statusCode' => '0000',
        ];

        $this->postJson('/api/v1/bkash/webhook', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $owner->tenant->refresh();
        $this->assertSame(Tenant::STATUS_ACTIVE, $owner->tenant->status);

        $this->postJson('/api/v1/bkash/webhook', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'already_processed');
    }

    public function test_cashier_cannot_access_checkout(): void
    {
        $owner = $this->registerOwner('8801712345700');

        $owner->tenant->update([
            'plan_id' => \App\Models\Plan::query()->where('slug', 'startup-plus')->value('id'),
        ]);

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Cashier',
            'mobile' => '8801712345701',
            'pin' => '654321',
            'role' => 'cashier',
        ])->assertCreated();

        $cashier = User::query()->where('mobile', '8801712345701')->firstOrFail();

        Sanctum::actingAs($cashier);

        $this->postJson('/api/v1/checkout/quote', [
            'plan_slug' => 'startup',
            'billing_cycle' => 'monthly',
        ])->assertForbidden();
    }

    private function registerOwner(string $mobile = '8801712345699'): User
    {
        $this->postJson('/api/v1/auth/register', [
            'shop_name' => 'Checkout Shop',
            'owner_name' => 'Owner',
            'mobile' => $mobile,
            'pin' => '123456',
        ])->assertCreated();

        return User::query()->where('mobile', $mobile)->firstOrFail();
    }
}
