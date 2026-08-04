<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('plans', 'is_trial_default')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->boolean('is_trial_default')->default(false)->after('is_active');
            });
        }

        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'plan_id')) {
                $table->foreignId('plan_id')->nullable()->after('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            }
            if (! Schema::hasColumn('stores', 'status')) {
                $table->string('status')->default('trial')->after('address');
            }
            if (! Schema::hasColumn('stores', 'trial_ends_at')) {
                $table->timestamp('trial_ends_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('stores', 'subscribed_at')) {
                $table->timestamp('subscribed_at')->nullable()->after('trial_ends_at');
            }
            if (! Schema::hasColumn('stores', 'current_period_ends_at')) {
                $table->timestamp('current_period_ends_at')->nullable()->after('subscribed_at');
            }
            if (! Schema::hasColumn('stores', 'billing_cycle')) {
                $table->string('billing_cycle')->nullable()->after('current_period_ends_at');
            }
            if (! Schema::hasColumn('stores', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('billing_cycle');
            }
            if (! Schema::hasColumn('stores', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->after('is_default');
            }
            if (! Schema::hasColumn('stores', 'data_purge_scheduled_at')) {
                $table->timestamp('data_purge_scheduled_at')->nullable()->after('suspended_at');
            }
        });

        if (! Schema::hasColumn('users', 'default_store_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('default_store_id')->nullable()->after('tenant_id')->constrained('stores')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('store_user')) {
            Schema::create('store_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->boolean('is_primary')->default(true);
                $table->timestamps();

                $table->unique(['store_id', 'user_id']);
                $table->index('user_id');
            });
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscriptions', 'store_id')) {
                $table->foreignId('store_id')->nullable()->after('tenant_id')->constrained()->cascadeOnDelete();
                $table->index(['store_id', 'status']);
            }
        });

        Schema::table('subscription_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('subscription_invoices', 'store_id')) {
                $table->foreignId('store_id')->nullable()->after('tenant_id')->constrained()->cascadeOnDelete();
            }
            if (! Schema::hasColumn('subscription_invoices', 'intent')) {
                $table->string('intent')->default('renew')->after('store_id');
            }
            if (! Schema::hasColumn('subscription_invoices', 'branch_meta')) {
                $table->json('branch_meta')->nullable()->after('intent');
            }
        });

        $this->migrateTenantBillingToStores();

        $this->dropStoresTenantUniqueIfExists();

        DB::table('plans')->where('slug', 'startup')->update([
            'is_trial_default' => true,
            'max_users' => 2,
        ]);
    }

    public function down(): void
    {
        if ($this->storesTenantUniqueExists()) {
            Schema::table('stores', function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
                $table->dropIndex(['tenant_id']);
                $table->unique('tenant_id');
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            });
        }

        Schema::table('subscription_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('subscription_invoices', 'branch_meta')) {
                $table->dropColumn('branch_meta');
            }
            if (Schema::hasColumn('subscription_invoices', 'intent')) {
                $table->dropColumn('intent');
            }
            if (Schema::hasColumn('subscription_invoices', 'store_id')) {
                $table->dropConstrainedForeignId('store_id');
            }
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'store_id')) {
                $table->dropConstrainedForeignId('store_id');
            }
        });

        Schema::dropIfExists('store_user');

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'default_store_id')) {
                $table->dropConstrainedForeignId('default_store_id');
            }
        });

        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'plan_id')) {
                $table->dropConstrainedForeignId('plan_id');
            }
            foreach ([
                'status', 'trial_ends_at', 'subscribed_at', 'current_period_ends_at',
                'billing_cycle', 'is_default', 'suspended_at', 'data_purge_scheduled_at',
            ] as $column) {
                if (Schema::hasColumn('stores', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('plans', function (Blueprint $table) {
            if (Schema::hasColumn('plans', 'is_trial_default')) {
                $table->dropColumn('is_trial_default');
            }
        });
    }

    private function migrateTenantBillingToStores(): void
    {
        $tenants = DB::table('tenants')->get();

        foreach ($tenants as $tenant) {
            $store = DB::table('stores')->where('tenant_id', $tenant->id)->orderBy('id')->first();

            if ($store === null) {
                continue;
            }

            DB::table('stores')->where('id', $store->id)->update([
                'plan_id' => $store->plan_id ?? $tenant->plan_id,
                'status' => $store->status ?? $tenant->status,
                'trial_ends_at' => $store->trial_ends_at ?? $tenant->trial_ends_at,
                'subscribed_at' => $store->subscribed_at ?? $tenant->subscribed_at,
                'current_period_ends_at' => $store->current_period_ends_at ?? $tenant->current_period_ends_at,
                'billing_cycle' => $store->billing_cycle ?? $tenant->billing_cycle,
                'is_default' => true,
            ]);

            DB::table('subscriptions')
                ->where('tenant_id', $tenant->id)
                ->whereNull('store_id')
                ->update(['store_id' => $store->id]);

            DB::table('subscription_invoices')
                ->where('tenant_id', $tenant->id)
                ->whereNull('store_id')
                ->update(['store_id' => $store->id, 'intent' => 'renew']);

            $owner = DB::table('users')
                ->where('tenant_id', $tenant->id)
                ->where('is_platform_admin', false)
                ->orderBy('id')
                ->first();

            if ($owner !== null && empty($owner->default_store_id)) {
                DB::table('users')->where('id', $owner->id)->update([
                    'default_store_id' => $store->id,
                ]);
            }
        }
    }

    private function dropStoresTenantUniqueIfExists(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            try {
                Schema::table('stores', function (Blueprint $table) {
                    $table->dropUnique(['tenant_id']);
                });
            } catch (\Throwable) {
                // Index may already be dropped.
            }

            return;
        }

        if (! $this->storesTenantUniqueExists()) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropUnique(['tenant_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');
        });
    }

    private function storesTenantUniqueExists(): bool
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return false;
        }

        $connection = Schema::getConnection();
        $table = $connection->getTablePrefix().'stores';
        $indexes = $connection->select("SHOW INDEX FROM `{$table}` WHERE Key_name LIKE '%tenant_id%' AND Non_unique = 0");

        return count($indexes) > 0;
    }
};
