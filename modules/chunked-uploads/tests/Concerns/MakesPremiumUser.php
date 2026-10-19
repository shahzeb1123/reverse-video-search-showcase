<?php

namespace Tests\Concerns;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Make a REAL premium user: an active subscription on a monthly plan.
 *
 * `isPremium()` reads the subscription — there is no boolean to flip — so the
 * test builds the real thing, and asserts it, because a silently-not-premium
 * user would make a premium test pass for the wrong reason.
 */
trait MakesPremiumUser
{
    protected function makeUserPremium(User $user): User
    {
        $plan = Plan::create([
            // NOT NULL uuid with no DB default and no model boot hook.
            'uuid'          => (string) Str::uuid(),
            'name'          => 'Test Monthly',
            'slug'          => 'test-monthly-' . Str::random(6),
            'billing_cycle' => 'monthly',
            'price'         => 9.99,
            'currency'      => 'USD',
            'search_quota'  => 500,
            'quota_period'  => 'monthly',
            'is_active'     => true,
        ]);

        Subscription::create([
            'uuid'      => (string) Str::uuid(),
            'user_id'   => $user->id,
            'plan_id'   => $plan->id,
            'status'    => 'active',
            'starts_at' => now()->subDay(),
            'ends_at'   => now()->addMonth(),
        ]);

        $user = $user->fresh();
        $this->assertTrue($user->isPremium(), 'test setup failed to make a premium user');

        return $user;
    }
}
