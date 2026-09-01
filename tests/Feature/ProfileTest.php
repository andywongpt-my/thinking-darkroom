<?php

namespace Tests\Feature;

use App\Domain\Domain;
use App\Models\Photo;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_user_can_delete_their_account_with_owned_media_and_created_proposals(): void
    {
        $user = User::factory()->create();
        $ownedProject = Project::factory()->create(['owner_id' => $user->id]);
        $ownedProject->members()->attach($user->id, ['role' => Domain::ROLE_OWNER]);
        $photo = Photo::factory()->create(['project_id' => $ownedProject->id]);

        $otherOwner = User::factory()->create();
        $sharedProject = Project::factory()->create(['owner_id' => $otherOwner->id]);
        $sharedProject->members()->attach($otherOwner->id, ['role' => Domain::ROLE_OWNER]);
        $sharedProject->members()->attach($user->id, ['role' => Domain::ROLE_PHOTOGRAPHER]);
        $proposal = Proposal::factory()->create([
            'project_id' => $sharedProject->id,
            'created_by' => $user->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->delete('/profile', ['password' => 'password']);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
        $this->assertDatabaseMissing('projects', ['id' => $ownedProject->id]);
        $this->assertDatabaseMissing('photos', ['id' => $photo->id]);
        $this->assertDatabaseHas('projects', ['id' => $sharedProject->id]);
        $this->assertDatabaseHas('proposals', [
            'id' => $proposal->id,
            'project_id' => $sharedProject->id,
            'created_by' => null,
        ]);
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
}
