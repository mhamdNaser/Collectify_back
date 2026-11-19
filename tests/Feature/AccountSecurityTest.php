<?php

use App\Modules\User\Mail\VerificationCodeMail;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

// The full migration set is MySQL-only, so load just the tables this flow touches.
beforeEach(function () {
    $m = fn ($module, $file) => base_path("app/Modules/{$module}/database/migrations/{$file}.php");
    Artisan::call('migrate', ['--realpath' => true, '--path' => [
        $m('Locale', '0001_01_01_000003_create_countries_table'),
        $m('Locale', '0001_01_01_000004_create_states_table'),
        $m('Locale', '0001_01_01_000005_create_cities_table'),
        $m('User', '0001_01_01_000006_create_users_table'),
        $m('User', '2026_09_29_120000_allow_self_registration_on_users_table'),
        $m('User', '2025_10_04_075133_create_personal_access_tokens_table'),
        $m('User', '2025_10_11_123012_create_permission_tables'),
        $m('Billing', '2026_02_26_091148_create_plans_table'),
        $m('Stores', '2026_02_26_091149_create_stores_table'),
    ]]);
    Role::findOrCreate('partner', 'web');
    Role::findOrCreate('admin', 'web');
    Mail::fake();

    $make = function (string $email, string $role) {
        $user = User::create([
            'first_name' => 'Test', 'last_name' => $role, 'name' => $role,
            'email' => $email, 'password' => 'old-password', 'status' => 1,
        ]);
        $user->assignRole($role);

        return $user;
    };

    $this->admin = $make('admin@test.com', 'admin');
    $this->owner = $make('owner@test.com', 'partner');
});

/** Sends a code as the owner and returns it from the faked mail. */
function requestCode($test, array $payload): string
{
    $test->actingAs($test->owner)->postJson('/api/admin/account/send-code', $payload)->assertOk();

    $code = null;
    Mail::assertSent(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return $code;
}

it('lets the super admin change the password without the old one or a code', function () {
    $this->actingAs($this->admin)->postJson('/api/admin/password/change', [
        'new_password' => 'new-password-1',
        'new_password_confirmation' => 'new-password-1',
    ])->assertOk();

    expect(Hash::check('new-password-1', $this->admin->fresh()->password))->toBeTrue();
});

it('requires an emailed code for the store owner to change the password', function () {
    $payload = ['new_password' => 'new-password-1', 'new_password_confirmation' => 'new-password-1'];

    $this->actingAs($this->owner)->postJson('/api/admin/password/change', $payload)
        ->assertStatus(422)->assertJsonValidationErrors('code');

    $code = requestCode($this, ['purpose' => 'password']);
    Mail::assertSent(VerificationCodeMail::class, fn ($mail) => $mail->hasTo('owner@test.com'));

    $this->actingAs($this->owner)->postJson('/api/admin/password/change', $payload + ['code' => $code])->assertOk();
    expect(Hash::check('new-password-1', $this->owner->fresh()->password))->toBeTrue();

    // A code works only once.
    $this->actingAs($this->owner)->postJson('/api/admin/password/change', $payload + ['code' => $code])
        ->assertStatus(422)->assertJsonValidationErrors('code');
});

it('invalidates the code after 5 wrong attempts', function () {
    $code = requestCode($this, ['purpose' => 'password']);
    $wrong = $code === '111111' ? '222222' : '111111';
    $payload = ['new_password' => 'new-password-1', 'new_password_confirmation' => 'new-password-1'];

    foreach (range(1, 5) as $_) {
        $this->actingAs($this->owner)->postJson('/api/admin/password/change', $payload + ['code' => $wrong])->assertStatus(422);
    }

    $this->actingAs($this->owner)->postJson('/api/admin/password/change', $payload + ['code' => $code])
        ->assertStatus(422)->assertJsonValidationErrors('code');
    expect(Hash::check('old-password', $this->owner->fresh()->password))->toBeTrue();
});

it('changes the store owner email only with the code sent to the new address', function () {
    $code = requestCode($this, ['purpose' => 'email', 'email' => 'New@Test.com']);
    Mail::assertSent(VerificationCodeMail::class, fn ($mail) => $mail->hasTo('new@test.com'));

    // The code is tied to the address it was sent to.
    $this->actingAs($this->owner)->postJson('/api/admin/account/email', ['email' => 'other@test.com', 'code' => $code])
        ->assertStatus(422)->assertJsonValidationErrors('email');

    $code = requestCode($this, ['purpose' => 'email', 'email' => 'new@test.com']);
    $this->actingAs($this->owner)->postJson('/api/admin/account/email', ['email' => 'new@test.com', 'code' => $code])
        ->assertOk()->assertJsonPath('data.email', 'new@test.com');

    expect($this->owner->fresh()->email)->toBe('new@test.com');
});

it('refuses an email that another account already uses', function () {
    $this->actingAs($this->owner)->postJson('/api/admin/account/send-code', ['purpose' => 'email', 'email' => 'admin@test.com'])
        ->assertStatus(422)->assertJsonValidationErrors('email');

    Mail::assertNothingSent();
});

it('lets the super admin change their own email without a code', function () {
    $this->actingAs($this->admin)->postJson('/api/admin/account/email', ['email' => 'boss@test.com'])->assertOk();

    expect($this->admin->fresh()->email)->toBe('boss@test.com');
});

it('lets the super admin change a store owner password and email directly', function () {
    $this->actingAs($this->admin)->postJson("/api/admin/users/{$this->owner->id}", [
        'email' => 'owner2@test.com',
        'password' => 'set-by-admin-1',
    ])->assertSuccessful();

    $owner = $this->owner->fresh();
    expect($owner->email)->toBe('owner2@test.com')
        ->and(Hash::check('set-by-admin-1', $owner->password))->toBeTrue();
    Mail::assertNothingSent();

    // This endpoint reports validation errors under "data".
    $this->actingAs($this->admin)->postJson("/api/admin/users/{$this->owner->id}", ['email' => 'admin@test.com'])
        ->assertStatus(422)->assertJsonValidationErrors('email', 'data');
});

it('emails the forgot-password code and rejects it after 15 minutes', function () {
    $this->postJson('/api/admin/password/forgot', ['email' => 'owner@test.com'])->assertOk();

    $code = null;
    Mail::assertSent(VerificationCodeMail::class, function ($mail) use (&$code) {
        $code = $mail->code;

        return $mail->hasTo('owner@test.com');
    });

    DB::table('password_reset_tokens')->update(['created_at' => now()->subMinutes(16)]);

    $this->postJson('/api/admin/password/reset-with-code', [
        'email' => 'owner@test.com',
        'code' => $code,
        'new_password' => 'new-password-1',
        'new_password_confirmation' => 'new-password-1',
    ])->assertStatus(422)->assertJsonPath('message', 'Verification code expired.');
});
