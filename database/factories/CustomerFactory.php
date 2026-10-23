<?php

namespace Database\Factories;

use App\Modules\Crm\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Date complet fictive pentru teste și demonstrații.
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        $email = 'client'.fake()->unique()->numberBetween(1000, 999999).'@example.test';

        return [
            'type' => 'person',
            'first_name' => fake('ro_RO')->firstName(),
            'last_name' => fake('ro_RO')->lastName(),
            'email' => $email,
            'email_normalized' => $email,
            'phone' => null,
            'phone_normalized' => null,
            'locale' => 'ro',
            'source' => 'web',
        ];
    }
}
