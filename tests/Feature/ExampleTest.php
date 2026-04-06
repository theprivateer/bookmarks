<?php

use App\Models\User;

test('the application returns a successful response', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertStatus(200);
});
