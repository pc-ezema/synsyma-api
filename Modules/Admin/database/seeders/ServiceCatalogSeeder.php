<?php

namespace Modules\Admin\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Admin\Models\Service;
use Modules\Admin\Models\ServiceCategory;

/**
 * Loads the starting services and prices. Safe to run again: it updates by slug and never deletes.
 *   php artisan module:seed Admin --class=ServiceCatalogSeeder
 * After the first run, manage everything from the admin dashboard.
 */
class ServiceCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'cleaning' => ['Cleaning', 'cleaning', 'Home, office and vehicle cleaning by trained professionals.'],
            'repairs' => ['Repairs', 'repairs', 'Certified professionals for repairs and maintenance.'],
            'outdoors' => ['Outdoors', 'outdoors', 'Lawn care, moving and other outdoor jobs.'],
        ];

        $ids = [];
        $order = 1;
        foreach ($categories as $key => [$name, $icon, $description]) {
            $category = ServiceCategory::withTrashed()->firstOrNew(['slug' => Str::slug($name)]);
            $category->fill(['name' => $name, 'icon' => $icon, 'description' => $description, 'is_active' => true]);
            if (! $category->exists) {
                $category->sort_order = $order;
            }
            $category->save();
            $ids[$key] = $category->id;
            $order++;
        }

        // [category, name, tier, description, price_type, base_price, included_hours, additional_hour_price, team_size, parts_tbd]
        $services = [
            ['cleaning', 'Airbnb Cleaning', 'premium', 'Book a team of 2 professional cleaners to get your investment ready for the next client.', 'hourly', 200, 2, null, 2, false],
            ['cleaning', 'Airbnb Cleaning', 'regular', 'Keep your Airbnb guest-ready with a professional cleaning between every stay. A 1-man job only.', 'hourly', 138, 2, null, 1, false],
            ['cleaning', 'Car Detailing', 'premium', 'Give your vehicle the care it deserves with our team of 2 professional detailers, both interior and exterior.', 'hourly', 200, 2, 85, 2, false],
            ['cleaning', 'Deep Cleaning', 'premium', 'Book a team of 2 professional cleaners to tackle tough stains.', 'hourly', 220, 2, 110, 2, false],
            ['cleaning', 'General Cleaning', 'regular', 'Book a professional Synsyma for a one-man job, general cleaning.', 'hourly', 155, 2, 75, 1, false],
            ['cleaning', 'Office Cleaning', 'premium', 'Book a team of 2 professional cleaners for your office cleaning, corporate and commercial.', 'quote', null, null, null, 2, false],
            ['cleaning', 'Duct Cleaning', 'premium', 'Improve the ventilation system with our professional duct cleaners. We help remove accumulated dust and debris from your ductwork to support cleaner airflow throughout your home.', 'hourly', 450, 2, 110, null, false],

            ['repairs', 'Plumbing', 'premium', 'You can count on our reliable, certified plumbers. From leaking faucets to clogged drains and everyday plumbing issues, we help keep your home\'s water system running smoothly.', 'hourly', 215, 2, null, null, false],
            ['repairs', 'Electrical', null, 'Keep your home or business powered safely with our professional electricians. Whether you need troubleshooting, fixture installations or repairs, we help get things working as they should.', 'fixed', 255, null, null, null, true],
            ['repairs', 'Pest Control', null, 'Protect your home and business from unwanted pests with effective pest control solutions. We help address pest problems and identify ways to prevent future infestations. Book one of our certified pest control agents today.', 'fixed', 258, null, null, null, false],
            ['repairs', 'Heating & Cooling', null, 'Stay comfortable every season with our dependable, certified HVAC professionals. From troubleshooting and maintenance to system repairs, we keep your home comfortable year-round.', 'fixed', 220, null, null, null, true],
            ['repairs', 'Handyman Services', null, 'Take care of your home maintenance to-do list with our versatile handyman. From minor repairs and installations to general fixes around your house, we make home upkeep simple and stress-free.', 'hourly', 165, 2, 85, null, true],

            ['outdoors', 'Lawn Maintenance', 'premium', 'Keep your outdoor space looking neat and inviting with Synsyma lawn maintenance services. A 2-man professional job.', 'hourly', 190, 2, null, 2, false],
            ['outdoors', 'Lawn Maintenance', 'regular', 'For regular lawn mowing, trimming and seasonal upkeep. A 1-man professional job only.', 'hourly', 138, 2, null, 1, false],
            ['outdoors', 'Moving', null, 'Need to move a single room, basement or a few bulky items? Our convenient small-move service makes transporting your belongings easier, faster and less stressful.', 'hourly', 129, 2, 50, null, false],
        ];

        $sort = [];
        foreach ($services as [$cat, $name, $tier, $description, $type, $price, $hours, $extra, $team, $parts]) {
            $slug = Str::slug(trim($name . ' ' . ($tier ?? '')));
            $sort[$cat] = ($sort[$cat] ?? 0) + 1;

            $service = Service::withTrashed()->firstOrNew(['slug' => $slug]);
            $service->fill([
                'category_id' => $ids[$cat],
                'name' => $name,
                'tier' => $tier,
                'description' => $description,
                'price_type' => $type,
                'currency' => 'USD',
                'base_price' => $price,
                'included_hours' => $hours,
                'additional_hour_price' => $extra,
                'team_size' => $team,
                'parts_tbd' => $parts,
                'home_protection' => true,
                'is_active' => true,
            ]);
            if (! $service->exists) {
                $service->sort_order = $sort[$cat];
            }
            $service->save();
        }
    }
}
