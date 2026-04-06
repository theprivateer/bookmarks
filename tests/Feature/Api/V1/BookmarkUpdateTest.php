<?php

use App\Models\Bookmark;
use App\Models\User;

test('can archive a bookmark', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->patchJson("/api/v1/bookmarks/{$bookmark->id}", ['archived' => true])
        ->assertOk()
        ->assertJsonPath('data.deleted_at', fn ($value) => $value !== null);

    expect(Bookmark::withTrashed()->find($bookmark->id)->trashed())->toBeTrue();
});

test('can restore an archived bookmark', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();
    $bookmark->delete();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->patchJson("/api/v1/bookmarks/{$bookmark->id}", ['archived' => false])
        ->assertOk()
        ->assertJsonPath('data.deleted_at', null);

    expect(Bookmark::find($bookmark->id))->not->toBeNull();
});

test('cannot archive another users bookmark', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $bookmark = Bookmark::factory()->for($other)->processed()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->patchJson("/api/v1/bookmarks/{$bookmark->id}", ['archived' => true])
        ->assertNotFound();
});

test('archived field is required', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->patchJson("/api/v1/bookmarks/{$bookmark->id}", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('archived');
});

test('archived field must be boolean', function () {
    $user = User::factory()->create();
    $bookmark = Bookmark::factory()->for($user)->processed()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->patchJson("/api/v1/bookmarks/{$bookmark->id}", ['archived' => 'yes'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('archived');
});
