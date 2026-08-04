<?php

namespace Tests\Feature\Platform;

use App\Models\BkashPayment;
use App\Models\Plan;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlatformTenantBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PlanSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    public function test_super_admin_lists_tenant_billing_history(): void
    {
        $owner = $this->registerOwner('8801712345701');
        $store = $this->defaultStore($owner);
        $plan = Plan::query()->where('slug', 'startup')->firstOrFail();
        $admin = $this->createSuperAdmin('8801711111201');

        $paidInvoice = $this->createInvoice($owner, $store, $plan, SubscriptionInvoice::STATUS_PAID);
        $this->createPayment($paidInvoice, BkashPayment::STATUS_COMPLETED);

        $failedInvoice = $this->createInvoice($owner, $store, $plan, SubscriptionInvoice::STATUS_PENDING);
        $this->createPayment($failedInvoice, BkashPayment::STATUS_FAILED);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/platform/tenants/'.$owner->tenant_id.'/billing');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame($failedInvoice->id, $response->json('data.0.id'));
        $this->assertTrue($response->json('data.0.can_approve'));
        $this->assertFalse($response->json('data.1.can_approve'));
        $this->assertSame('failed', $response->json('data.0.payments.0.status'));
        $this->assertSame('completed', $response->json('data.1.payments.0.status'));
    }

    public function test_super_admin_can_filter_failed_payment_billing(): void
    {
        $owner = $this->registerOwner('8801712345702');
        $store = $this->defaultStore($owner);
        $plan = Plan::query()->where('slug', 'startup')->firstOrFail();
        $admin = $this->createSuperAdmin('8801711111202');

        $paidInvoice = $this->createInvoice($owner, $store, $plan, SubscriptionInvoice::STATUS_PAID);
        $this->createPayment($paidInvoice, BkashPayment::STATUS_COMPLETED);

        $failedInvoice = $this->createInvoice($owner, $store, $plan, SubscriptionInvoice::STATUS_PENDING);
        $this->createPayment($failedInvoice, BkashPayment::STATUS_FAILED);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/platform/tenants/'.$owner->tenant_id.'/billing?payment_status=failed');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($failedInvoice->id, $response->json('data.0.id'));
    }

    public function test_super_admin_can_approve_failed_payment(): void
    {
        $owner = $this->registerOwner('8801712345703');
        $store = $this->expireDefaultBranch($owner);
        $plan = Plan::query()->where('slug', 'startup')->firstOrFail();
        $admin = $this->createSuperAdmin('8801711111203');

        $invoice = $this->createInvoice($owner, $store, $plan, SubscriptionInvoice::STATUS_PENDING);
        $this->createPayment($invoice, BkashPayment::STATUS_FAILED);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/platform/tenants/'.$owner->tenant_id.'/billing/'.$invoice->id.'/approve')
            ->assertOk()
            ->assertJsonPath('status', SubscriptionInvoice::STATUS_PAID)
            ->assertJsonPath('can_approve', false);

        $store->refresh();
        $this->assertSame(Store::STATUS_ACTIVE, $store->status);
        $this->assertNotNull($store->current_period_ends_at);
        $this->assertTrue($store->current_period_ends_at->isFuture());

        $this->assertDatabaseHas('subscription_invoices', [
            'id' => $invoice->id,
            'status' => SubscriptionInvoice::STATUS_PAID,
        ]);

        $this->assertDatabaseHas('bkash_payments', [
            'subscription_invoice_id' => $invoice->id,
            'status' => BkashPayment::STATUS_FAILED,
        ]);
    }

    public function test_cannot_approve_paid_invoice(): void
    {
        $owner = $this->registerOwner('8801712345704');
        $store = $this->defaultStore($owner);
        $plan = Plan::query()->where('slug', 'startup')->firstOrFail();
        $admin = $this->createSuperAdmin('8801711111204');

        $invoice = $this->createInvoice($owner, $store, $plan, SubscriptionInvoice::STATUS_PAID);
        $this->createPayment($invoice, BkashPayment::STATUS_COMPLETED);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/platform/tenants/'.$owner->tenant_id.'/billing/'.$invoice->id.'/approve')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invoice']);
    }

    public function test_tenant_owner_cannot_access_platform_billing(): void
    {
        $owner = $this->registerOwner('8801712345705');

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/platform/tenants/'.$owner->tenant_id.'/billing')
            ->assertForbidden();
    }

    private function createInvoice(User $owner, Store $store, Plan $plan, string $status): SubscriptionInvoice
    {
        return SubscriptionInvoice::query()->create([
            'tenant_id' => $owner->tenant_id,
            'store_id' => $store->id,
            'plan_id' => $plan->id,
            'intent' => SubscriptionInvoice::INTENT_RENEW,
            'billing_cycle' => Store::BILLING_MONTHLY,
            'subtotal' => 20,
            'discount_amount' => 0,
            'total_amount' => 20,
            'status' => $status,
            'paid_at' => $status === SubscriptionInvoice::STATUS_PAID ? now() : null,
        ]);
    }

    private function createPayment(SubscriptionInvoice $invoice, string $status): BkashPayment
    {
        return BkashPayment::query()->create([
            'subscription_invoice_id' => $invoice->id,
            'payment_id' => 'MOCK'.uniqid(),
            'amount' => $invoice->total_amount,
            'status' => $status,
            'completed_at' => $status === BkashPayment::STATUS_COMPLETED ? now() : null,
        ]);
    }

    private function createSuperAdmin(string $mobile): User
    {
        Role::findOrCreate('super_admin', 'web');

        $user = User::query()->create([
            'name' => 'Platform Admin',
            'mobile' => $mobile,
            'pin_hash' => '123456',
            'is_platform_admin' => true,
            'tenant_id' => null,
        ]);

        $user->syncRoles(['super_admin']);

        return $user;
    }
}
