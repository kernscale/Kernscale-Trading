<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_member_uploads_a_reencoded_square_avatar_visible_to_members_only(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('avatar.update'), ['avatar' => UploadedFile::fake()->image('me.png', 400, 300)])
            ->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

        $user->refresh();
        Storage::disk('local')->assertExists($user->avatar_path);
        [$width, $height, $type] = getimagesizefromstring(Storage::disk('local')->get($user->avatar_path));
        $this->assertSame([256, 256, IMAGETYPE_WEBP], [$width, $height, $type]);
        $this->assertArrayNotHasKey('avatar_path', $user->toArray());
        $this->assertStringStartsWith('/avatars/'.$user->id.'?v=', $user->avatar);

        $viewer = User::factory()->withTwoFactor()->create();
        $viewer->passkeys()->create(['name' => 'key', 'credential_id' => 'avatar-key', 'credential' => []]);
        $this->actingAs($viewer)->withSession(['security.totp_user' => $viewer->id])->get($user->avatar)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->actingAs(User::factory()->create(['membership_status' => 'pending']))->get($user->avatar)->assertForbidden();
        auth()->logout();
        $this->get($user->avatar)->assertRedirect(route('login'));
    }

    public function test_replacing_and_removing_cleans_up_stored_files(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('avatar.update'), ['avatar' => UploadedFile::fake()->image('a.jpg', 200, 200)]);
        $first = $user->refresh()->avatar_path;
        $this->actingAs($user)->post(route('avatar.update'), ['avatar' => UploadedFile::fake()->image('b.webp', 200, 200)]);
        Storage::disk('local')->assertMissing($first);

        $this->actingAs($user)->delete(route('avatar.destroy'))->assertRedirect(route('profile.edit'));
        $this->assertNull($user->refresh()->avatar_path);
        $this->assertCount(0, Storage::disk('local')->allFiles('avatars'));
    }

    public function test_rejects_non_images_svg_and_tiny_images(): void
    {
        $user = User::factory()->create();
        foreach ([
            UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->image('tiny.png', 20, 20),
        ] as $file) {
            $this->actingAs($user)->post(route('avatar.update'), ['avatar' => $file])->assertSessionHasErrors('avatar');
        }
        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_approved_but_unenrolled_member_cannot_read_another_members_avatar(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('avatar.update'), ['avatar' => UploadedFile::fake()->image('avatar.png', 200, 200)])->assertRedirect();
        $viewer = User::factory()->create();
        $this->actingAs($viewer)->getJson($user->refresh()->avatar)->assertForbidden();
    }
}
