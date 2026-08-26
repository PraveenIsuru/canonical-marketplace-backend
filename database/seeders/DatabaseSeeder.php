<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /*
     * WithoutModelEvents is deliberately not used here.
     *
     * Store visibility is maintained by model events on Attachment: creating one marks
     * the store live, deleting the last one returns it to dark. Muting events would
     * seed a catalogue in which every store is dark, no seller list returns anything,
     * and the frontend appears broken for reasons nothing in the code explains.
     */

    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test Buyer',
            'email' => 'test@example.com',
        ]);

        User::factory()->create([
            'name' => 'Test Administrator',
            'email' => 'admin@example.com',
            'is_admin' => true,
        ]);

        $this->call(CatalogueSeeder::class);
    }
}
