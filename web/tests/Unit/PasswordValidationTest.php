<?php

namespace Tests\Unit;

use App\Http\Requests\UserRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class PasswordValidationTest extends TestCase
{
    private function accepts(string $password): bool
    {
        $factory = new Factory(new Translator(new ArrayLoader, 'en'));
        $rules = (new UserRequest)->rules();

        // Validate only the password. No Laravel application or database is booted.
        return $factory->make(['password' => $password], ['password' => $rules['password']])->passes();
    }

    public function test_eight_character_password_and_requested_password_are_accepted(): void
    {
        $this->assertTrue($this->accepts('Abcdef12'));
        $this->assertTrue($this->accepts('Password1'));
    }

    public function test_password_shorter_than_eight_characters_is_rejected(): void
    {
        $this->assertFalse($this->accepts('Abcde12'));
        $this->assertFalse($this->accepts(''));
    }
}
