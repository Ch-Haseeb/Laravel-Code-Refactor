<?php

namespace  App\Tests;

use Tests\TestCase;
use App\Models\User;
use App\Models\Town;
use App\Models\Type;
use App\Models\Company;
use App\Models\UserMeta;
use App\Models\Department;
use App\Models\UserTowns;
use App\Models\UserLanguages;
use App\Models\UsersBlacklist;
use App\Repository\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UserRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private UserRepository $userRepository;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->userRepository = new UserRepository();
        
        putenv('CUSTOMER_ROLE_ID=2');
        putenv('TRANSLATOR_ROLE_ID=3');
    }

   
    public function test_creates_new_customer_with_complete_meta_data()
    {
      
        $request = [
            'role' => env('CUSTOMER_ROLE_ID'),
            'name' => 'John Customer',
            'company_id' => '',
            'department_id' => '',
            'email' => 'customer@test.com',
            'dob_or_orgid' => '1990-01-01',
            'phone' => '1234567890',
            'mobile' => '0987654321',
            'password' => 'haseeb123',
            'consumer_type' => 'paid',
            'customer_type' => 'business',
            'username' => 'johncustomer',
            'post_code' => '12345',
            'address' => '123 Test St',
            'city' => 'Test City',
            'town' => 'Test Town',
            'country' => 'Test Country',
            'reference' => 'yes',
            'additional_info' => 'Test info',
            'cost_place' => 'Test Place',
            'fee' => '100',
            'time_to_charge' => '2',
            'time_to_pay' => '30',
            'charge_ob' => '1',
            'customer_id' => 'CUST123',
            'charge_km' => '10',
            'maximum_km' => '100',
            'status' => '1'
        ];

        Type::create(['code' => 'paid', 'name' => 'Paid']);

        
        $user = $this->userRepository->createOrUpdate(null, $request);

        
        $this->assertDatabaseHas('users', [
            'email' => 'customer@test.com',
            'name' => 'John Customer'
        ]);

        $this->assertDatabaseHas('user_metas', [
            'user_id' => $user->id,
            'consumer_type' => 'paid',
            'customer_type' => 'business',
            'username' => 'johncustomer',
            'post_code' => '12345',
            'fee' => '100',
            'charge_km' => '10',
            'maximum_km' => '100'
        ]);

        $this->assertDatabaseHas('companies', [
            'name' => 'John Customer'
        ]);

        $this->assertDatabaseHas('departments', [
            'name' => 'John Customer'
        ]);
    }

 
    public function test_creates_translator_with_complete_profile()
    {
      
        $request = [
            'role' => env('TRANSLATOR_ROLE_ID'),
            'name' => 'Jane Translator',
            'email' => 'translator@test.com',
            'password' => 'haseeb123',
            'dob_or_orgid' => '1990-01-01',
            'phone' => '1234567890',
            'mobile' => '0987654321',
            'translator_type' => 'professional',
            'worked_for' => 'yes',
            'organization_number' => 'ORG123',
            'gender' => 'female',
            'translator_level' => 'certified',
            'additional_info' => 'Translator info',
            'post_code' => '12345',
            'address' => '123 Translator St',
            'address_2' => 'Suite 100',
            'town' => 'Translator Town',
            'user_language' => [1, 2],
            'status' => '1'
        ];

       
        $user = $this->userRepository->createOrUpdate(null, $request);

        $this->assertDatabaseHas('users', [
            'email' => 'translator@test.com',
            'name' => 'Jane Translator'
        ]);

        $this->assertDatabaseHas('user_metas', [
            'user_id' => $user->id,
            'translator_type' => 'professional',
            'worked_for' => 'yes',
            'organization_number' => 'ORG123',
            'gender' => 'female',
            'translator_level' => 'certified'
        ]);

        foreach ($request['user_language'] as $langId) {
            $this->assertDatabaseHas('user_languages', [
                'user_id' => $user->id,
                'lang_id' => $langId
            ]);
        }
    }

    
    public function test_updates_customer_blacklist()
    {
   
        $user = User::factory()->create();
        $translator1 = User::factory()->create();
        $translator2 = User::factory()->create();

        $request = [
            'role' => env('CUSTOMER_ROLE_ID'),
            'name' => $user->name,
            'email' => $user->email,
            'translator_ex' => [$translator1->id, $translator2->id],
            'consumer_type' => 'paid',
            'status' => '1'
        ];

      
        $this->userRepository->createOrUpdate($user->id, $request);

      
        $this->assertDatabaseHas('users_blacklist', [
            'user_id' => $user->id,
            'translator_id' => $translator1->id
        ]);
        $this->assertDatabaseHas('users_blacklist', [
            'user_id' => $user->id,
            'translator_id' => $translator2->id
        ]);
    }


    public function test_handles_town_management()
    {
       
        $user = User::factory()->create();
        $existingTown = Town::create(['townname' => 'Existing Town']);
        
        $request = [
            'role' => env('TRANSLATOR_ROLE_ID'),
            'name' => $user->name,
            'email' => $user->email,
            'new_towns' => 'New Test Town',
            'user_towns_projects' => [$existingTown->id],
            'status' => '1'
        ];

       
        $this->userRepository->createOrUpdate($user->id, $request);

     
        $this->assertDatabaseHas('towns', ['townname' => 'New Test Town']);
        $this->assertDatabaseHas('user_towns', [
            'user_id' => $user->id,
            'town_id' => $existingTown->id
        ]);
    }

  
    public function test_handles_null_values_properly()
    {
     
        $request = [
            'role' => env('CUSTOMER_ROLE_ID'),
            'name' => 'Test User',
            'email' => 'test@test.com',
            'company_id' => '',
            'department_id' => '',
            'dob_or_orgid' => '',
            'phone' => '',
            'mobile' => '',
            'consumer_type' => 'paid',
            'status' => '1'
        ];

        $user = $this->userRepository->createOrUpdate(null, $request);

      
        $this->assertDatabaseHas('users', [
            'email' => 'test@test.com',
            'company_id' => 0,
            'department_id' => 0
        ]);
    }


    public function test_handles_status_changes_properly()
    {
    
        $user = User::factory()->create(['status' => '0']);
        
        $request = [
            'role' => env('TRANSLATOR_ROLE_ID'),
            'name' => $user->name,
            'email' => $user->email,
            'status' => '1'
        ];

        $updatedUser = $this->userRepository->createOrUpdate($user->id, $request);

       
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => '1'
        ]);
    }

    
    public function test_properly_updates_existing_user()
    {
        
        $user = User::factory()->create([
            'name' => 'Original Name',
            'email' => 'original@test.com',
            'status' => '1'
        ]);

        $request = [
            'role' => env('TRANSLATOR_ROLE_ID'),
            'name' => 'Updated Name',
            'email' => 'updated@test.com',
            'password' => 'haseeb123',
            'status' => '0'
        ];

   
        $updatedUser = $this->userRepository->createOrUpdate($user->id, $request);

     
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'email' => 'updated@test.com'
        ]);
        
        $this->assertTrue(Hash::check('haseeb123', $updatedUser->password));
    }
}
