<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\MobileNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

class CreateSuperAdminCommand extends Command
{
    protected $signature = 'retail360:create-super-admin {mobile} {pin}';

    protected $description = 'Create a platform super admin user (mobile + 6-digit PIN)';

    public function handle(): int
    {
        $mobile = MobileNormalizer::normalize($this->argument('mobile'));
        $pin = $this->argument('pin');

        $validator = Validator::make(
            ['mobile' => $mobile, 'pin' => $pin],
            [
                'mobile' => ['required', 'regex:/^8801\d{9}$/'],
                'pin' => ['required', 'digits:6'],
            ],
            [
                'mobile.regex' => 'Mobile must be normalized as 8801XXXXXXXXX (Bangladesh).',
                'pin.digits' => 'PIN must be exactly 6 digits.',
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::query()->updateOrCreate(
            ['mobile' => $mobile],
            [
                'name' => 'Platform Admin',
                'pin_hash' => $pin,
                'is_platform_admin' => true,
                'tenant_id' => null,
            ]
        );

        Role::findOrCreate('super_admin', 'web');
        $user->syncRoles(['super_admin']);

        $this->info(sprintf(
            'Super admin %s (id: %d, mobile: %s).',
            $user->wasRecentlyCreated ? 'created' : 'updated',
            $user->id,
            $user->mobile
        ));

        return self::SUCCESS;
    }
}
