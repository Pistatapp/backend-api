<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Laravelcm\Subscriptions\Models\Plan;
use Laravelcm\Subscriptions\Models\Feature;

class PlanFeatureSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = file_get_contents(public_path('json/plan_features.json'));
        $plans = json_decode($plans, true);

        foreach ($plans as $planData) {
            $plan = Plan::create([
                'name' => __($planData['slug']),
                'slug' => $planData['slug'],
                'description' => 'description',
                'sort_order' => $planData['sort_order'],
                'is_active' => true,
                'price' => 0,
                'signup_fee' => 0,
                'currency' => 'IRR',
                'trial_period' => 0,
                'trial_interval' => 'day',
                'invoice_period' => 1,
                'invoice_interval' => 'month',
                'grace_period' => 0,
                'grace_interval' => 'day',
                'prorate_day' => 0,
                'prorate_period' => 0,
                'prorate_extend_due' => 0,
                'active_subscribers_limit' => 0,
            ]);

            foreach ($planData['features'] as $slug => $title) {
                Feature::create([
                    'plan_id' => $plan->id,
                    'name' => $title,
                    'slug' => $slug,
                    'description' => 'description',
                    'value' => 'unlimited',
                    'resettable_period' => 0,
                    'resettable_interval' => 'month',
                    'sort_order' => 0,
                ]);
            }
        }
    }
}
